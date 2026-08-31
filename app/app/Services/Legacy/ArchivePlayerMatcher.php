<?php

namespace App\Services\Legacy;

use App\Enums\Gender;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Legt de spelers van de drie generaties naast elkaar en maakt er één persoon per
 * mens van: comp_spelers (2009-2013) → intra_spelers (2013-2023) → players (nu).
 *
 * Exacte naamgelijkheid koppelt vanzelf. Voor de rest wordt een voorstel berekend op
 * naamgelijkenis; wat daar niet uit te halen valt — spelfouten, naamswijzigingen — is
 * eenmalig nagekeken en vastgelegd in database/legacy/player-map-overrides.php.
 */
class ArchivePlayerMatcher
{
    /** Vanaf deze gelijkenis (0-100) stellen we een koppeling met een huidige speler voor. */
    private const DREMPEL_VOORSTEL = 78.0;

    /**
     * Drempel voor het samenvoegen van een comp- en een intra-speler. Lager, omdat beide
     * kanten oude data zijn: die koppeling wordt sowieso ter controle gemarkeerd.
     */
    private const DREMPEL_COMP = 72.0;

    /** Kandidaten binnen deze afstand van de beste score maken de keuze ambigu. */
    private const AMBIGU_MARGE = 4.0;

    /** @var array<string, ArchivePerson> gesleuteld op genormaliseerde naam */
    private array $personen = [];

    /** @var array<int, object> huidige spelers, gesleuteld op id */
    private array $spelerPerId = [];

    /** @var array<int, string> */
    private array $sleutelPerCompId = [];

    /** @var array<int, string> */
    private array $sleutelPerIntraId = [];

    /** @var array{player_dubbels: array<int, int>, intra_naar_player: array<int, int>, comp_naar_intra: array<int, int>} */
    private array $overrides;

    /** @var list<string> */
    private array $waarschuwingen = [];

    private readonly ConnectionInterface $archive;

    public function __construct()
    {
        $this->archive = DB::connection('archive');
        $this->overrides = require database_path('legacy/player-map-overrides.php');
    }

    /**
     * @return list<ArchivePerson> één rij per mens, enkel wie in een oud systeem voorkomt
     */
    public function persons(): array
    {
        if ($this->personen === []) {
            $this->bouw();
        }

        return array_values(array_filter(
            $this->personen,
            fn (ArchivePerson $p): bool => $p->comp !== null || $p->intra !== null,
        ));
    }

    /** @return array<int, int> dubbel player-id => de speler die blijft */
    public function duplicatePlayers(): array
    {
        return $this->overrides['player_dubbels'];
    }

    /** @return list<string> overrides die niet toegepast konden worden */
    public function warnings(): array
    {
        $this->persons();

        return $this->waarschuwingen;
    }

    private function bouw(): void
    {
        $this->laadHuidigeSpelers();
        $this->laadIntraSpelers();
        $this->laadCompSpelers();
        $this->indexeerHerkomst();
        $this->pasCompOverridesToe();
        $this->koppelCompAanIntra();
        $this->pasSpelerOverridesToe();
        $this->bepaalStatus();
    }

    private function laadHuidigeSpelers(): void
    {
        $dubbels = $this->overrides['player_dubbels'];

        foreach (DB::table('players')->get() as $rij) {
            $this->spelerPerId[$rij->id] = $rij;

            // Een bevestigd dubbel telt niet mee als eigen speler: de overblijvende id wint.
            if (isset($dubbels[$rij->id])) {
                continue;
            }

            $sleutel = self::sleutel($rij->first_name, $rij->last_name);
            $persoon = $this->persoon($sleutel);

            if ($persoon->playerId !== null) {
                // Dezelfde naam staat meermaals in players zonder dat het als dubbel
                // bevestigd is: dat kan niet automatisch gekoppeld worden.
                $persoon->notes[] = sprintf('naam staat meermaals in players: id %d en %d', $persoon->playerId, $rij->id);
                $persoon->status = 'AMBIGU';

                continue;
            }
            $persoon->playerId = $rij->id;
        }
    }

    private function laadIntraSpelers(): void
    {
        $rijen = $this->archive->table('intra_spelers')
            ->select('id', 'voornaam', 'naam', 'geslacht', 'klassement', 'is_lid', 'is_veteraan')
            ->get();

        foreach ($rijen as $rij) {
            $persoon = $this->persoon(self::sleutel($rij->voornaam, $rij->naam));
            $persoon->intra = $rij;
        }
    }

    private function laadCompSpelers(): void
    {
        $rijen = $this->archive->table('comp_spelers')
            ->select('ID', 'voornaam', 'achternaam', 'geslacht')
            ->get();

        foreach ($rijen as $rij) {
            $persoon = $this->persoon(self::sleutel($rij->voornaam, $rij->achternaam));
            $persoon->comp = $rij;
            $persoon->compLink = $persoon->intra === null ? '' : 'exact';
        }
    }

    private function indexeerHerkomst(): void
    {
        foreach ($this->personen as $sleutel => $persoon) {
            if ($persoon->comp !== null) {
                $this->sleutelPerCompId[$persoon->comp->ID] = $sleutel;
            }
            if ($persoon->intra !== null) {
                $this->sleutelPerIntraId[$persoon->intra->id] = $sleutel;
            }
        }
    }

    private function pasCompOverridesToe(): void
    {
        foreach ($this->overrides['comp_naar_intra'] as $compId => $intraId) {
            $compSleutel = $this->sleutelPerCompId[$compId] ?? null;
            $intraSleutel = $this->sleutelPerIntraId[$intraId] ?? null;

            if ($compSleutel === null || $intraSleutel === null) {
                $this->waarschuwingen[] = sprintf('comp-id %d of intra-id %d bestaat niet', $compId, $intraId);

                continue;
            }
            if ($compSleutel === $intraSleutel) {
                continue; // Namen zijn intussen gelijk: de exacte match deed het werk al.
            }

            $this->voegSamen($compSleutel, $intraSleutel, 'bevestigd');
        }
    }

    /**
     * Tussen beide oude systemen zijn namen soms anders gespeld ("Heirbrant" / "Heirbrandt").
     * We koppelen het beste paar eerst, zodat één intra-speler niet twee comp-spelers opslokt.
     */
    private function koppelCompAanIntra(): void
    {
        $compLos = $this->sleutelsMet(fn (ArchivePerson $p): bool => $p->comp !== null && $p->intra === null);
        $intraLos = $this->sleutelsMet(fn (ArchivePerson $p): bool => $p->intra !== null && $p->comp === null);

        $paren = [];
        foreach ($compLos as $compSleutel) {
            foreach ($intraLos as $intraSleutel) {
                $score = self::gelijkenis($compSleutel, $intraSleutel);
                if ($score >= self::DREMPEL_COMP) {
                    $paren[] = ['score' => $score, 'comp' => $compSleutel, 'intra' => $intraSleutel];
                }
            }
        }
        usort($paren, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $gebruikt = [];
        foreach ($paren as $paar) {
            if (isset($gebruikt[$paar['comp']]) || isset($gebruikt[$paar['intra']])) {
                continue;
            }
            $gebruikt[$paar['comp']] = true;
            $gebruikt[$paar['intra']] = true;

            $this->voegSamen($paar['comp'], $paar['intra'], sprintf('voorstel (%.0f)', $paar['score']));
        }
    }

    private function pasSpelerOverridesToe(): void
    {
        foreach ($this->overrides['intra_naar_player'] as $intraId => $playerId) {
            $sleutel = $this->sleutelPerIntraId[$intraId] ?? null;

            if ($sleutel === null || ! isset($this->personen[$sleutel])) {
                $this->waarschuwingen[] = sprintf('intra-id %d bestaat niet', $intraId);

                continue;
            }
            if (! isset($this->spelerPerId[$playerId])) {
                $this->waarschuwingen[] = sprintf('player-id %d bestaat niet', $playerId);

                continue;
            }

            $this->personen[$sleutel]->playerId = $playerId;
            $this->personen[$sleutel]->status = 'BEVESTIGD';
        }
    }

    /**
     * Geeft elke persoon een status en, waar nodig, een voorstel: huidige spelers zonder
     * exacte tegenhanger blijven kandidaat voor wie nog niet gekoppeld is.
     */
    private function bepaalStatus(): void
    {
        $vrijeSpelers = [];
        foreach ($this->personen as $sleutel => $persoon) {
            if ($persoon->playerId !== null && $persoon->comp === null && $persoon->intra === null) {
                $vrijeSpelers[$persoon->playerId] = $sleutel;
            }
        }

        foreach ($this->personen as $sleutel => $persoon) {
            $bron = $persoon->intra ?? $persoon->comp;
            if ($bron === null) {
                continue;
            }

            $persoon->firstName = trim($persoon->intra->voornaam ?? $persoon->comp->voornaam);
            $persoon->lastName = trim($persoon->intra->naam ?? $persoon->comp->achternaam);
            // De twee generaties spellen het geslacht anders — `intra_spelers` schrijft
            // "Man"/"Vrouw", `comp_spelers` "man"/"vrouw" — dus het krijgt hier meteen de
            // vorm van Gender. Dit is de enige plek waar de oude databanken gelezen
            // worden, en dus de enige plek waar die twee spellingen mogen bestaan.
            $persoon->gender = self::geslacht($persoon->intra->geslacht ?? $persoon->comp->geslacht ?? null);
            $persoon->ranking = $persoon->intra->klassement ?? null;

            if ($persoon->status === 'AMBIGU' || $persoon->status === 'BEVESTIGD') {
                $persoon->score = $persoon->status === 'AMBIGU' ? '100' : '';

                continue;
            }
            if ($persoon->playerId !== null) {
                $persoon->status = 'GEKOPPELD';
                $persoon->score = '100';

                continue;
            }

            $this->zoekVoorstel($persoon, $sleutel, $vrijeSpelers);
        }
    }

    /** @param array<int, string> $vrijeSpelers player-id => genormaliseerde naam */
    private function zoekVoorstel(ArchivePerson $persoon, string $sleutel, array $vrijeSpelers): void
    {
        $scores = [];
        foreach ($vrijeSpelers as $id => $kandidaatSleutel) {
            $scores[$id] = self::gelijkenis($sleutel, $kandidaatSleutel);
        }
        arsort($scores);

        $besteId = array_key_first($scores);
        $besteScore = $besteId === null ? 0.0 : $scores[$besteId];

        if ($besteScore < self::DREMPEL_VOORSTEL) {
            $persoon->status = 'NIEUW';
            $persoon->score = $besteId === null ? '' : sprintf('%.0f', $besteScore);

            if ($besteScore >= 65.0) {
                $persoon->notes[] = sprintf(
                    'zwakke gelijkenis, niet voorgesteld: %s (id %d, %.0f)',
                    $this->naamVan($besteId),
                    $besteId,
                    $besteScore,
                );
            }

            return;
        }

        $concurrenten = array_filter(
            $scores,
            fn (float $score, int $id): bool => $id !== $besteId && $score >= $besteScore - self::AMBIGU_MARGE,
            ARRAY_FILTER_USE_BOTH,
        );

        $persoon->playerId = $besteId;
        $persoon->score = sprintf('%.0f', $besteScore);
        $persoon->status = $concurrenten === [] ? 'VOORSTEL' : 'AMBIGU';

        foreach (array_keys($concurrenten) as $id) {
            $persoon->notes[] = sprintf('ook mogelijk: %s (id %d, %.0f)', $this->naamVan($id), $id, $scores[$id]);
        }
    }

    private function naamVan(int $playerId): string
    {
        $speler = $this->spelerPerId[$playerId] ?? null;

        return $speler === null ? '?' : "{$speler->first_name} {$speler->last_name}";
    }

    private function persoon(string $sleutel): ArchivePerson
    {
        return $this->personen[$sleutel] ??= new ArchivePerson;
    }

    /** @param callable(ArchivePerson): bool $filter @return list<string> */
    private function sleutelsMet(callable $filter): array
    {
        return array_keys(array_filter($this->personen, $filter));
    }

    /** Voegt de comp-speler van $compSleutel samen met de intra-speler van $intraSleutel. */
    private function voegSamen(string $compSleutel, string $intraSleutel, string $koppeling): void
    {
        $comp = $this->personen[$compSleutel]->comp;

        $this->personen[$intraSleutel]->comp = $comp;
        $this->personen[$intraSleutel]->compLink = $koppeling;
        $this->personen[$intraSleutel]->notes[] = sprintf(
            'comp-naam wijkt af: %s %s (comp-id %d)',
            $comp->voornaam,
            $comp->achternaam,
            $comp->ID,
        );

        $this->sleutelPerCompId[$comp->ID] = $intraSleutel;
        unset($this->personen[$compSleutel]);
    }

    // ------------------------------------------------------------ naamvergelijking

    /** Normaliseert een naam: kleine letters, geen accenten, geen dubbele spaties. */
    public static function normaliseer(string $naam): string
    {
        $naam = strtr(mb_strtolower(trim($naam)), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y',
        ]);
        $naam = preg_replace('/[^a-z ]+/', '', $naam);

        return preg_replace('/\s+/', ' ', trim($naam));
    }

    private static function sleutel(string $voornaam, string $achternaam): string
    {
        return self::normaliseer($voornaam).'|'.self::normaliseer($achternaam);
    }

    /** Gelijkenis 0-100, met verdraagzaamheid voor omgewisselde voor- en achternaam. */
    private static function gelijkenis(string $sleutelA, string $sleutelB): float
    {
        [$voornaamA, $achternaamA] = explode('|', $sleutelA);
        [$voornaamB, $achternaamB] = explode('|', $sleutelB);

        $recht = self::paarScore($voornaamA, $achternaamA, $voornaamB, $achternaamB);
        $omgewisseld = self::paarScore($achternaamA, $voornaamA, $voornaamB, $achternaamB) - 1.0;

        return max($recht, $omgewisseld);
    }

    /**
     * Vergelijkt twee naamparen veld per veld. Het tweede veld weegt zwaarder, want daar
     * staat normaal de achternaam: voornamen worden vaker afgekort of anders gespeld.
     */
    private static function paarScore(string $eersteA, string $tweedeA, string $eersteB, string $tweedeB): float
    {
        return 0.4 * self::veldScore($eersteA, $eersteB) + 0.6 * self::veldScore($tweedeA, $tweedeB);
    }

    /**
     * Het geslacht zoals de oude databanken het schreven, op de vorm van Gender.
     * Alles wat geen van beide is — leeg, of een waarde die geen van de twee
     * generaties hoort te schrijven — wordt null: dat is in het archief al de
     * betekenis "hier zit geen persoon achter".
     */
    private static function geslacht(?string $bron): ?string
    {
        return match (mb_strtolower(trim((string) $bron))) {
            'man' => Gender::Male->value,
            'vrouw' => Gender::Female->value,
            default => null,
        };
    }

    private static function veldScore(string $a, string $b): float
    {
        if ($a === $b) {
            return 100.0;
        }
        if ($a === '' || $b === '') {
            return 0.0;
        }
        // Afkorting of roepnaam: "bea" vs "beatrijs", "jean marie" vs "jeanmarie".
        if (str_starts_with($a, $b) || str_starts_with($b, $a)) {
            return 92.0;
        }

        $afstand = levenshtein($a, $b);

        return max(0.0, 100.0 - ($afstand / max(strlen($a), strlen($b))) * 100.0);
    }
}

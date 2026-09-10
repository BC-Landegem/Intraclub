<?php

namespace App\Filament\Pages;

use App\Models\Player;
use App\Models\Season;
use App\Services\DrawService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Wie stond dit seizoen aan de kant, en hoe vaak — om de loting te kunnen nakijken.
 *
 * Drie keuzes die uit de code zelf niet af te lezen zijn:
 *
 * - Geteld wordt wie EFFECTIEF aan de kant bleef, niet wie de loting uitkoos. De
 *   vlag is niet blijvend: vult een laatkomer de match aan, dan speelt de eerder
 *   uitgelote speler toch mee en wist GameObserver zijn vlag. Dat is de bedoeling
 *   — eerlijkheid gaat over speeltijd — en het spaart een tweede waarheid naast
 *   player_round_statistics uit.
 * - "Sinds" en het schild rekenen vanaf de VOLGENDE speeldag, en tellen dus enkel
 *   uitlotingen daarvóór, precies zoals DrawService::participants(). Vanaf de
 *   laatste speeldag rekenen zou iedereen die gisteren uitviel een schild geven
 *   terwijl hij gisteren in zijn eigen lijst staat: dat oogt als een schending.
 * - Geen alarmregel. De bescherming is met opzet zacht (zit iedereen erin, dan
 *   valt er alsnog iemand uit), dus een tweede definitie van "fout" in de admin
 *   zou van de loting wegdrijven.
 */
class Uitlotingen extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.uitlotingen';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserMinus;

    protected static ?string $navigationLabel = 'Uitlotingen';

    protected static ?string $title = 'Uitlotingen';

    protected static ?int $navigationSort = 5;

    public ?int $seasonId = null;

    /** @var array<int, array<int, list<int>>> */
    private array $drawnOutRoundsPerSeason = [];

    public function mount(): void
    {
        $this->seasonId = Season::current()?->id;
    }

    /** @return array<int, string> */
    public function getSeasonOptions(): array
    {
        return Season::query()->orderByDesc('id')->pluck('name', 'id')->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->playersQuery())
            ->paginated(false)
            ->defaultSort('drawn_out_count', 'desc')
            ->emptyStateHeading('Geen spelers in dit seizoen')
            ->emptyStateDescription('Zodra er een speeldag met aanwezigheden is, staat hier wie aan de kant bleef.')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Speler')
                    ->searchable(['first_name', 'last_name'])
                    ->sortable(['first_name', 'last_name']),
                TextColumn::make('drawn_out_count')
                    ->label('Uitgeloot')
                    ->numeric()
                    ->alignEnd()
                    // Gelijke aantallen zijn hier de regel, niet de uitzondering:
                    // zonder naam als tweede sleutel staat het scherm bij elke
                    // verversing anders.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('drawn_out_count', $direction)
                        ->orderBy('first_name')
                        ->orderBy('last_name')),
                TextColumn::make('present_count')
                    ->label('Aanwezig')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('drawn_out_rounds')
                    ->label('Speeldagen')
                    ->state(fn (Player $record): string => implode(', ', $this->drawnOutRounds($record)) ?: '—'),
                TextColumn::make('rounds_since')
                    ->label('Sinds · sd '.$this->nextRoundNumber())
                    ->alignEnd()
                    ->state(fn (Player $record): string => (string) ($this->roundsSinceDrawnOut($record) ?? '—'))
                    ->icon(fn (Player $record): ?Heroicon => $this->isProtected($record)
                        ? Heroicon::OutlinedShieldCheck
                        : null)
                    ->iconPosition(IconPosition::After),
            ]);
    }

    /** @return array{players: int, drawn_out: int, rounds: int} */
    public function getSummary(): array
    {
        return [
            'players' => $this->playersQuery()->count(),
            'drawn_out' => array_sum(array_map('count', $this->drawnOutRoundsOfSeason())),
            'rounds' => $this->seasonId === null
                ? 0
                : DB::table('rounds')->where('season_id', $this->seasonId)->count(),
        ];
    }

    public function nextRoundNumber(): int
    {
        if ($this->seasonId === null) {
            return 1;
        }

        return (int) DB::table('rounds')->where('season_id', $this->seasonId)->max('number') + 1;
    }

    /**
     * Een rij per lid dat dit seizoen aanwezig was, plus wie toen uitgeloot werd maar
     * inmiddels geen lid meer is: anders verdwijnt zijn geschiedenis uit de lijst
     * terwijl ze in het totaal blijft meetellen.
     */
    private function playersQuery(): Builder
    {
        if ($this->seasonId === null) {
            return Player::query()->whereRaw('1 = 0');
        }

        $seasonId = $this->seasonId;
        $inSeason = fn (Builder $query): Builder => $query->whereHas(
            'round',
            fn (Builder $round): Builder => $round->where('season_id', $seasonId)
        );

        return Player::query()
            ->withCount([
                'roundStatistics as drawn_out_count' => fn (Builder $query) => $inSeason($query->where('is_drawn_out', true)),
                'roundStatistics as present_count' => fn (Builder $query) => $inSeason($query->where('is_present', true)),
            ])
            ->where(function (Builder $query) use ($inSeason): void {
                $query
                    ->where(fn (Builder $member): Builder => $member
                        ->where('is_member', true)
                        ->whereHas('roundStatistics', fn (Builder $statistic) => $inSeason($statistic->where('is_present', true))))
                    ->orWhereHas('roundStatistics', fn (Builder $statistic) => $inSeason($statistic->where('is_drawn_out', true)));
            });
    }

    /** @return list<int> */
    private function drawnOutRounds(Player $player): array
    {
        return $this->drawnOutRoundsOfSeason()[$player->id] ?? [];
    }

    private function roundsSinceDrawnOut(Player $player): ?int
    {
        $rounds = $this->drawnOutRounds($player);

        return $rounds === [] ? null : $this->nextRoundNumber() - max($rounds);
    }

    private function isProtected(Player $player): bool
    {
        $since = $this->roundsSinceDrawnOut($player);

        return $since !== null && $since <= DrawService::PROTECTED_ROUNDS;
    }

    /**
     * De speeldagnummers waarop elke speler aan de kant bleef. Eén query voor het hele
     * seizoen: de tabel pagineert niet, en drie kolommen lezen eruit.
     *
     * @return array<int, list<int>>
     */
    private function drawnOutRoundsOfSeason(): array
    {
        if ($this->seasonId === null) {
            return [];
        }

        return $this->drawnOutRoundsPerSeason[$this->seasonId] ??= DB::table('player_round_statistics as statistic')
            ->join('rounds', 'rounds.id', '=', 'statistic.round_id')
            ->where('rounds.season_id', $this->seasonId)
            ->where('statistic.is_drawn_out', true)
            ->orderBy('rounds.number')
            ->get(['statistic.player_id', 'rounds.number'])
            ->groupBy('player_id')
            ->map(fn ($rows): array => $rows->pluck('number')->map(fn ($number): int => (int) $number)->all())
            ->all();
    }
}

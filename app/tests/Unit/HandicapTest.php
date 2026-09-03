<?php

namespace Tests\Unit;

use App\Services\Handicap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HandicapTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int, 2: int, 3: int}> thuisbonus, uitbonus, start thuis, start uit */
    public static function verdelingen(): array
    {
        return [
            'gelijke duo\'s krijgen geen voorsprong' => [9, 9, 0, 0],
            'even verschil splitst gelijk' => [12, 6, 3, -3],
            'oneven verschil geeft het punt aan het zwakke duo' => [13, 6, 4, -3],
            'verschil van 1' => [8, 7, 1, 0],
            'het zwakke duo kan het uitduo zijn' => [6, 12, -3, 3],
            'oneven, met het zwakke duo uit' => [6, 13, -3, 4],
            'grootste verschil uit de productiedump' => [14, 3, 6, -5],
        ];
    }

    #[DataProvider('verdelingen')]
    public function test_de_startstanden_volgen_de_bonussommen(int $home, int $away, int $startHome, int $startAway): void
    {
        $handicap = Handicap::between($home, $away);

        $this->assertSame($startHome, $handicap->home);
        $this->assertSame($startAway, $handicap->away);
    }

    /**
     * De afstand tussen de twee startstanden is exact het bonusverschil — dat is de
     * eigenschap die H/2 met de oude regel deelt, en het enige wat het spel merkt.
     */
    public function test_de_afstand_blijft_het_volledige_verschil(): void
    {
        for ($difference = 0; $difference <= 14; $difference++) {
            $handicap = Handicap::between($difference, 0);

            $this->assertSame($difference, $handicap->home - $handicap->away, "verschil {$difference}");
        }
    }

    /**
     * Wat de regel moest oplossen: de som van beide startstanden is nul, of één bij
     * een oneven verschil. De oude regel injecteerde H punten in de set, en die
     * gingen via het setgemiddelde rechtstreeks het klassement in.
     */
    public function test_er_worden_nauwelijks_punten_geinjecteerd(): void
    {
        for ($difference = 0; $difference <= 14; $difference++) {
            $handicap = Handicap::between($difference, 0);

            $this->assertSame($difference % 2, $handicap->home + $handicap->away, "verschil {$difference}");
        }
    }
}

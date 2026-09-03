<?php

namespace Tests\Unit;

use App\Enums\PointsPerSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * De setstanden die kunnen bestaan, per puntenschaal.
 *
 * Een set gaat tot het setmaximum met minstens twee punten verschil; staat het
 * op één punt verschil vanaf daar, dan wordt doorgespeeld tot iemand er twee
 * voorstaat, tot aan de cap waar het volgende punt beslist.
 */
class PointsPerSetTest extends TestCase
{
    /** @return array<string, array{0: PointsPerSet, 1: int, 2: int}> */
    public static function geldigeStanden(): array
    {
        return [
            'tot 15: nul tegen' => [PointsPerSet::Fifteen, 15, 0],
            'tot 15: ruime winst' => [PointsPerSet::Fifteen, 15, 11],
            'tot 15: nipt op het maximum' => [PointsPerSet::Fifteen, 15, 13],
            'tot 15: omgekeerd' => [PointsPerSet::Fifteen, 13, 15],
            'tot 15: eerste verlenging' => [PointsPerSet::Fifteen, 16, 14],
            'tot 15: verlenging halverwege' => [PointsPerSet::Fifteen, 20, 18],
            'tot 15: cap met twee verschil' => [PointsPerSet::Fifteen, 21, 19],
            'tot 15: cap met gouden punt' => [PointsPerSet::Fifteen, 21, 20],
            'tot 15: gouden punt omgekeerd' => [PointsPerSet::Fifteen, 20, 21],

            'tot 21: nul tegen' => [PointsPerSet::TwentyOne, 21, 0],
            'tot 21: ruime winst' => [PointsPerSet::TwentyOne, 21, 15],
            'tot 21: nipt op het maximum' => [PointsPerSet::TwentyOne, 21, 19],
            'tot 21: eerste verlenging' => [PointsPerSet::TwentyOne, 22, 20],
            'tot 21: cap met twee verschil' => [PointsPerSet::TwentyOne, 30, 28],
            'tot 21: cap met gouden punt' => [PointsPerSet::TwentyOne, 30, 29],
        ];
    }

    /** @return array<string, array{0: PointsPerSet, 1: int, 2: int}> */
    public static function ongeldigeStanden(): array
    {
        return [
            // De aanleiding voor deze regel: 16 kan alleen tegen 14.
            'tot 15: winnaar te ver voor' => [PointsPerSet::Fifteen, 13, 16],
            'tot 15: één punt verschil op het maximum' => [PointsPerSet::Fifteen, 15, 14],
            'tot 15: niemand haalt het maximum' => [PointsPerSet::Fifteen, 14, 9],
            'tot 15: gelijk' => [PointsPerSet::Fifteen, 15, 15],
            'tot 15: verlenging met drie verschil' => [PointsPerSet::Fifteen, 18, 15],
            'tot 15: voorbij de cap' => [PointsPerSet::Fifteen, 22, 20],
            'tot 15: cap met drie verschil' => [PointsPerSet::Fifteen, 21, 18],
            'tot 15: negatief' => [PointsPerSet::Fifteen, 15, -1],

            // Precies wat op de 15-schaal wél mag: de twee schalen overlappen niet
            // in hun verlengingen (16-21 tegenover 22-30).
            'tot 21: verlenging van de kleine schaal' => [PointsPerSet::TwentyOne, 16, 14],
            'tot 21: één punt verschil op het maximum' => [PointsPerSet::TwentyOne, 21, 20],
            'tot 21: gelijk' => [PointsPerSet::TwentyOne, 21, 21],
            'tot 21: voorbij de cap' => [PointsPerSet::TwentyOne, 31, 29],
            'tot 21: cap met drie verschil' => [PointsPerSet::TwentyOne, 30, 27],
        ];
    }

    #[DataProvider('geldigeStanden')]
    public function test_geldige_setstanden(PointsPerSet $pointsPerSet, int $home, int $away): void
    {
        $this->assertTrue(
            $pointsPerSet->allowsSet($home, $away),
            "{$home}-{$away} hoort te kunnen bij {$pointsPerSet->getLabel()}."
        );
    }

    #[DataProvider('ongeldigeStanden')]
    public function test_ongeldige_setstanden(PointsPerSet $pointsPerSet, int $home, int $away): void
    {
        $this->assertFalse(
            $pointsPerSet->allowsSet($home, $away),
            "{$home}-{$away} hoort niet te kunnen bij {$pointsPerSet->getLabel()}."
        );
    }

    public function test_een_set_die_nog_niet_gespeeld_is_mag_leeg_blijven(): void
    {
        $this->assertTrue(PointsPerSet::Fifteen->allowsSet(null, null));
    }

    public function test_een_half_ingevulde_set_bestaat_niet(): void
    {
        $this->assertFalse(PointsPerSet::Fifteen->allowsSet(15, null));
        $this->assertFalse(PointsPerSet::Fifteen->allowsSet(null, 13));
    }

    public function test_de_cap_hangt_aan_de_schaal(): void
    {
        $this->assertSame(21, PointsPerSet::Fifteen->cap());
        $this->assertSame(30, PointsPerSet::TwentyOne->cap());
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Welke regel de viertallen samenstelt. Enkel dat verschilt tussen de twee: wie
 * meedoet, wie aan de kant blijft en het beschermingsvenster zijn gedeeld.
 *
 * `StrengthGroups` heet niet "klassiek": over drie seizoenen zegt "nieuw" niets
 * meer over wat het doet, en de kolom moet leesbaar blijven zonder git.
 *
 * `HasDescription` laat Filament de uitleg per keuze zelf tonen; daarom staat ze hier
 * en niet als helperText in het formulier.
 */
enum DrawSystem: string implements HasDescription, HasLabel
{
    case StrengthGroups = 'strength_groups';
    case VaryingOpponents = 'varying_opponents';

    public function getLabel(): string
    {
        return match ($this) {
            self::StrengthGroups => 'Sterktegroepen',
            self::VaryingOpponents => 'Wisselende tegenstanders',
        };
    }

    public function getDescription(): string|Htmlable|null
    {
        return match ($this) {
            // Niet "sterk tegen sterk": de groepen overlappen 20% en binnen een groep
            // beslist het toeval, dus verder dan "dezelfde helft" reikt het niet.
            self::StrengthGroups => 'Twee overlappende sterktegroepen, willekeurig binnen de groep. Je speelt tegen dezelfde helft van het klassement.',
            self::VaryingOpponents => 'Deelt in op wie dit seizoen nog niet tegen elkaar speelde. Sterkte speelt geen rol.',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Gender: string implements HasLabel
{
    case Male = 'male';
    case Female = 'female';

    public function getLabel(): string
    {
        return match ($this) {
            self::Male => 'Man',
            self::Female => 'Vrouw',
        };
    }

    /**
     * Waarde zoals de legacy-API ze publiceert. De publieke API blijft deze
     * gebruiken zodat bestaande consumenten (o.a. de zaal-app) blijven werken.
     */
    public function apiValue(): string
    {
        return match ($this) {
            self::Male => 'Man',
            self::Female => 'Woman',
        };
    }
}

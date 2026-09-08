<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Naam')
                    ->required(),
                TextInput::make('email')
                    ->label('E-mailadres')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->required(),
                TextInput::make('password')
                    ->label('Wachtwoord')
                    ->password()
                    ->revealable()
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText('Laat leeg om het huidige wachtwoord te behouden.')
                    ->minLength(8),
                // Op je eigen rij vast, anders sluit je jezelf onherroepelijk buiten.
                Toggle::make('is_admin')
                    ->label('Toegang tot het beheerspaneel')
                    ->helperText('Uit: deze gebruiker kan enkel in de zaal-app, niet in dit beheerspaneel.')
                    ->default(true)
                    ->disabled(fn (?object $record): bool => $record?->id === auth()->id()),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Rounds\RelationManagers;

use App\Enums\PointsPerSet;
use App\Models\Player;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GamesRelationManager extends RelationManager
{
    protected static string $relationship = 'games';

    protected static ?string $title = 'Games';

    protected static ?string $modelLabel = 'game';

    protected static ?string $pluralModelLabel = 'games';

    private const PLAYER_FIELDS = ['player1_id', 'player2_id', 'player3_id', 'player4_id'];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Spelers')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        self::playerSelect('player1_id', 'Speler 1'),
                        self::playerSelect('player2_id', 'Speler 2'),
                        self::playerSelect('player3_id', 'Speler 3'),
                        self::playerSelect('player4_id', 'Speler 4'),
                    ]),
                Section::make('Scores')
                    ->description('De teams roteren per set. Laat leeg wat nog niet gespeeld is.')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        $this->setGroup(1, '1+2', '3+4'),
                        $this->setGroup(2, '1+3', '2+4'),
                        $this->setGroup(3, '1+4', '2+3'),
                    ]),
            ]);
    }

    private function setGroup(int $set, string $homeTeam, string $awayTeam): Group
    {
        return Group::make([
            $this->scoreInput("set{$set}_home", "Set {$set}: {$homeTeam}")
                ->rule(fn (Get $get): Closure => $this->setIsPlayable($get, $set)),
            $this->scoreInput("set{$set}_away", "Set {$set}: {$awayTeam}"),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('player1.full_name')
                    ->label('Speler 1'),
                TextColumn::make('player2.full_name')
                    ->label('Speler 2'),
                TextColumn::make('player3.full_name')
                    ->label('Speler 3'),
                TextColumn::make('player4.full_name')
                    ->label('Speler 4'),
                TextColumn::make('set1_home')
                    ->label('Set 1')
                    ->formatStateUsing(fn ($record): string => "{$record->set1_home} - {$record->set1_away}"),
                TextColumn::make('set2_home')
                    ->label('Set 2')
                    ->formatStateUsing(fn ($record): string => "{$record->set2_home} - {$record->set2_away}"),
                TextColumn::make('set3_home')
                    ->label('Set 3')
                    ->formatStateUsing(fn ($record): string => "{$record->set3_home} - {$record->set3_away}"),
                IconColumn::make('is_complete')
                    ->label('Compleet')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Nieuwe game')
                    ->modalWidth(Width::FourExtraLarge),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge),
                DeleteAction::make(),
            ]);
    }

    private static function playerSelect(string $name, string $label): Select
    {
        $select = Select::make($name)
            ->label($label)
            ->options(
                fn (): array => Player::query()
                    ->members()
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get()
                    ->mapWithKeys(fn (Player $player): array => [$player->id => $player->full_name])
                    ->all()
            )
            ->searchable()
            ->required();

        // Elke speler mag maar één keer in dezelfde game staan.
        foreach (self::PLAYER_FIELDS as $field) {
            if ($field !== $name) {
                $select->different($field);
            }
        }

        return $select;
    }

    /**
     * De grenzen komen uit de puntenschaal van het seizoen: hoger dan de cap kan
     * een setstand niet gaan. De regel over de twee getallen sámen hangt aan het
     * thuisvak van de set, zodat de melding maar één keer verschijnt.
     */
    private function scoreInput(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->minValue(0)
            ->maxValue(fn (): int => $this->pointsPerSet()->cap())
            ->validationMessages([
                'max' => fn (): string => "Meer dan {$this->pointsPerSet()->cap()} punten kan niet in een set.",
                'min' => 'Een setstand kan niet negatief zijn.',
            ])
            ->nullable();
    }

    /** De setstand als paar: beide leeg, of een stand die echt gespeeld kan zijn. */
    private function setIsPlayable(Get $get, int $set): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $set): void {
            $pointsPerSet = $this->pointsPerSet();
            $home = self::toScore($get("set{$set}_home"));
            $away = self::toScore($get("set{$set}_away"));

            if ($pointsPerSet->allowsSet($home, $away)) {
                return;
            }

            $fail($home === null || $away === null
                ? "Set {$set}: vul beide punten in of laat beide leeg."
                : "Set {$set}: {$home}-{$away} kan niet. {$pointsPerSet->setRule()}");
        };
    }

    private function pointsPerSet(): PointsPerSet
    {
        return $this->getOwnerRecord()->season->points_per_set;
    }

    private static function toScore(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}

<?php

namespace App\Filament\Pages;

use App\Models\Round;
use App\Models\Season;
use App\Services\RankingService;
use App\Services\SeasonCalculator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Klassement extends Page
{
    protected string $view = 'filament.pages.klassement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?string $navigationLabel = 'Klassement';

    protected static ?string $title = 'Klassement';

    protected static ?int $navigationSort = 4;

    public ?int $seasonId = null;

    public function mount(): void
    {
        $this->seasonId = Season::current()?->id;
    }

    /** @return array<int, string> */
    public function getSeasonOptions(): array
    {
        return Season::query()->orderByDesc('id')->pluck('name', 'id')->all();
    }

    /** @return array{season: ?Season, round: ?Round, categories: array<string, list<array{id: int, first_name: string, last_name: string, full_name: string, average: float, rank: int, difference: int}>>} */
    public function getRanking(): array
    {
        return app(RankingService::class)->get($this->seasonId, categories: RankingService::CATEGORIES);
    }

    /** @return array<string, string> */
    public function getCategoryLabels(): array
    {
        return [
            RankingService::CATEGORY_GENERAL => 'Algemeen',
            RankingService::CATEGORY_WOMEN => 'Dames',
            RankingService::CATEGORY_VETERANS => 'Veteranen (45+)',
            RankingService::CATEGORY_RECREANTS => 'Recreanten',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculate')
                ->label('Herbereken tussenstand')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription('Herberekent alle speeldag- en seizoensstatistieken van het gekozen seizoen. Gebruik dit na een handmatige correctie.')
                ->action(function (): void {
                    $season = Season::findOrFail($this->seasonId);
                    app(SeasonCalculator::class)->calculate($season);

                    Notification::make()
                        ->title("Tussenstand van {$season->name} herberekend")
                        ->success()
                        ->send();
                }),
        ];
    }
}

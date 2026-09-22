<?php

namespace App\Filament\Pages;

use App\Jobs\SendPushMessage;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Services\Push\WebPushSender;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Clubberichten versturen (onderwerp `club`) en het logboek van alles wat
 * vertrok, ook de automatische intraclub-berichten.
 *
 * Er is bewust geen "test naar mezelf": dat vraagt te weten welk abonnement
 * van wie is, en die koppeling belooft de privacyverklaring niet te maken. Wie
 * wil testen doet dat op een omgeving met een eigen VAPID-paar.
 */
class Pushberichten extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.pushberichten';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?string $navigationLabel = 'Pushberichten';

    protected static ?string $title = 'Pushberichten';

    protected static ?int $navigationSort = 6;

    /*
     * Het paneel laat enkel is_admin binnen (User::canAccessPanel), dus dit
     * verandert vandaag niets. Het staat er omdat een bericht naar alle
     * toestellen van de club iets anders is dan een uitslag verbeteren: komt er
     * ooit een tussenrol, dan blijft deze pagina expliciet bij de beheerders.
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_admin;
    }

    public static function isConfigured(): bool
    {
        return WebPushSender::isConfigured();
    }

    /** @return array<string, array{label: string, count: int}> */
    public function getTopicCounts(): array
    {
        $counts = [];

        foreach (config('push.topics') as $topic => $settings) {
            $counts[$topic] = [
                'label' => $settings['label'],
                'count' => PushSubscription::query()->forTopic($topic)->count(),
            ];
        }

        return $counts;
    }

    public function getSubscriptionCount(): int
    {
        return PushSubscription::query()->count();
    }

    /**
     * Berichten die al langer dan een paar minuten in de wachtrij staan. De cron
     * die de wachtrij leegt is het enige stuk van deze feature dat buiten de
     * deploy valt (zie DEPLOY.md), en dit is de enige plek waar te zien is dat
     * hij niet draait.
     */
    public function getStuckCount(): int
    {
        return PushMessage::query()
            ->whereNull('sent_at')
            ->whereNull('error')
            ->where('created_at', '<', now()->subMinutes(3))
            ->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label('Clubbericht versturen')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->disabled(fn (): bool => ! self::isConfigured())
                ->tooltip(fn (): ?string => self::isConfigured() ? null : 'Push staat uit: geen VAPID-sleutels in .env.')
                ->modalHeading('Clubbericht versturen')
                ->modalDescription(fn (): string => sprintf(
                    'Gaat naar %d %s met een abonnement op clubberichten. Dit kan niet ongedaan gemaakt worden.',
                    $count = $this->getTopicCounts()['club']['count'],
                    $count === 1 ? 'toestel' : 'toestellen',
                ))
                ->modalSubmitActionLabel('Verstuur')
                ->schema([
                    TextInput::make('title')
                        ->label('Titel')
                        ->required()
                        ->maxLength(60)
                        ->helperText('Kort: de titel staat vet in de melding. Bv. "Geen badminton op 2 oktober".'),
                    Textarea::make('body')
                        ->label('Bericht')
                        ->required()
                        ->maxLength(180)
                        ->rows(3)
                        ->helperText('Hoogstens een paar zinnen; op een telefoon is na zo\'n 150 tekens de rest weg.'),
                    TextInput::make('url')
                        ->label('Link (optioneel)')
                        ->url()
                        ->maxLength(500)
                        ->placeholder(config('push.site_url').'/kalender/')
                        ->helperText('Waar een tik op de melding naartoe gaat. Leeg = de startpagina van de site.'),
                ])
                ->action(function (array $data): void {
                    $message = PushMessage::create([
                        'topic' => 'club',
                        'title' => trim($data['title']),
                        'body' => trim($data['body']),
                        'url' => filled($data['url'] ?? null) ? trim($data['url']) : config('push.site_url').'/',
                        'user_id' => auth()->id(),
                    ]);

                    SendPushMessage::dispatch($message->id);

                    Notification::make()
                        ->title('Bericht staat klaar')
                        ->body('Het vertrekt binnen de minuut; het logboek toont daarna naar hoeveel toestellen.')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PushMessage::query()->with(['user', 'round']))
            ->defaultSort('created_at', 'desc')
            ->paginated([25])
            ->emptyStateHeading('Nog geen berichten verstuurd')
            ->emptyStateDescription('Het eerste automatische bericht vertrekt zodra een speeldag berekend is; een clubbericht maak je met de knop rechtsboven.')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Wanneer')
                    ->dateTime('d-m-Y H:i')
                    ->sortable(),
                TextColumn::make('topic')
                    ->label('Onderwerp')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => config("push.topics.{$state}.label", $state)),
                TextColumn::make('title')
                    ->label('Titel')
                    ->description(fn (PushMessage $record): string => $record->body)
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (PushMessage $record): string => match (true) {
                        $record->error !== null => 'Mislukt',
                        $record->sent_at === null => 'In de wachtrij',
                        default => sprintf('%d verstuurd', $record->sent_count),
                    })
                    ->description(fn (PushMessage $record): ?string => match (true) {
                        $record->error !== null => $record->error,
                        $record->sent_at === null => null,
                        default => implode(' · ', array_filter([
                            $record->expired_count > 0 ? "{$record->expired_count} dood opgeruimd" : null,
                            $record->failed_count > 0 ? "{$record->failed_count} niet afgeleverd" : null,
                        ])) ?: null,
                    })
                    ->color(fn (PushMessage $record): string => match (true) {
                        $record->error !== null => 'danger',
                        $record->sent_at === null => 'warning',
                        $record->failed_count > 0 => 'warning',
                        default => 'success',
                    })
                    ->badge(),
                TextColumn::make('user.name')
                    ->label('Door')
                    ->placeholder('Automatisch'),
            ]);
    }
}

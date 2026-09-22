<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Filament\Resources\Rounds\RoundResource;
use App\Models\Round;
use App\Services\Push\RoundNotifier;
use App\Services\Push\WebPushSender;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewRound extends ViewRecord
{
    protected static string $resource = RoundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            /*
             * Het automatische intraclub-bericht nog eens (of alsnog) sturen: na
             * een correctie, of als het automatische bericht niet vertrok omdat de
             * speeldag te oud was of de sleutels er nog niet stonden. Dit gaat
             * langs alle remmen van RoundNotifier heen, vandaar de bevestiging.
             */
            Action::make('push')
                ->label('Pushbericht versturen')
                ->icon(Heroicon::OutlinedBellAlert)
                ->color('gray')
                ->visible(fn (Round $record): bool => $record->is_calculated)
                ->disabled(fn (): bool => ! WebPushSender::isConfigured())
                ->tooltip(fn (): ?string => WebPushSender::isConfigured() ? null : 'Push staat uit: geen VAPID-sleutels in .env.')
                ->requiresConfirmation()
                ->modalHeading(fn (Round $record): string => "Pushbericht over speeldag {$record->number} versturen")
                ->modalDescription(fn (Round $record): string => ($record->push_notified_at === null
                    ? 'Over deze speeldag vertrok nog geen bericht. '
                    : 'Over deze speeldag vertrok al een bericht op '.$record->push_notified_at->format('d-m-Y H:i').'. ')
                    .'Iedereen met een abonnement op Intraclub krijgt "Intraclub: speeldag '.$record->number.' berekend" met een link naar de uitslag.')
                ->modalSubmitActionLabel('Verstuur')
                ->action(function (Round $record, RoundNotifier $notifier): void {
                    $notifier->notify($record, auth()->user());

                    Notification::make()
                        ->title('Bericht staat klaar')
                        ->body('Het vertrekt binnen de minuut; het logboek onder Pushberichten toont daarna naar hoeveel toestellen.')
                        ->success()
                        ->send();
                }),
        ];
    }
}

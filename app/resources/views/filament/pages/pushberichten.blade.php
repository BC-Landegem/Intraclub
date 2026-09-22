<x-filament-panels::page>
    @unless ($this::isConfigured())
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning" heading="Push staat uit">
            Er staan geen VAPID-sleutels in de <code>.env</code> van deze omgeving. Abonnementen worden wel aangenomen,
            maar er vertrekt niets. Genereer een paar met <code>php artisan push:vapid</code> en zet daarna de taak
            <code>optimize</code> (zie DEPLOY.md).
        </x-filament::section>
    @endunless

    @if (($stuck = $this->getStuckCount()) > 0)
        <x-filament::section icon="heroicon-o-clock" icon-color="danger" heading="De wachtrij loopt niet leeg">
            {{ $stuck }} {{ $stuck === 1 ? 'bericht staat' : 'berichten staan' }} al langer dan een paar minuten te wachten.
            Kijk na of de cron voor <code>schedule:run</code> in DirectAdmin nog draait (DEPLOY.md, "Cron").
        </x-filament::section>
    @endif

    @php($topics = $this->getTopicCounts())
    <div style="display: flex; flex-wrap: wrap; gap: 0.25rem 1.5rem; font-size: 0.875rem; opacity: 0.7; font-variant-numeric: tabular-nums;">
        <span>{{ $this->getSubscriptionCount() }} {{ $this->getSubscriptionCount() === 1 ? 'toestel' : 'toestellen' }} geabonneerd</span>
        @foreach ($topics as $topic)
            <span>{{ $topic['label'] }}: {{ $topic['count'] }}</span>
        @endforeach
        <span>Een toestel kan op beide onderwerpen intekenen; wie zich in de browser afmeldt in plaats van op de site, verdwijnt pas bij het eerstvolgende bericht.</span>
    </div>

    {{ $this->table }}
</x-filament-panels::page>

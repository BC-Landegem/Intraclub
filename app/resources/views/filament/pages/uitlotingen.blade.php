<x-filament-panels::page>
    <div style="max-width: 20rem;">
        <x-filament::input.wrapper>
            <x-filament::input.select wire:model.live="seasonId">
                @foreach ($this->getSeasonOptions() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    {{ $this->table }}

    @php($summary = $this->getSummary())

    <div style="display: flex; flex-wrap: wrap; gap: 0.25rem 1.5rem; font-size: 0.875rem; opacity: 0.7;">
        <span style="font-variant-numeric: tabular-nums;">
            {{ $summary['players'] }} spelers &middot;
            {{ $summary['drawn_out'] }} uitlotingen &middot;
            {{ $summary['rounds'] }} speeldagen
        </span>
        <span>
            De laatste kolom rekent tot speeldag {{ $this->nextRoundNumber() }}, de volgende; het schild markeert
            wie er dan niet opnieuw uitgeloot mag worden.
        </span>
    </div>
</x-filament-panels::page>

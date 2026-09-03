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

    @php($ranking = $this->getRanking())

    <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(24rem, 1fr));">
        @foreach ($this->getCategoryLabels() as $category => $label)
            <x-filament::section :heading="$label" compact>
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr style="text-align: left; font-size: 0.75rem; text-transform: uppercase; opacity: 0.6;">
                            <th style="padding: 0.25rem 0.5rem 0.5rem 0; width: 2.5rem;">#</th>
                            <th style="padding: 0.25rem 0.5rem 0.5rem 0;">Speler</th>
                            <th style="padding: 0.25rem 0.5rem 0.5rem 0; text-align: right;">Gem.</th>
                            <th style="padding: 0.25rem 0 0.5rem 0; text-align: right; width: 3.5rem;">+/-</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ranking['categories'][$category] as $entry)
                            <tr style="border-top: 1px solid rgb(128 128 128 / 0.15);">
                                <td style="padding: 0.375rem 0.5rem 0.375rem 0; font-variant-numeric: tabular-nums; opacity: 0.6;">{{ $entry['rank'] }}</td>
                                <td style="padding: 0.375rem 0.5rem 0.375rem 0;">{{ $entry['full_name'] }}</td>
                                <td style="padding: 0.375rem 0.5rem 0.375rem 0; text-align: right; font-variant-numeric: tabular-nums;">{{ $entry['is_active'] ? number_format($entry['average'], 2, ',', '') : $entry['average_text'] }}</td>
                                <td style="padding: 0.375rem 0 0.375rem 0; text-align: right; font-variant-numeric: tabular-nums;">
                                    @if ($entry['difference'] > 0)
                                        <span style="color: rgb(22 163 74);">&#9650; {{ $entry['difference'] }}</span>
                                    @elseif ($entry['difference'] < 0)
                                        <span style="color: rgb(220 38 38);">&#9660; {{ abs($entry['difference']) }}</span>
                                    @else
                                        <span style="opacity: 0.4;">&ndash;</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" style="padding: 1rem 0; text-align: center; opacity: 0.6;">Geen spelers in deze categorie.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>

import { Component, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';

interface RankingEntry {
  id: number;
  firstName: string;
  name: string;
  average: number;
  rank: number;
  difference: number;
}

interface RankingResponse {
  seasonId: number;
  general: RankingEntry[];
  women: RankingEntry[];
  veterans: RankingEntry[];
  recreants: RankingEntry[];
}

interface LatestRound {
  number: number;
  date: string;
}

type Category = 'general' | 'women' | 'veterans' | 'recreants';

/**
 * The current standings, for players who walk up to the tablet between matches.
 * The average is a score out of 21, so each row carries a bar showing where the
 * player sits between the lowest and highest average — readable at a glance from
 * a few metres away.
 */
@Component({
  selector: 'app-standings',
  imports: [],
  templateUrl: './standings.html',
  styleUrl: './standings.css',
})
export class Standings {
  private readonly http = inject(HttpClient);

  protected readonly categories: { key: Category; label: string }[] = [
    { key: 'general', label: 'Algemeen' },
    { key: 'women', label: 'Dames' },
    { key: 'veterans', label: 'Veteranen' },
    { key: 'recreants', label: 'Recreanten' },
  ];

  protected readonly category = signal<Category>('general');
  protected readonly searchTerm = signal('');
  protected readonly loading = signal(true);
  protected readonly errorMessage = signal('');

  private readonly ranking = signal<RankingResponse | null>(null);
  protected readonly latestRound = signal<LatestRound | null>(null);

  protected readonly entries = computed(() => this.ranking()?.[this.category()] ?? []);

  /** Lowest and highest average in this category, for scaling the bars. */
  protected readonly range = computed(() => {
    const averages = this.entries().map((entry) => entry.average);

    return averages.length === 0
      ? { low: 0, high: 1 }
      : { low: Math.min(...averages), high: Math.max(...averages) };
  });

  protected readonly visibleEntries = computed(() => {
    const term = this.searchTerm().trim().toLowerCase();
    const entries = this.entries();

    return term === ''
      ? entries
      : entries.filter((entry) => `${entry.firstName} ${entry.name}`.toLowerCase().includes(term));
  });

  constructor() {
    void this.load();
  }

  /** How far along the bar this player sits, as a percentage. */
  protected barWidth(entry: RankingEntry): number {
    const { low, high } = this.range();
    const span = high - low;

    return span <= 0 ? 100 : 6 + ((entry.average - low) / span) * 94;
  }

  protected formatAverage(average: number): string {
    return average.toFixed(2).replace('.', ',');
  }

  private async load(): Promise<void> {
    this.loading.set(true);
    try {
      const [ranking, round] = await Promise.all([
        firstValueFrom(this.http.get<RankingResponse>('/api/rankings')),
        firstValueFrom(this.http.get<LatestRound | null>('/api/rounds/latestCalculated')),
      ]);
      this.ranking.set(ranking);
      this.latestRound.set(round);
    } catch {
      this.errorMessage.set('De tussenstand kon niet geladen worden.');
    } finally {
      this.loading.set(false);
    }
  }
}

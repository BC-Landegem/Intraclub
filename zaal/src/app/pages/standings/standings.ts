import { Component, computed, inject, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { firstValueFrom } from 'rxjs';

type Category = 'general' | 'women' | 'veterans' | 'recreants';

interface RankingEntry {
  id: number;
  first_name: string;
  last_name: string;
  full_name: string;
  average: number;
  rank: number;
  difference: number;
}

interface RankingRound {
  id: number;
  number: number;
  date: string;
}

/**
 * Vorm van /api/rankings. `meta.round` is de speeldag waarop de stand staat, of
 * null wanneer het seizoen nog geen berekende speeldag heeft — dan staat het
 * klassement op de basispunten.
 */
interface RankingResponse {
  data: Record<Category, RankingEntry[]>;
  meta: {
    season: { id: number; name: string; points_per_set: number } | null;
    round: RankingRound | null;
  };
}

/**
 * The current standings, for players who walk up to the tablet between matches.
 * The average is a score out of 15 or 21 depending on the season, so each row
 * carries a bar showing where the player sits between the lowest and highest
 * average — readable at a glance from a few metres away.
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
  protected readonly latestRound = computed(() => this.ranking()?.meta.round ?? null);

  protected readonly entries = computed(() => this.ranking()?.data[this.category()] ?? []);

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
      : entries.filter((entry) => entry.full_name.toLowerCase().includes(term));
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
      // Eén call in plaats van twee: /api/rankings geeft de vier categorieën én,
      // in meta.round, na welke speeldag de stand geldt. Daarvoor was
      // /api/rounds/latestCalculated nodig; die route bestaat niet meer.
      this.ranking.set(await firstValueFrom(this.http.get<RankingResponse>('/api/rankings')));
    } catch {
      this.errorMessage.set('De tussenstand kon niet geladen worden. Probeer opnieuw.');
    } finally {
      this.loading.set(false);
    }
  }
}

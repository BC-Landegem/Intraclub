import { Component, computed, inject, input, output, signal } from '@angular/core';
import { FillCandidate, PlayerSummary } from '../../core/models';
import { ZaalApi } from '../../core/zaal-api';

/**
 * Composes a match. Used in two ways:
 * - filling up players who were drawn out (they are fixed in the match, you only
 *   pick the volunteers who want to join them);
 * - putting together a free match from scratch.
 *
 * The app never picks anyone: volunteering is voluntary, the hall decides.
 */
@Component({
  selector: 'app-compose-match',
  imports: [],
  templateUrl: './compose-match.html',
  styleUrl: './compose-match.css',
})
export class ComposeMatch {
  private readonly api = inject(ZaalApi);

  /** Players who are already in the match and cannot be removed. */
  readonly fixedPlayers = input<PlayerSummary[]>([]);

  readonly closed = output<void>();

  protected readonly candidates = signal<FillCandidate[]>([]);
  protected readonly others = signal<FillCandidate[]>([]);
  protected readonly chosen = signal<FillCandidate[]>([]);
  protected readonly searchTerm = signal('');
  protected readonly errorMessage = signal('');
  protected readonly busy = signal(false);

  protected readonly slotsLeft = computed(
    () => 4 - this.fixedPlayers().length - this.chosen().length,
  );

  protected readonly isReady = computed(() => this.slotsLeft() === 0);

  /** Present players who are not drawn out, minus whoever is already picked. */
  protected readonly availableCandidates = computed(() => {
    const taken = new Set([
      ...this.fixedPlayers().map((player) => player.id),
      ...this.chosen().map((player) => player.id),
    ]);

    return this.candidates().filter((player) => !taken.has(player.id));
  });

  /** Members who are not marked present yet — someone who just walked in. */
  protected readonly lateArrivals = computed(() => {
    const term = this.searchTerm().trim().toLowerCase();
    const taken = new Set(this.chosen().map((player) => player.id));
    const available = this.others().filter((player) => !taken.has(player.id));

    return term === ''
      ? available.slice(0, 8)
      : available.filter((player) => player.fullName.toLowerCase().includes(term)).slice(0, 12);
  });

  constructor() {
    void this.loadCandidates();
  }

  protected choose(player: FillCandidate): void {
    if (this.slotsLeft() > 0) {
      this.chosen.update((current) => [...current, player]);
    }
  }

  protected remove(player: FillCandidate): void {
    this.chosen.update((current) => current.filter((chosen) => chosen.id !== player.id));
  }

  protected async confirm(): Promise<void> {
    this.errorMessage.set('');
    this.busy.set(true);
    try {
      const playerIds = [
        ...this.fixedPlayers().map((player) => player.id),
        ...this.chosen().map((player) => player.id),
      ];
      await this.api.confirmGame(playerIds);

      if (this.api.errorMessage() === '') {
        this.closed.emit();
      } else {
        this.errorMessage.set(this.api.errorMessage());
      }
    } finally {
      this.busy.set(false);
    }
  }

  private async loadCandidates(): Promise<void> {
    try {
      const candidates = await this.api.fillCandidates();
      this.candidates.set(candidates.present);
      this.others.set(candidates.others);
    } catch {
      this.errorMessage.set('Kon de spelerslijst niet laden.');
    }
  }
}

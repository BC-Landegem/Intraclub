import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Auth } from '../../core/auth';
import { Game, GameScores, PlayerSummary } from '../../core/models';
import { ZaalApi } from '../../core/zaal-api';
import { AddPlayer } from '../add-player/add-player';

type Tab = 'attendance' | 'matches';

@Component({
  selector: 'app-zaal',
  imports: [AddPlayer],
  templateUrl: './zaal.html',
  styleUrl: './zaal.css',
})
export class Zaal {
  private readonly auth = inject(Auth);
  private readonly router = inject(Router);

  protected readonly api = inject(ZaalApi);

  protected readonly tab = signal<Tab>('attendance');
  protected readonly showAddPlayer = signal(false);
  protected readonly searchTerm = signal('');

  /** Proposed games from the last draw that still need confirming. */
  protected readonly proposals = signal<PlayerSummary[][]>([]);

  protected readonly filteredPlayers = computed(() => {
    const term = this.searchTerm().trim().toLowerCase();
    const players = this.api.players();

    return term === '' ? players : players.filter((player) => player.fullName.toLowerCase().includes(term));
  });

  constructor() {
    void this.api.loadCurrentRound();
  }

  protected async toggleAttendance(playerId: number, present: boolean): Promise<void> {
    await this.api.setAttendance(playerId, present);
  }

  protected async draw(): Promise<void> {
    await this.api.drawRound();
    this.proposals.set(this.api.proposedGames());
    this.tab.set('matches');
  }

  protected async confirmProposal(index: number): Promise<void> {
    const proposal = this.proposals()[index];
    if (proposal === undefined) {
      return;
    }

    await this.api.confirmGame(proposal.map((player) => player.id));
    if (this.api.errorMessage() === '') {
      this.proposals.update((current) => current.filter((_, position) => position !== index));
    }
  }

  protected async confirmAllProposals(): Promise<void> {
    for (const proposal of this.proposals()) {
      await this.api.confirmGame(proposal.map((player) => player.id));
      if (this.api.errorMessage() !== '') {
        return;
      }
    }
    this.proposals.set([]);
  }

  protected async saveScores(event: Event, game: Game, formElement: HTMLFormElement): Promise<void> {
    event.preventDefault();
    const fields = new FormData(formElement);
    const read = (name: string): number | null => {
      const value = fields.get(name);

      return value === null || value === '' ? null : Number(value);
    };

    const scores: GameScores = {
      set1_home: read('set1_home'),
      set1_away: read('set1_away'),
      set2_home: read('set2_home'),
      set2_away: read('set2_away'),
      set3_home: read('set3_home'),
      set3_away: read('set3_away'),
    };

    await this.api.saveScores(game.id, scores);
  }

  protected async signOut(): Promise<void> {
    await this.auth.logout();
    await this.router.navigate(['/login']);
  }
}

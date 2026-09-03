import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Auth } from '../../core/auth';
import { PlayerSummary, RoundPlayer } from '../../core/models';
import { ZaalApi } from '../../core/zaal-api';
import { AddPlayer } from '../add-player/add-player';
import { ComposeMatch } from '../compose-match/compose-match';
import { Standings } from '../standings/standings';
import { MatchScores } from './match-scores/match-scores';

type Tab = 'attendance' | 'matches' | 'standings';

@Component({
  selector: 'app-zaal',
  imports: [AddPlayer, ComposeMatch, MatchScores, Standings],
  templateUrl: './zaal.html',
  styleUrl: './zaal.css',
})
export class Zaal {
  private readonly auth = inject(Auth);
  private readonly router = inject(Router);

  protected readonly api = inject(ZaalApi);

  protected readonly tab = signal<Tab>('attendance');
  protected readonly showAddPlayer = signal(false);

  /**
   * Players that are fixed in the match being composed. Empty means a free match;
   * filled means the drawn-out players are being helped to a foursome.
   */
  protected readonly composeFor = signal<PlayerSummary[] | null>(null);
  protected readonly searchTerm = signal('');

  /** Briefly true after a check-in, so the counter can acknowledge it. */
  protected readonly countPulse = signal(false);

  /** Proposed games from the last draw that still need confirming. */
  protected readonly proposals = signal<PlayerSummary[][]>([]);

  protected readonly filteredPlayers = computed(() => {
    const term = this.searchTerm().trim().toLowerCase();
    const players = this.api.players();

    return term === ''
      ? players
      : players.filter((player) => player.fullName.toLowerCase().includes(term));
  });

  constructor() {
    void this.api.loadCurrentRound();
  }

  /**
   * Checking in is the one moment in this app that is about the player, not the
   * administration. The fill sweeps from where the finger landed, so the tile
   * answers the tap itself; the counter then acknowledges the new arrival.
   */
  protected async checkIn(event: MouseEvent, player: RoundPlayer): Promise<void> {
    const tile = event.currentTarget as HTMLElement;
    const bounds = tile.getBoundingClientRect();
    tile.style.setProperty('--tap-x', `${event.clientX - bounds.left}px`);
    tile.style.setProperty('--tap-y', `${event.clientY - bounds.top}px`);

    const arriving = !player.present;
    await this.api.setAttendance(player.id, arriving);

    if (arriving && this.api.errorMessage() === '') {
      this.countPulse.set(true);
      setTimeout(() => this.countPulse.set(false), 600);
    }
  }

  protected async startToday(): Promise<void> {
    await this.api.startToday();
  }

  protected async openRound(roundId: number): Promise<void> {
    await this.api.openRound(roundId);
  }

  protected async draw(): Promise<void> {
    await this.redraw();
    this.tab.set('matches');
  }

  /**
   * Draws again. Matches that were already confirmed keep their players, so a
   * redraw only reshuffles whoever is still waiting.
   */
  protected async redraw(): Promise<void> {
    await this.api.drawRound();
    this.proposals.set(this.api.proposedGames());
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

  protected startFillingDrawnOut(): void {
    this.composeFor.set(this.api.drawnOut());
  }

  protected startFreeMatch(): void {
    this.composeFor.set([]);
  }

  protected async closeCompose(): Promise<void> {
    this.composeFor.set(null);
    await this.api.loadCurrentRound();
  }

  protected async signOut(): Promise<void> {
    await this.auth.logout();
    await this.router.navigate(['/login']);
  }
}

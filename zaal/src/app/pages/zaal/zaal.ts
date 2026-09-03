import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { Auth } from '../../core/auth';
import { Game, PlayerSummary, RoundPlayer } from '../../core/models';
import { ZaalApi } from '../../core/zaal-api';
import { AddPlayer } from '../add-player/add-player';
import { ComposeMatch } from '../compose-match/compose-match';
import { Standings } from '../standings/standings';
import { MatchRecap, RecapMode } from './match-recap/match-recap';
import { PlayerFinder } from './player-finder/player-finder';
import { Results } from './results/results';
import { ScoreEntry } from './score-entry/score-entry';

/** Waar de tablet op staat. `kiosk` is de rusttoestand: "wie heeft er gespeeld?". */
type View = 'kiosk' | 'results' | 'standings' | 'admin';

/**
 * De stap binnen de kiosk. `finder` is de rusttoestand; de andere twee horen bij
 * één wedstrijd en dragen zelf waar "Klaar" naartoe terugkeert.
 */
type Step =
  | { kind: 'finder' }
  | { kind: 'entry'; me: PlayerSummary; gameId: number }
  | { kind: 'recap'; me: PlayerSummary | null; gameId: number; mode: RecapMode; back: View };

/** Na zoveel stilte staat de tablet weer op de beginvraag. */
const IDLE_MS = 120_000;

@Component({
  selector: 'app-zaal',
  imports: [AddPlayer, ComposeMatch, MatchRecap, PlayerFinder, Results, ScoreEntry, Standings],
  templateUrl: './zaal.html',
  styleUrl: './zaal.css',
  host: {
    '(pointerdown)': 'keepAwake()',
    '(keydown)': 'keepAwake()',
  },
})
export class Zaal {
  private readonly auth = inject(Auth);
  private readonly router = inject(Router);

  protected readonly api = inject(ZaalApi);

  protected readonly view = signal<View>('kiosk');
  protected readonly step = signal<Step>({ kind: 'finder' });

  protected readonly adminTab = signal<'attendance' | 'games'>('attendance');
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

  private idleTimer: ReturnType<typeof setTimeout> | undefined;

  /** De stap die het scherm overneemt, of null wanneer de kiosk in rust is. */
  protected readonly activeStep = computed(() => {
    const step = this.step();

    return step.kind === 'finder' ? null : step;
  });

  /** De wedstrijd van de actieve stap, altijd zoals de server hem nú kent. */
  protected readonly activeGame = computed(() => {
    const step = this.activeStep();

    return step === null ? null : (this.api.games().find((game) => game.id === step.gameId) ?? null);
  });

  /** Het nummer waaronder die wedstrijd op de speeldag staat, 1-gebaseerd. */
  protected readonly activeNumber = computed(() => {
    const game = this.activeGame();

    return game === null ? 0 : this.api.games().indexOf(game) + 1;
  });

  protected readonly filteredPlayers = computed(() => {
    const term = this.searchTerm().trim().toLowerCase();
    const players = this.api.players();

    return term === ''
      ? players
      : players.filter((player) => player.fullName.toLowerCase().includes(term));
  });

  /** De afwerklijst voor de organisator: wat nog geen score heeft, bovenaan. */
  protected readonly progress = computed(() =>
    this.api
      .games()
      .map((game, index) => ({ game, number: index + 1 }))
      .sort((one, other) => Number(one.game.isComplete) - Number(other.game.isComplete)),
  );

  constructor() {
    void this.api.loadCurrentRound();
  }

  // ---------------------------------------------------------------- kioskpad

  /**
   * Iemand heeft zijn naam aangetikt. Staat de score er al, dan is dit een
   * leesscherm; anders begint de invoer. In beide gevallen komt hij in zijn éigen
   * wedstrijd terecht, dus die van iemand anders kan hij niet openen.
   */
  protected onPicked(player: RoundPlayer): void {
    const game = this.api.games().find((item) => item.players.some((one) => one.id === player.id));

    if (game === undefined) {
      return;
    }

    this.step.set(
      game.isComplete
        ? { kind: 'recap', me: player, gameId: game.id, mode: 'read', back: 'kiosk' }
        : { kind: 'entry', me: player, gameId: game.id },
    );
    this.keepAwake();
  }

  protected onEntryDone(): void {
    const step = this.step();

    if (step.kind === 'entry') {
      this.step.set({
        kind: 'recap',
        me: step.me,
        gameId: step.gameId,
        mode: 'confirm',
        back: 'kiosk',
      });
    }
  }

  /** Uit de uitslagen: je bekijkt een wedstrijd waar je zelf niets aan doet. */
  protected onPeek(game: Game): void {
    this.step.set({ kind: 'recap', me: null, gameId: game.id, mode: 'peek', back: 'results' });
  }

  protected onRecapEdit(): void {
    const step = this.step();

    if (step.kind === 'recap' && step.me !== null) {
      this.step.set({ kind: 'entry', me: step.me, gameId: step.gameId });
    }
  }

  /** Terug naar waar deze stap vandaan kwam. */
  protected closeStep(): void {
    const step = this.step();

    this.view.set(step.kind === 'recap' ? step.back : 'kiosk');
    this.step.set({ kind: 'finder' });
  }

  /** De rusttoestand: de beginvraag, niets geopend. */
  protected goHome(): void {
    this.step.set({ kind: 'finder' });
    this.view.set('kiosk');
    this.searchTerm.set('');
  }

  protected show(view: View): void {
    this.step.set({ kind: 'finder' });
    this.view.set(view);
  }

  /**
   * Elke aanraking schuift de terugvalklok op. Staat de tablet al op de
   * beginvraag, dan is er niets om naar terug te vallen en loopt er geen klok —
   * anders zou het scherm zich om de twee minuten voor niets verversen.
   */
  protected keepAwake(): void {
    clearTimeout(this.idleTimer);

    if (this.view() === 'kiosk' && this.step().kind === 'finder') {
      return;
    }

    this.idleTimer = setTimeout(() => this.goHome(), IDLE_MS);
  }

  // -------------------------------------------------------------- organisator

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
    this.adminTab.set('games');
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

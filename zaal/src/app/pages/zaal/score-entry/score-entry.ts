import { Component, computed, inject, input, output, signal } from '@angular/core';
import { Game, GameScores, PlayerSummary } from '../../../core/models';
import { SetOption, directWins, extensionWins } from '../../../core/score-rules';
import { ZaalApi } from '../../../core/zaal-api';

/** Eén set op het scherm: de twee duo's, de stand, en wat er nog moet gebeuren. */
interface SetRow {
  index: number;
  number: number;
  home: string;
  away: string;
  /** De stand zoals de server hem kent, of null zolang de set niet ingevuld is. */
  score: { home: number; away: number } | null;
}

/**
 * De drie setstanden ingeven: tik de winnaar, tik de stand.
 *
 * Er is geen bewaarknop en geen invoervak. Elke tik gaat meteen naar de server,
 * dus wie halverwege weggeroepen wordt verliest niets, en er bestaat geen verschil
 * tussen "ingetikt" en "bewaard" waarover je je kan vergissen.
 *
 * Er is ook geen validatie meer. Elke knop die de speler te zien krijgt is per
 * constructie een legale setstand (zie score-rules.ts), dus een onmogelijke stand
 * kan niet ingegeven worden en hoeft niet gemeld te worden.
 */
@Component({
  selector: 'app-score-entry',
  templateUrl: './score-entry.html',
  styleUrl: './score-entry.css',
})
export class ScoreEntry {
  protected readonly api = inject(ZaalApi);

  readonly game = input.required<Game>();
  readonly me = input.required<PlayerSummary>();

  /** Het setmaximum van het seizoen, en het plafond op een verlenging. */
  readonly target = input.required<number>();
  readonly cap = input.required<number>();

  /** Alle drie de sets staan er; de wedstrijd is af. */
  readonly done = output<void>();
  /** Dit is niet mijn wedstrijd — terug naar de namen. */
  readonly leave = output<void>();

  /** De set die de speler bewust opnieuw ingeeft; anders volgt de app de sets. */
  private readonly editing = signal<number | null>(null);

  /** Het duo dat de actieve set won, zolang de stand nog niet gekozen is. */
  protected readonly pendingWinner = signal<0 | 1 | null>(null);

  /** De verlengingen staan dicht tot iemand zegt dat de set er een was. */
  protected readonly showExtensions = signal(false);

  protected readonly rows = computed<SetRow[]>(() =>
    this.game().sets.map((set, index) => ({
      index,
      number: set.number,
      home: this.pairName(set.home.players),
      away: this.pairName(set.away.players),
      score:
        set.home.score === null || set.away.score === null
          ? null
          : { home: set.home.score, away: set.away.score },
    })),
  );

  /** Welke set nu aan de beurt is: de gekozen, of de eerste die nog leeg staat. */
  protected readonly activeIndex = computed(() => {
    const chosen = this.editing();
    if (chosen !== null) {
      return chosen;
    }

    const waiting = this.rows().find((row) => row.score === null);

    return waiting?.index ?? null;
  });

  protected readonly activeRow = computed(() => {
    const index = this.activeIndex();

    return index === null ? null : (this.rows()[index] ?? null);
  });

  /** De namen van de vier, met die van de speler zelf vooraan. */
  protected readonly foursome = computed(() => {
    const mine = this.me().id;
    const others = this.game()
      .players.filter((player) => player.id !== mine)
      .map((player) => this.api.nameOf(player));

    return { me: this.api.nameOf(this.me()), others };
  });

  /** Het duo dat de set verloor — dat is het getal dat we nog nodig hebben. */
  protected readonly losingPair = computed(() => {
    const row = this.activeRow();
    const side = this.pendingWinner();

    return row === null || side === null ? '' : side === 0 ? row.away : row.home;
  });

  protected readonly direct = computed(() => directWins(this.target(), this.cap()));
  protected readonly extensions = computed(() => extensionWins(this.target(), this.cap()));

  protected pickWinner(side: 0 | 1): void {
    this.pendingWinner.set(side);
    this.showExtensions.set(false);
  }

  /**
   * De stand vastleggen en meteen bewaren.
   *
   * De lokale keuze wordt vrijgegeven vóór de server antwoordt, want de volgende
   * set is onmiddellijk aan de beurt — ZaalApi heeft de cijfers dan al in de
   * toestand gezet. Alleen op de laatste set wachten we het antwoord af: dán
   * verschijnt het bevestigingsscherm, en dat hoort de waarheid te vertellen.
   */
  protected async pickScore(option: SetOption): Promise<void> {
    const index = this.activeIndex();
    const side = this.pendingWinner();

    if (index === null || side === null) {
      return;
    }

    const scores = this.scoresWith(
      index,
      side === 0 ? option.winner : option.loser,
      side === 0 ? option.loser : option.winner,
    );

    this.pendingWinner.set(null);
    this.editing.set(null);
    this.showExtensions.set(false);

    const finished = Object.values(scores).every((value) => value !== null);

    await this.api.saveScores(this.game().id, scores);

    if (finished && this.api.errorMessage() === '') {
      this.done.emit();
    }
  }

  /** Twee namen die vanavond niet met elkaar te verwarren zijn. */
  private pairName(players: PlayerSummary[]): string {
    return players.map((player) => this.api.nameOf(player)).join(' + ');
  }

  /** Een set opnieuw ingeven. De oude stand blijft staan tot er een nieuwe is. */
  protected redo(index: number): void {
    this.editing.set(index);
    this.pendingWinner.set(null);
    this.showExtensions.set(false);
  }

  /** De zes velden zoals de API ze verwacht, met één set vervangen. */
  private scoresWith(index: number, home: number, away: number): GameScores {
    const scores: GameScores = {};

    this.game().sets.forEach((set, position) => {
      scores[`set${set.number}_home`] = position === index ? home : set.home.score;
      scores[`set${set.number}_away`] = position === index ? away : set.away.score;
    });

    return scores;
  }
}

import { Component, computed, inject, input, output, signal } from '@angular/core';
import { Game, GameScores, PlayerSummary } from '../../../core/models';
import { SetOption, directWins, extensionWins } from '../../../core/score-rules';
import { ZaalApi } from '../../../core/zaal-api';
import { signed } from '../match-recap/match-recap';

/** Eén set op het scherm: de twee duo's, de stand, en wat er nog moet gebeuren. */
interface SetRow {
  index: number;
  number: number;
  home: string;
  away: string;
  /** De stand zoals de server hem kent, of null zolang de set niet ingevuld is. */
  score: { home: number; away: number } | null;
  /**
   * De stand waarop de twee duo's aan deze set begonnen, in dezelfde volgorde als
   * de namen erboven. Null zodra de set ingevuld is. Hier is dit geen speelinfo
   * meer maar een controlemiddel: klopt het cijfer dat ik intik met de set die we
   * net gespeeld hebben?
   */
  start: string | null;
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
 *
 * `me` mag leeg zijn. Wie via zijn naam binnenkwam ziet die in de kop staan; wie
 * dit scherm vanaf het bord van de avond opent komt er zonder. Dat scheelt geen
 * rechten — invullen mocht sowieso door elk van de vier — enkel de aanspreking.
 */
@Component({
  selector: 'app-score-entry',
  templateUrl: './score-entry.html',
  styleUrl: './score-entry.css',
})
export class ScoreEntry {
  protected readonly api = inject(ZaalApi);

  readonly game = input.required<Game>();

  /** Wie er staat, of null wanneer dit scherm vanaf het bord geopend is. */
  readonly me = input<PlayerSummary | null>(null);

  /** Het nummer van de wedstrijd op de speeldag; de kop zonder naam leunt erop. */
  readonly number = input.required<number>();

  /** Het setmaximum van het seizoen, en het plafond op een verlenging. */
  readonly target = input.required<number>();
  readonly cap = input.required<number>();

  /** Alle drie de sets staan er; de wedstrijd is af. */
  readonly done = output<void>();
  /** Dit is niet mijn wedstrijd — terug naar waar dit scherm vandaan kwam. */
  readonly leave = output<void>();

  /** De set die de speler bewust opnieuw ingeeft; anders volgt de app de sets. */
  private readonly editing = signal<number | null>(null);

  /** Het duo dat de actieve set won, zolang de stand nog niet gekozen is. */
  protected readonly pendingWinner = signal<0 | 1 | null>(null);

  /** De verlengingen staan dicht tot iemand zegt dat de set er een was. */
  protected readonly showExtensions = signal(false);

  /**
   * De set waarvan de stand nu naar de server onderweg is, of null.
   *
   * Nodig omdat `ZaalApi.isSaving()` per wedstrijd werkt en de cijfers lokaal al
   * in de toestand staan vóór het verzoek vertrekt. Zonder dit zegt de setregel
   * "bewaard" op het moment dat er nog niets bewaard ís — en dat is precies wat
   * `applyScoresLocally` in zijn docblock afspreekt níét te doen.
   */
  private readonly savingIndex = signal<number | null>(null);

  /** Staat de stand van deze set nog op de lijn? */
  protected isSending(index: number): boolean {
    return this.savingIndex() === index && this.api.isSaving(this.game().id);
  }

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
      start:
        set.home.start === null || set.away.start === null
          ? null
          : `${signed(set.home.start)} tegen ${signed(set.away.start)}`,
    })),
  );

  /**
   * Welke set nu aan de beurt is: de gekozen, of de eerste die nog leeg staat.
   *
   * De doorloop is een gemak, geen volgorde die de app oplegt: elke setregel is
   * aantikbaar, dus wie met een andere paring begon zet zelf een andere set open.
   *
   * Is er nergens nog een gat, dan is er geen actieve set. Een volledige wedstrijd
   * opent dus dicht: drie afgeronde regels, en pas na een tik op "wijzig" valt er
   * iets te veranderen. Dat is wat dit scherm veilig maakt voor de tweede ingang,
   * die geen naam meer vraagt — een verdwaalde tik verandert niets.
   */
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

  /**
   * De namen van de vier. Staat er iemand bij naam, dan komt die vooraan en de
   * rest erachter; zonder naam is het gewoon het viertal, in de volgorde van de
   * loting.
   */
  protected readonly foursome = computed<{ me: string | null; others: string[] }>(() => {
    const mine = this.me();
    const players = this.game().players;

    if (mine === null) {
      return { me: null, others: players.map((player) => this.api.nameOf(player)) };
    }

    return {
      me: this.api.nameOf(mine),
      others: players
        .filter((player) => player.id !== mine.id)
        .map((player) => this.api.nameOf(player)),
    };
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
    this.savingIndex.set(index);

    const finished = Object.values(scores).every((value) => value !== null);

    try {
      await this.api.saveScores(this.game().id, scores);
    } finally {
      this.savingIndex.set(null);
    }

    if (finished && this.api.errorMessage() === '') {
      this.done.emit();
    }
  }

  /** Twee namen die vanavond niet met elkaar te verwarren zijn. */
  private pairName(players: PlayerSummary[]): string {
    return players.map((player) => this.api.nameOf(player)).join(' + ');
  }

  /**
   * Een set openzetten: een die al een stand heeft om te verbeteren, of een die
   * nog wacht om ze voor te kruipen. De oude stand blijft staan tot er een
   * nieuwe is, dus openzetten alleen wist niets.
   */
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

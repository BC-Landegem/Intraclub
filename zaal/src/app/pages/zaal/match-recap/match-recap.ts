import { Component, computed, inject, input, output } from '@angular/core';
import { SetScore, gameTally } from '../../../core/game-statistics';
import { Game, PlayerSummary } from '../../../core/models';
import { ZaalApi } from '../../../core/zaal-api';
import { timeOfDay } from '../player-finder/player-finder';

/**
 * Hetzelfde scherm in drie toonaarden:
 *
 * - `confirm`: net bewaard. Zegt niet alleen dát het bewaard is, maar wát er
 *   bewaard is, zodat de speler zijn invoer kan natrekken vóór hij weggaat.
 * - `read`: iemand van je viertal was je voor. Geen formulier meer, een uitslag
 *   om te lezen — met een aparte, bewuste knop om alsnog te wijzigen.
 * - `peek`: je bekijkt een wedstrijd van het bord van de avond. Dan ben je kijker,
 *   niet deelnemer: geen naam geaccentueerd en niets te wijzigen.
 *
 * En één toestand die daar dwars doorheen loopt: een set die nog gespeeld moet
 * worden heeft geen cijfers maar een startstand. Dat is waarvoor de vier spelers
 * hier vóór hun wedstrijd langskomen — de duo's roteren per set, dus hun voorsprong
 * verschilt per set en is niet uit het hoofd te doen.
 */
export type RecapMode = 'confirm' | 'read' | 'peek';

/** Eén setregel: ofwel de stand die er staat, ofwel de stand waarop ze begint. */
interface RecapRow {
  number: number;
  home: string;
  away: string;
  score: { home: number; away: number } | null;
  start: { home: string; away: string } | null;
}

@Component({
  selector: 'app-match-recap',
  templateUrl: './match-recap.html',
  styleUrl: './match-recap.css',
})
export class MatchRecap {
  private readonly api = inject(ZaalApi);

  readonly game = input.required<Game>();
  readonly mode = input.required<RecapMode>();

  /** De speler die hier staat, of null wanneer je enkel kijkt. */
  readonly me = input<PlayerSummary | null>(null);

  /** Het nummer waaronder de wedstrijd op de speeldag staat, 1-gebaseerd. */
  readonly number = input.required<number>();

  /** Het setmaximum van het seizoen; nodig om het gemiddelde te trimmen. */
  readonly target = input.required<number>();

  readonly done = output<void>();
  readonly edit = output<void>();

  protected readonly savedAt = computed(() => timeOfDay(this.game().savedAt));

  /** Staan alle drie de sets er? Enkel dan valt er iets te tellen. */
  protected readonly isComplete = computed(() => this.game().isComplete);

  /** Is er al één set ingevuld? Zo niet, dan is dit een vooruitblik. */
  protected readonly hasAnyScore = computed(() =>
    this.game().sets.some((set) => set.home.score !== null && set.away.score !== null),
  );

  protected readonly rows = computed<RecapRow[]>(() =>
    this.game().sets.map((set) => ({
      number: set.number,
      home: set.home.players.map((player) => this.api.nameOf(player)).join(' + '),
      away: set.away.players.map((player) => this.api.nameOf(player)).join(' + '),
      score:
        set.home.score === null || set.away.score === null
          ? null
          : { home: set.home.score, away: set.away.score },
      start:
        set.home.start === null || set.away.start === null
          ? null
          : { home: signed(set.home.start), away: signed(set.away.start) },
    })),
  );

  /**
   * Per speler het gemiddelde dat naar het klassement gaat, met de gewonnen sets
   * als context. Client-side gerekend uit de zes getallen die al op het scherm
   * staan — geen extra aanroep.
   */
  protected readonly tally = computed(() => {
    const sets = this.game().sets.map(
      (set) => [set.home.score ?? 0, set.away.score ?? 0] as SetScore,
    );
    const perPosition = gameTally(sets, this.target());
    const mine = this.me()?.id ?? null;

    return this.game().players.map((player, position) => ({
      player,
      isMe: player.id === mine,
      ...perPosition[position],
    }));
  });

  /** Onderscheidt de twee Barten van elkaar wanneer ze samen in een viertal zitten. */
  protected name(player: PlayerSummary): string {
    return this.api.nameOf(player);
  }

  /** Zelfde notatie als de tussenstand, zodat het herkenbaar hetzelfde getal is. */
  protected formatAverage(average: number): string {
    return average.toFixed(2).replace('.', ',');
  }
}

/**
 * Een startstand met een echt minteken (−, U+2212) in plaats van een koppelteken.
 * Op anderhalve meter afstand is dat het verschil tussen een getal en een streepje.
 *
 * Staat hier en niet in `core/`, om dezelfde reden als `timeOfDay` in de
 * player-finder: het is opmaak van één scherm, en het invulscherm leent het.
 */
export function signed(value: number): string {
  return value < 0 ? `−${Math.abs(value)}` : `${value}`;
}

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
 * - `peek`: je bekijkt een wedstrijd uit de uitslagen. Dan ben je kijker, niet
 *   deelnemer: geen naam geaccentueerd en niets te wijzigen.
 */
export type RecapMode = 'confirm' | 'read' | 'peek';

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

  protected readonly rows = computed(() =>
    this.game().sets.map((set) => ({
      number: set.number,
      home: set.home.players.map((player) => this.api.nameOf(player)).join(' + '),
      away: set.away.players.map((player) => this.api.nameOf(player)).join(' + '),
      homeScore: set.home.score,
      awayScore: set.away.score,
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

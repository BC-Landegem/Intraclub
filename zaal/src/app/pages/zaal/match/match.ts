import { Component, computed, inject, input } from '@angular/core';
import { Router } from '@angular/router';
import { PlayerSummary } from '../../../core/models';
import { ZaalApi } from '../../../core/zaal-api';
import { MatchRecap, RecapMode } from '../match-recap/match-recap';
import { ScoreEntry } from '../score-entry/score-entry';

/** Wat er met deze wedstrijd te doen valt; `entry` is het enige invulscherm. */
type Mode = RecapMode | 'entry';

/**
 * Eén wedstrijd, in de gedaante die de route vraagt: de score invullen, de
 * bevestiging erna, of de uitslag om te lezen.
 *
 * De gedaantes zijn routes, dus de terugknop doet hier wat hij overal doet.
 * Binnen de wedstrijd vervángen de stappen elkaar wél in de geschiedenis: heen
 * en weer tussen invullen en bevestigen blijft één stap, zodat "terug" altijd de
 * wedstrijd verlaat in plaats van erin te blijven ronddraaien.
 *
 * De speler in de URL is geen recht maar een aanspreking: hij bepaalt of je eigen
 * naam vooraan staat en oplicht in de telling. Invullen mocht altijd al door elk
 * van de vier, dus wie van het bord van de avond komt gaat rechtstreeks naar het
 * invulscherm — zonder naam, en zonder tussenstap om er een te kiezen.
 */
@Component({
  selector: 'app-match',
  imports: [MatchRecap, ScoreEntry],
  templateUrl: './match.html',
  styleUrl: './match.css',
})
export class Match {
  private readonly router = inject(Router);
  private readonly api = inject(ZaalApi);

  /** Uit de route: welke wedstrijd, wie ervoor staat, en in welke gedaante. */
  readonly gameId = input.required<string>();
  readonly playerId = input<string>();
  readonly mode = input.required<Mode>();

  /** De wedstrijd zoals de server hem nú kent, of null als hij niet meer bestaat. */
  protected readonly game = computed(
    () => this.api.games().find((game) => game.id === Number(this.gameId())) ?? null,
  );

  /** De speler die hier staat, of null wanneer er enkel gekeken wordt. */
  protected readonly me = computed<PlayerSummary | null>(() => {
    const id = Number(this.playerId());

    return this.game()?.players.find((player) => player.id === id) ?? null;
  });

  /** Het nummer waaronder die wedstrijd op de speeldag staat, 1-gebaseerd. */
  protected readonly number = computed(() => {
    const game = this.game();

    return game === null ? 0 : this.api.games().indexOf(game) + 1;
  });

  /** Het setmaximum van het seizoen, en het plafond op een verlenging. */
  protected readonly target = computed(() => this.api.round()?.pointsPerSet ?? 0);
  protected readonly cap = computed(() => this.api.round()?.maxScore ?? 0);

  /** Het invulscherm; het werkt met of zonder speler. */
  protected readonly isEntry = computed(() => this.mode() === 'entry');

  /** Alles wat geen bevestiging is, is een uitslag om te lezen. */
  protected readonly recapMode = computed<RecapMode>(() =>
    this.mode() === 'confirm' ? 'confirm' : 'recap',
  );

  /** De drie sets staan er: door naar de bevestiging. */
  protected onSaved(): void {
    void this.step('bewaard');
  }

  /** Toch nog iets aanpassen. */
  protected onEdit(): void {
    void this.step('score');
  }

  /**
   * Terug naar waar dit scherm vandaan kwam. Zonder speler in de URL kom je van
   * het bord van de avond; met een speler kom je van je eigen naam, en dan hoort
   * de tablet achteraf leeg te staan voor de volgende.
   */
  protected close(): void {
    void this.router.navigate([this.playerId() === undefined ? '/wedstrijden' : '/'], {
      replaceUrl: true,
    });
  }

  /**
   * Een stap binnen dezelfde wedstrijd, langs dezelfde ingang als waarlangs je
   * binnenkwam: met een speler in het pad blijft die erin staan, zonder blijft
   * hij weg. Zo verandert "Klaar" achteraf niet stiekem van bestemming.
   */
  private step(to: 'score' | 'bewaard'): Promise<boolean> {
    const player = this.playerId();
    const path =
      player === undefined
        ? ['/wedstrijd', this.gameId(), to]
        : ['/wedstrijd', this.gameId(), 'speler', player, to];

    return this.router.navigate(path, { replaceUrl: true });
  }
}

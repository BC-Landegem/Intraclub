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
 * bevestiging erna, de uitslag als deelnemer, of de uitslag als kijker.
 *
 * De vier gedaantes zijn vier routes, dus de terugknop doet hier wat hij overal
 * doet. Binnen de wedstrijd vervángen de stappen elkaar wél in de geschiedenis:
 * heen en weer tussen invullen en bevestigen blijft één stap, zodat "terug"
 * altijd de wedstrijd verlaat in plaats van erin te blijven ronddraaien.
 *
 * Waarom de speler in de URL staat en niet enkel de wedstrijd: hij bepaalt wat
 * je mag. Zonder speler ben je kijker en valt er niets in te vullen.
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

  /** Invullen kan enkel wanneer we weten wie er staat. */
  protected readonly isEntry = computed(() => this.mode() === 'entry' && this.me() !== null);

  /** Zonder speler is dit een kijkscherm, wat de route ook zegt. */
  protected readonly recapMode = computed<RecapMode>(() => {
    const mode = this.mode();

    if (this.me() === null) {
      return 'peek';
    }

    return mode === 'entry' ? 'read' : mode;
  });

  /** De drie sets staan er: door naar de bevestiging. */
  protected onSaved(): void {
    void this.step('bewaard');
  }

  /** Toch nog iets aanpassen. */
  protected onEdit(): void {
    void this.step('score');
  }

  /** Terug naar waar dit scherm vandaan kwam: het bord van de avond, of de zaal. */
  protected close(): void {
    void this.router.navigate([this.mode() === 'peek' ? '/wedstrijden' : '/'], { replaceUrl: true });
  }

  private step(to: 'score' | 'bewaard'): Promise<boolean> {
    return this.router.navigate(['/wedstrijd', this.gameId(), 'speler', this.playerId(), to], {
      replaceUrl: true,
    });
  }
}

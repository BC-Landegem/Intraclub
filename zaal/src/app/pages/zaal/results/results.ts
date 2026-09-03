import { Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ZaalApi } from '../../../core/zaal-api';

/**
 * Het bord van de avond: wie speelt tegen wie, en wat is het geworden.
 *
 * Dit was "Uitslagen", maar het scherm lijstte altijd al álle wedstrijden — ook
 * die zonder score. Zolang die rijen dood waren viel dat niet op; nu ze het pad
 * zijn naar de startstanden van een wedstrijd die nog moet, is "uitslagen" het
 * verkeerde woord voor twee derde van wat hier staat.
 *
 * Een lege wedstrijd is dus wél aantikbaar, en dat opent geen invoerpad: invullen
 * vereist een speler, en een speler komt uit het `speler/:playerId`-segment van de
 * route. Deze tegels linken naar `/wedstrijd/:id` zonder speler, en dan is er per
 * constructie enkel te kijken. De oude, grovere regel ("leeg = niet aantikbaar")
 * hield hetzelfde tegen met een botter mes.
 */
@Component({
  selector: 'app-results',
  imports: [RouterLink],
  templateUrl: './results.html',
  styleUrl: './results.css',
})
export class Results {
  private readonly api = inject(ZaalApi);

  protected readonly rows = computed(() =>
    this.api.games().map((game, index) => ({
      game,
      number: index + 1,
      who: game.players.map((player) => this.api.nameOf(player)).join(', '),
      line: game.isComplete
        ? game.sets.map((set) => `${set.home.score}–${set.away.score}`).join('  ·  ')
        : null,
    })),
  );

  protected readonly openCount = computed(() => this.api.gamesWithoutScore().length);
}

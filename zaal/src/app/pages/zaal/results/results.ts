import { Component, computed, inject, input, output } from '@angular/core';
import { Game } from '../../../core/models';
import { ZaalApi } from '../../../core/zaal-api';

/**
 * Alle uitslagen van vanavond, om te lezen.
 *
 * Soms wil iemand gewoon zien hoe de rest het deed. Dat is een leesscherm, geen
 * invoerscherm: een wedstrijd zonder score staat er gestippeld bij en is bewust
 * níet aantikbaar. Zou dat wel kunnen, dan bestond er weer een invoerpad dat niet
 * bij je eigen naam begint — en dat is precies de deur waardoor het per ongeluk
 * aanpassen binnenkwam.
 */
@Component({
  selector: 'app-results',
  templateUrl: './results.html',
  styleUrl: './results.css',
})
export class Results {
  private readonly api = inject(ZaalApi);

  readonly games = input.required<Game[]>();

  readonly peek = output<Game>();

  protected readonly rows = computed(() =>
    this.games().map((game, index) => ({
      game,
      number: index + 1,
      who: game.players.map((player) => this.api.nameOf(player)).join(', '),
      line: game.isComplete
        ? game.sets.map((set) => `${set.home.score}–${set.away.score}`).join('  ·  ')
        : null,
    })),
  );

  protected readonly openCount = computed(
    () => this.games().filter((game) => !game.isComplete).length,
  );
}

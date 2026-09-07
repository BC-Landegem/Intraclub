import { Component, computed, input, output, signal } from '@angular/core';
import { Game, RoundPlayer } from '../../../core/models';

/** Wat er van iemands wedstrijd te zeggen valt, vóór je hem aantikt. */
type PlayerState = 'open' | 'saved' | 'drawn';

interface Candidate {
  player: RoundPlayer;
  state: PlayerState;
  /** Wanneer de score bewaard werd, als tijd van de dag. Enkel bij 'saved'. */
  savedAt: string | null;
}

/**
 * Wie heeft er gespeeld?
 *
 * Een speler kent zijn eigen naam, niet het nummer van zijn wedstrijd. Dus begint
 * de weg naar een score hier: eerst de voorletter van je voornaam, dan je naam.
 * Twee tikken, en geen van beide schermen scrollt — bij 46 aanwezigen staan er 21
 * letters op het scherm en daaronder hoogstens een handvol namen.
 *
 * Voorletter van de *voornaam*, want zo denk je in een club aan iemand. De tegel
 * toont beide namen, dus wie op de familienaam zoekt vindt het ook.
 */
@Component({
  selector: 'app-player-finder',
  templateUrl: './player-finder.html',
  styleUrl: './player-finder.css',
})
export class PlayerFinder {
  readonly players = input.required<RoundPlayer[]>();
  readonly games = input.required<Game[]>();

  readonly picked = output<RoundPlayer>();

  protected readonly letter = signal<string | null>(null);

  private readonly gameByPlayer = computed(() => {
    const byPlayer = new Map<number, Game>();

    for (const game of this.games()) {
      for (const player of game.players) {
        byPlayer.set(player.id, game);
      }
    }

    return byPlayer;
  });

  private readonly candidates = computed<Candidate[]>(() => {
    const byPlayer = this.gameByPlayer();

    return this.players().map((player) => {
      const game = byPlayer.get(player.id);

      if (game === undefined) {
        return { player, state: 'drawn' as const, savedAt: null };
      }

      return game.isComplete
        ? { player, state: 'saved' as const, savedAt: timeOfDay(game.savedAt) }
        : { player, state: 'open' as const, savedAt: null };
    });
  });

  /** De voorletters die er vanavond zijn, met of zonder werk eronder. */
  protected readonly letters = computed(() => {
    const perLetter = new Map<string, Candidate[]>();

    for (const candidate of this.candidates()) {
      const initial = initialOf(candidate.player.firstName);
      const group = perLetter.get(initial);
      group === undefined ? perLetter.set(initial, [candidate]) : group.push(candidate);
    }

    return [...perLetter.entries()]
      .map(([initial, group]) => ({
        initial,
        // Een letter dooft zodra er niets meer te doen valt: alles bewaard, of
        // enkel wie deze speeldag toch niet meespeelde.
        settled: group.every((candidate) => candidate.state !== 'open'),
      }))
      .sort((one, other) => one.initial.localeCompare(other.initial, 'nl'));
  });

  protected readonly named = computed(() => {
    const chosen = this.letter();

    return chosen === null
      ? []
      : this.candidates()
          .filter((candidate) => initialOf(candidate.player.firstName) === chosen)
          .sort((one, other) => one.player.fullName.localeCompare(other.player.fullName, 'nl'));
  });

  protected choose(candidate: Candidate): void {
    if (candidate.state !== 'drawn') {
      this.picked.emit(candidate.player);
    }
  }
}

function initialOf(firstName: string): string {
  return firstName.trim().charAt(0).toUpperCase();
}

/** "20:14" uit een ISO-tijdstip; null blijft null. */
export function timeOfDay(iso: string | null): string | null {
  if (iso === null) {
    return null;
  }

  const moment = new Date(iso);

  return Number.isNaN(moment.getTime())
    ? null
    : moment.toLocaleTimeString('nl-BE', { hour: '2-digit', minute: '2-digit' });
}

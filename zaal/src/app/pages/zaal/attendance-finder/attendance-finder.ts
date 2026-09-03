import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { RoundPlayer } from '../../../core/models';
import { ZaalApi } from '../../../core/zaal-api';
import { AttendanceList } from '../attendance-list/attendance-list';

/** Lang genoeg om de veeg en het vinkje te zien, kort genoeg om niet te wachten. */
const TERUG_MS = 1100;

/**
 * "Wie is er vanavond?" — zichzelf aanwezig zetten, in twee tikken.
 *
 * Dezelfde vorm als de score-vinder, en om dezelfde reden: de club telt 88 leden,
 * en die in één raster zetten is drie schermvullingen scrollen plus een
 * tabletklavier. Achter een voorletter staan er een handvol. Zolang deze lijst van
 * de organisator was mocht ze lang zijn — hij gaat ze één keer af. Zodra iedereen
 * zichzelf aanduidt is het een zoekscherm, en dan geldt wat overal in deze app
 * geldt: twee tikken, geen schuifbalk.
 *
 * Na een geslaagde tik keert het scherm terug naar de letters. Dat scheelt de
 * volgende in de rij een tik, en het haalt de scherpste kant van de toggle weg:
 * een tweede tik op je eigen groene tegel zou je er weer áf zetten, en daar is nu
 * geen scherm meer voor. Wie zich wil corrigeren zoekt zichzelf gewoon opnieuw op.
 */
@Component({
  selector: 'app-attendance-finder',
  imports: [AttendanceList],
  templateUrl: './attendance-finder.html',
  styleUrl: './attendance-finder.css',
})
export class AttendanceFinder {
  protected readonly api = inject(ZaalApi);

  protected readonly letter = signal<string | null>(null);

  private terugTimer: ReturnType<typeof setTimeout> | undefined;

  constructor() {
    inject(DestroyRef).onDestroy(() => clearTimeout(this.terugTimer));
  }

  /** De voorletters die onder de leden voorkomen, in alfabetische volgorde. */
  protected readonly letters = computed(() => {
    const found = new Set<string>();

    for (const player of this.api.players()) {
      found.add(initialOf(player.firstName));
    }

    return [...found].sort((one, other) => one.localeCompare(other, 'nl'));
  });

  protected readonly named = computed<RoundPlayer[]>(() => {
    const chosen = this.letter();

    return chosen === null
      ? []
      : this.api
          .players()
          .filter((player) => initialOf(player.firstName) === chosen)
          .sort((one, other) => one.fullName.localeCompare(other.fullName, 'nl'));
  });

  protected onCheckedIn(): void {
    clearTimeout(this.terugTimer);
    this.terugTimer = setTimeout(() => this.letter.set(null), TERUG_MS);
  }
}

function initialOf(firstName: string): string {
  return firstName.trim().charAt(0).toUpperCase();
}

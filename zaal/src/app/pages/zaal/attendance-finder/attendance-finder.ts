import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { RoundPlayer } from '../../../core/models';
import { ZaalApi } from '../../../core/zaal-api';
import { AttendanceList } from '../attendance-list/attendance-list';

/** Lang genoeg om de veeg en het vinkje te zien, kort genoeg om niet te wachten. */
const TERUG_MS = 1100;

/**
 * Hoe lang je naam blijft staan nadat je jezelf aanduidde. Ruim langer dan de
 * terugkeer naar de letters, want juist daar was het spoor kwijt.
 */
const STROOK_MS = 5000;

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
 * een tweede tik op je eigen groene tegel zou je er weer áf zetten.
 *
 * Maar die terugkeer nam ook de bevestiging mee. De veeg is klaar op 460ms, het
 * vinkje op 500ms, en op 1100ms stond je weer voor het letterraster met alleen een
 * teller die "47 aanwezig" zegt — een getal dat niets over jóú zegt. Vandaar de
 * strook: ze verschijnt op de tik, noemt je bij naam, overleeft de schermwissel,
 * en biedt de weg terug aan in plaats van je te laten gokken of een tweede tik
 * helpt of schaadt.
 */
@Component({
  selector: 'app-attendance-finder',
  imports: [AttendanceList, RouterLink],
  templateUrl: './attendance-finder.html',
  styleUrl: './attendance-finder.css',
})
export class AttendanceFinder {
  protected readonly api = inject(ZaalApi);

  protected readonly letter = signal<string | null>(null);

  private readonly aangeduid = signal<RoundPlayer | null>(null);

  private terugTimer: ReturnType<typeof setTimeout> | undefined;
  private strookTimer: ReturnType<typeof setTimeout> | undefined;

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      clearTimeout(this.terugTimer);
      clearTimeout(this.strookTimer);
    });
  }

  /**
   * De strook leest de echte toestand, niet wat de tik beloofde. Weigert de server
   * de aanmelding, dan haalt `setAttendance` de waarheid op en verdwijnt ze vanzelf
   * — een bevestiging die blijft staan naast een foutmelding is erger dan geen.
   */
  protected readonly bevestiging = computed(() => {
    const gekozen = this.aangeduid();

    if (gekozen === null) {
      return null;
    }

    return this.api.players().find((player) => player.id === gekozen.id && player.present) ?? null;
  });

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

  protected onCheckedIn(player: RoundPlayer): void {
    clearTimeout(this.terugTimer);
    clearTimeout(this.strookTimer);

    this.aangeduid.set(player);

    this.terugTimer = setTimeout(() => this.letter.set(null), TERUG_MS);
    this.strookTimer = setTimeout(() => this.aangeduid.set(null), STROOK_MS);
  }

  /** "Toch niet." Meteen weg met de strook: er valt niets meer te bevestigen. */
  protected async verwijder(player: RoundPlayer): Promise<void> {
    clearTimeout(this.strookTimer);
    this.aangeduid.set(null);

    await this.api.setAttendance(player.id, false);
  }
}

function initialOf(firstName: string): string {
  return firstName.trim().charAt(0).toUpperCase();
}

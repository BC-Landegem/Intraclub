import { Component, DestroyRef, computed, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterOutlet } from '@angular/router';
import { Auth } from '../../../../core/auth';
import { RoundPlayer } from '../../../../core/models';
import { ZaalApi } from '../../../../core/zaal-api';
import { AttendanceList } from '../../attendance-list/attendance-list';

type Filter = 'alle' | 'aanwezig' | 'afwezig';

/** Waar de duim van de filterrij staat. */
const PLAATS: Record<Filter, number> = { alle: 0, aanwezig: 1, afwezig: 2 };

/**
 * Lang genoeg om de veeg en het vinkje te laten aflopen (460ms, plus een vinkje
 * dat op 500ms klaar is) voor de tegel uit de filter valt.
 */
const GENADE_MS = 800;

/**
 * De aanwezigheden zoals de organisator ze ziet: de volle lijst met een zoekveld,
 * en daaronder de knoppen die alleen hij heeft — loten, een nieuwe speler, afmelden.
 *
 * Spelers duiden zichzelf aan op het beginscherm, in een letterraster. Dat is
 * dezelfde handeling op dezelfde tegels (`AttendanceList`), maar een andere manier
 * om erbij te komen: hij zoekt wie ontbreekt, zij zoeken zichzelf.
 *
 * Zoeken alleen volstond niet. Op een gewone speeldag staan er 46 groene tegels
 * verspreid tussen 42 grijze, en "wie is er nu eigenlijk" beantwoord je dan door
 * het hele raster af te lopen. Vandaar de filterrij: Alle, Aanwezig, Afwezig.
 * Ze staat standaard op Alle — het scherm van vroeger — en vergeet zichzelf zodra
 * hij naar een ander tabblad gaat, want een filter die stil aanstaat laat je
 * geloven dat een speler niet bestaat.
 */
@Component({
  selector: 'app-attendance',
  imports: [RouterLink, RouterOutlet, AttendanceList],
  templateUrl: './attendance.html',
  styleUrl: './attendance.css',
})
export class Attendance {
  private readonly auth = inject(Auth);
  private readonly router = inject(Router);

  protected readonly api = inject(ZaalApi);

  protected readonly searchTerm = signal('');
  protected readonly filter = signal<Filter>('alle');

  protected readonly filterPlaats = computed(() => PLAATS[this.filter()]);

  /**
   * Wie net getikt is en zijn tegel nog even mag houden.
   *
   * Zonder dit verdwijnt onder "Afwezig" de tegel op het moment van de tik, en
   * schuift de volgende naam onder een vinger die nog op het scherm ligt. Nu
   * krijgt de tik eerst zijn antwoord — de veeg, het vinkje — en herschikt het
   * raster pas als de hand al weg is.
   */
  private readonly genade = signal<readonly number[]>([]);
  private readonly klokken = new Map<number, ReturnType<typeof setTimeout>>();

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      for (const klok of this.klokken.values()) {
        clearTimeout(klok);
      }
    });
  }

  /**
   * Wat de zoekterm overhoudt. Ook de tellingen op de filterknoppen slaan hierop:
   * typ je "Bau" terwijl je op Aanwezig staat, dan zegt de rij zelf waar hij wél
   * staat (Aanwezig 0, Afwezig 1) in plaats van je op een leeg scherm te zetten.
   */
  private readonly searched = computed(() => {
    const term = this.searchTerm().trim().toLowerCase();
    const players = this.api.players();

    return term === ''
      ? players
      : players.filter((player) => player.fullName.toLowerCase().includes(term));
  });

  protected readonly tellingen = computed(() => {
    const found = this.searched();
    const aanwezig = found.filter((player) => player.present).length;

    return { alle: found.length, aanwezig, afwezig: found.length - aanwezig };
  });

  protected readonly shownPlayers = computed(() => {
    const chosen = this.filter();

    if (chosen === 'alle') {
      return this.searched();
    }

    const gespaard = this.genade();
    const gezocht = chosen === 'aanwezig';

    return this.searched().filter(
      (player) => player.present === gezocht || gespaard.includes(player.id),
    );
  });

  /** Een lege lijst zegt waaróm ze leeg is; dat weet dit scherm en de tegels niet. */
  protected readonly leegTekst = computed(() => {
    const zoekt = this.searchTerm().trim() !== '';

    switch (this.filter()) {
      case 'aanwezig':
        return zoekt ? 'Niemand binnen met deze naam.' : 'Nog niemand binnen.';
      case 'afwezig':
        return zoekt ? 'Geen afwezige met deze naam.' : 'Iedereen is er.';
      default:
        return zoekt ? 'Niemand met deze naam.' : 'Nog geen spelers.';
    }
  });

  protected onToggled(player: RoundPlayer): void {
    clearTimeout(this.klokken.get(player.id));
    this.genade.update((ids) => (ids.includes(player.id) ? ids : [...ids, player.id]));

    this.klokken.set(
      player.id,
      setTimeout(() => {
        this.klokken.delete(player.id);
        this.genade.update((ids) => ids.filter((id) => id !== player.id));
      }, GENADE_MS),
    );
  }

  /** Geloot: de voorstellen wachten op het andere tabblad. */
  protected async draw(): Promise<void> {
    await this.api.drawRound();
    await this.router.navigate(['/organisator/wedstrijden']);
  }

  protected async signOut(): Promise<void> {
    await this.auth.logout();
    await this.router.navigate(['/login']);
  }
}

import { Component, computed, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterOutlet } from '@angular/router';
import { Auth } from '../../../../core/auth';
import { ZaalApi } from '../../../../core/zaal-api';
import { AttendanceList } from '../../attendance-list/attendance-list';

/**
 * De aanwezigheden zoals de organisator ze ziet: de volle lijst met een zoekveld,
 * en daaronder de knoppen die alleen hij heeft — loten, een nieuwe speler, afmelden.
 *
 * Spelers duiden zichzelf aan op het beginscherm, in een letterraster. Dat is
 * dezelfde handeling op dezelfde tegels (`AttendanceList`), maar een andere manier
 * om erbij te komen: hij zoekt wie ontbreekt, zij zoeken zichzelf.
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

  protected readonly filteredPlayers = computed(() => {
    const term = this.searchTerm().trim().toLowerCase();
    const players = this.api.players();

    return term === ''
      ? players
      : players.filter((player) => player.fullName.toLowerCase().includes(term));
  });

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

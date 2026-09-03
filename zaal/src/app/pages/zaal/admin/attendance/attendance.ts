import { Component, computed, inject, signal } from '@angular/core';
import { Router, RouterLink, RouterOutlet } from '@angular/router';
import { Auth } from '../../../../core/auth';
import { RoundPlayer } from '../../../../core/models';
import { ZaalApi } from '../../../../core/zaal-api';

/**
 * Wie er vanavond is, en daarna de loting. Dit is het enige scherm waar de
 * organisator een lijst afgaat, dus staat de zoekregel bovenaan.
 */
@Component({
  selector: 'app-attendance',
  imports: [RouterLink, RouterOutlet],
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

  /**
   * Checking in is the one moment in this app that is about the player, not the
   * administration. The fill sweeps from where the finger landed, so the tile
   * answers the tap itself; the counter in the tab bar then acknowledges the new
   * arrival.
   */
  protected async checkIn(event: MouseEvent, player: RoundPlayer): Promise<void> {
    const tile = event.currentTarget as HTMLElement;
    const bounds = tile.getBoundingClientRect();
    tile.style.setProperty('--tap-x', `${event.clientX - bounds.left}px`);
    tile.style.setProperty('--tap-y', `${event.clientY - bounds.top}px`);

    await this.api.setAttendance(player.id, !player.present);
  }

  /** Geloot: de voorstellen wachten op het andere tabblad. */
  protected async draw(): Promise<void> {
    await this.api.drawRound();
    await this.router.navigate(['/beheer/wedstrijden']);
  }

  protected async signOut(): Promise<void> {
    await this.auth.logout();
    await this.router.navigate(['/login']);
  }
}

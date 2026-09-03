import { Component, inject } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { RoundPlayer } from '../../../core/models';
import { ZaalApi } from '../../../core/zaal-api';
import { AttendanceFinder } from '../attendance-finder/attendance-finder';
import { PlayerFinder } from '../player-finder/player-finder';

/**
 * De rusttoestand van de tablet, in twee gedaantes. Vóór de loting is dat de
 * aanwezigheidslijst — iedereen duidt zichzelf aan — en daarna "wie heeft er
 * gespeeld?". Eronder staan de twee schermen waarvoor spelers zelf langskomen.
 */
@Component({
  selector: 'app-kiosk',
  imports: [AttendanceFinder, PlayerFinder, RouterLink],
  templateUrl: './kiosk.html',
  styleUrl: './kiosk.css',
})
export class Kiosk {
  private readonly router = inject(Router);

  protected readonly api = inject(ZaalApi);

  /**
   * Iemand heeft zijn naam aangetikt. Staat de score er al, dan is dit een
   * leesscherm; anders begint de invoer. In beide gevallen komt hij in zijn éigen
   * wedstrijd terecht, dus die van iemand anders kan hij niet openen.
   */
  protected onPicked(player: RoundPlayer): void {
    const game = this.api.games().find((item) => item.players.some((one) => one.id === player.id));

    if (game === undefined) {
      return;
    }

    const match = ['/wedstrijd', game.id, 'speler', player.id];

    void this.router.navigate(game.isComplete ? match : [...match, 'score']);
  }
}

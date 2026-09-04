import { Component, inject, input, output } from '@angular/core';
import { RoundPlayer } from '../../../core/models';
import { ZaalApi } from '../../../core/zaal-api';

/**
 * Het tegelraster waarin iemand zichzelf aanwezig zet.
 *
 * Staat op twee schermen, en dat is de reden dat het een eigen component is: de
 * organisator krijgt de volle lijst met een zoekveld erboven, de speler krijgt de
 * handvol namen onder zijn voorletter. Wát een tegel doet is op beide plekken
 * hetzelfde; hóé je bij die tegel komt niet.
 *
 * Welke namen er staan komt dus van buiten. Loten, afmelden en een speler
 * toevoegen staan hier niet: dat zijn de knoppen van de organisator.
 */
@Component({
  selector: 'app-attendance-list',
  templateUrl: './attendance-list.html',
  styleUrl: './attendance-list.css',
  host: { '[class.groot]': 'groot()' },
})
export class AttendanceList {
  private readonly api = inject(ZaalApi);

  readonly players = input.required<RoundPlayer[]>();

  /** Grotere tegels voor het spelersscherm, waar er maar een handvol staan. */
  readonly groot = input(false);

  /**
   * Wat er staat als er geen tegels zijn. Het scherm dat de namen aanlevert weet
   * waaróm de lijst leeg is — een zoekterm, een filter — en deze component niet.
   */
  readonly leeg = input('Geen spelers gevonden.');

  /** Iemand heeft zichzelf zojuist áán gezet; afvinken meldt niets. */
  readonly checkedIn = output<RoundPlayer>();

  /**
   * Elke tik, in beide richtingen. Wie op aanwezigheid filtert heeft dit nodig:
   * daar tilt ook een áfvinking de tegel uit de lijst.
   */
  readonly toggled = output<RoundPlayer>();

  protected isBusy(): boolean {
    return this.api.isBusy();
  }

  /**
   * Inchecken is het ene moment in deze app dat over de speler gaat en niet over
   * de administratie. De vulling veegt open vanaf waar de vinger landde, dus de
   * tegel beantwoordt de tik zelf.
   */
  protected async checkIn(event: MouseEvent, player: RoundPlayer): Promise<void> {
    const tile = event.currentTarget as HTMLElement;
    const bounds = tile.getBoundingClientRect();
    tile.style.setProperty('--tap-x', `${event.clientX - bounds.left}px`);
    tile.style.setProperty('--tap-y', `${event.clientY - bounds.top}px`);

    const wordtAanwezig = !player.present;

    // `setAttendance` zet de toestand meteen om en praat pas daarna met de server,
    // dus melden we nu al: een bevestiging die op het antwoord wacht komt later dan
    // het groen dat ze bevestigt.
    const onderweg = this.api.setAttendance(player.id, wordtAanwezig);

    this.toggled.emit(player);

    if (wordtAanwezig) {
      this.checkedIn.emit(player);
    }

    await onderweg;
  }
}

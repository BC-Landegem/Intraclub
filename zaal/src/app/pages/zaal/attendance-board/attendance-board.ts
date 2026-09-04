import { Component, computed, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ZaalApi } from '../../../core/zaal-api';

/** Vanaf deze hoogte komt er een kolom bij in plaats van een regel. */
const REGELS_PER_KOLOM = 9;

/** Vier kolommen is de smalste kolom waarin een dubbele achternaam nog past. */
const MEEST_KOLOMMEN = 4;

/**
 * "Wie is er al?" — de aanwezigen om te lezen, niet om aan te tikken.
 *
 * Een eigen route en geen uitklap onder de teller. Twee dingen komen daardoor
 * gratis mee: de terugknop doet één stap terug, en de terugvalklok van de zaal
 * grijpt hier in. Die loopt niet op de beginvraag zelf, dus een lijst die iemand
 * openliet zou daar tot de volgende bezoeker blijven staan.
 *
 * Namen zijn tekst en geen tegels. Wie hier komt kijkt of zijn dubbelpartner er
 * al is; op een tablet die van niemand is hoort een strijkende hand niemand af te
 * kunnen melden.
 */
@Component({
  selector: 'app-attendance-board',
  imports: [RouterLink],
  templateUrl: './attendance-board.html',
  styleUrl: './attendance-board.css',
})
export class AttendanceBoard {
  protected readonly api = inject(ZaalApi);

  /**
   * Alfabetisch op voornaam, zoals de hele spelerskant: het letterraster vraagt
   * de eerste letter van je vóórnaam, dus daar zoek je "Steven" en niet "Loos".
   * En met de volledige naam erbij, want een derde van de club deelt een
   * voornaam — de korte labels van `nameOf` zijn voor viertallen, waar de
   * verzameling klein genoeg is om er iets aan te hebben.
   */
  protected readonly namen = computed(() =>
    [...this.api.presentPlayers()].sort((one, other) =>
      one.fullName.localeCompare(other.fullName, 'nl'),
    ),
  );

  /**
   * Zes namen staan in één kolom, 34 in vier. Tot vier groeien de kolommen, en
   * daarna de regels — boven de vijftig aanwezigen wordt er geschoven.
   */
  protected readonly kolommen = computed(() =>
    Math.min(MEEST_KOLOMMEN, Math.max(1, Math.ceil(this.namen().length / REGELS_PER_KOLOM))),
  );
}

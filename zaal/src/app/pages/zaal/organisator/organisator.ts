import { Component, inject } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { ZaalApi } from '../../../core/zaal-api';

/**
 * Het scherm van de organisator: de tabbalk en wat eronder staat. Twee
 * bestemmingen, dus twee routes — en een verversing houdt hem op het tabblad
 * waar hij stond.
 *
 * Heette "Beheer" tot een clublid erop wees dat dat woord al bezet is: PLAN.md
 * en PRODUCT.md noemen Filament het beheerspaneel en de gebruiker erachter de
 * beheerder. Hij verwachtte dus dat scherm. Een label dat zijn publiek noemt
 * laat de juiste persoon binnen en duwt de rest weg; een label dat een inhoud
 * noemt — beheer, spelers, loting — nodigt iedereen uit.
 */
@Component({
  selector: 'app-organisator',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './organisator.html',
  styleUrl: './organisator.css',
})
export class Organisator {
  protected readonly api = inject(ZaalApi);
}

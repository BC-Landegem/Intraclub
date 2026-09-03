import { Component, inject } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { ZaalApi } from '../../../core/zaal-api';

/**
 * Beheer: de tabbalk en het scherm eronder. Twee bestemmingen, dus twee routes —
 * en een verversing houdt de organisator op het tabblad waar hij stond.
 */
@Component({
  selector: 'app-admin',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './admin.html',
  styleUrl: './admin.css',
})
export class Admin {
  protected readonly api = inject(ZaalApi);
}

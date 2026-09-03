import { Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink, RouterOutlet } from '@angular/router';
import { filter } from 'rxjs';
import { ZaalApi } from '../../core/zaal-api';

/** Na zoveel stilte staat de tablet weer op de beginvraag. */
const IDLE_MS = 120_000;

/**
 * Het vaste kader rond elk scherm: de balk met de speeldag, de foutmelding, en
 * de vraag of er vandaag wel een speeldag is.
 *
 * Wat er ín het kader staat komt uit de route. Zolang er geen speeldag geladen
 * is, staat er de startvraag en geen scherm — zo mag elk scherm eronder ervan
 * uitgaan dat er een speeldag ís.
 */
@Component({
  selector: 'app-zaal',
  imports: [RouterLink, RouterOutlet],
  templateUrl: './zaal.html',
  styleUrl: './zaal.css',
  host: {
    '(pointerdown)': 'keepAwake()',
    '(keydown)': 'keepAwake()',
  },
})
export class Zaal {
  private readonly router = inject(Router);

  protected readonly api = inject(ZaalApi);

  /** Waar de tablet nu op staat. De balk en de terugvalklok hangen ervan af. */
  private readonly url = signal(this.router.url);

  protected readonly isHome = computed(() => this.url() === '/');
  protected readonly isAdmin = computed(() => this.url().startsWith('/beheer'));

  private idleTimer: ReturnType<typeof setTimeout> | undefined;

  constructor() {
    this.router.events
      .pipe(
        filter((event) => event instanceof NavigationEnd),
        takeUntilDestroyed(),
      )
      .subscribe(() => {
        this.url.set(this.router.url);
        this.keepAwake();
      });
  }

  protected async startToday(): Promise<void> {
    await this.api.startToday();
  }

  protected async openRound(roundId: number): Promise<void> {
    await this.api.openRound(roundId);
  }

  /**
   * Elke aanraking en elke navigatie schuiven de terugvalklok op. Staat de tablet
   * al op de beginvraag, dan is er niets om naar terug te vallen en loopt er geen
   * klok — anders zou het scherm zich om de twee minuten voor niets verversen.
   *
   * De terugval vervángt de stap in de geschiedenis: wie na een half uur weer
   * langskomt, hoort met de terugknop niet in de wedstrijd van iemand anders te
   * belanden.
   */
  protected keepAwake(): void {
    clearTimeout(this.idleTimer);

    if (this.isHome()) {
      return;
    }

    this.idleTimer = setTimeout(
      () => void this.router.navigate(['/'], { replaceUrl: true }),
      IDLE_MS,
    );
  }
}

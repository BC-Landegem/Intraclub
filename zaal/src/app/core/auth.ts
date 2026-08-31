import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { CurrentUser } from './models';

/**
 * Sessie-login. De app draait op dezelfde origin als de API, dus de sessiecookie
 * doet het werk: het zaaltoestel blijft ingelogd tot iemand uitlogt.
 */
@Injectable({ providedIn: 'root' })
export class Auth {
  private readonly http = inject(HttpClient);

  private readonly currentUser = signal<CurrentUser | null>(null);

  readonly user = this.currentUser.asReadonly();

  async login(email: string, password: string): Promise<void> {
    // Haal eerst het CSRF-cookie op; Angular stuurt het daarna automatisch mee.
    await firstValueFrom(this.http.get('/sanctum/csrf-cookie', { responseType: 'text' }));

    const user = await firstValueFrom(this.http.post<CurrentUser>('/api/login', { email, password }));
    this.currentUser.set(user);
  }

  async logout(): Promise<void> {
    await firstValueFrom(this.http.post('/api/logout', {}));
    this.currentUser.set(null);
  }

  /** Controleert bij het opstarten of de sessie nog geldig is. */
  async restoreSession(): Promise<boolean> {
    try {
      const user = await firstValueFrom(this.http.get<CurrentUser>('/api/me'));
      this.currentUser.set(user);

      return true;
    } catch {
      this.currentUser.set(null);

      return false;
    }
  }
}

export const authGuard: CanActivateFn = async () => {
  const auth = inject(Auth);
  const router = inject(Router);

  if (auth.user() !== null || (await auth.restoreSession())) {
    return true;
  }

  return router.createUrlTree(['/login']);
};

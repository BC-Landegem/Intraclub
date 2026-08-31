import { Routes } from '@angular/router';
import { authGuard } from './core/auth';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () => import('./pages/login/login').then((m) => m.Login),
    title: 'Aanmelden - Intraclub',
  },
  {
    path: '',
    loadComponent: () => import('./pages/zaal/zaal').then((m) => m.Zaal),
    canActivate: [authGuard],
    title: 'Intraclub zaal',
  },
  { path: '**', redirectTo: '' },
];

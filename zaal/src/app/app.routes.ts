import { inject } from '@angular/core';
import { ResolveFn, Routes } from '@angular/router';
import { authGuard } from './core/auth';
import { ZaalApi } from './core/zaal-api';

/**
 * Elk scherm van de zaal-app is een route.
 *
 * Waarom: de tablet in de zaal heeft een terugknop, en die hoort te doen wat hij
 * overal doet — één stap terug. Zolang de schermen toestand in een component
 * waren, sloot die knop de hele app af. Nu staat elke stap in de URL, dus werkt
 * ook een verversing: wie op /wedstrijden of in een wedstrijd staat, blijft daar.
 *
 * De paden zijn Nederlands, zoals alles wat in de zaal te lezen valt.
 */

/**
 * De speeldag staat vóór het eerste scherm er is. Zo mag elk scherm ervan
 * uitgaan dat de toestand geladen is — ook wie rechtstreeks op een wedstrijd
 * binnenkomt of de pagina ververst.
 */
const roundLoaded: ResolveFn<boolean> = async () => {
  await inject(ZaalApi).loadCurrentRound();

  return true;
};

const matchScreen = () => import('./pages/zaal/match/match').then((m) => m.Match);
const composeScreen = () =>
  import('./pages/compose-match/compose-match').then((m) => m.ComposeMatch);

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
    resolve: { round: roundLoaded },
    children: [
      {
        path: '',
        loadComponent: () => import('./pages/zaal/kiosk/kiosk').then((m) => m.Kiosk),
        title: 'Intraclub zaal',
      },
      {
        path: 'wedstrijden',
        loadComponent: () => import('./pages/zaal/results/results').then((m) => m.Results),
        title: 'Wedstrijden - Intraclub',
      },
      {
        path: 'tussenstand',
        loadComponent: () => import('./pages/standings/standings').then((m) => m.Standings),
        title: 'Tussenstand - Intraclub',
      },

      /*
       * Eén wedstrijd in drie gedaantes — lezen, invullen, bevestigen — en elke
       * gedaante bestaat twee keer: met en zonder speler in het pad. Ze hangen
       * alle zes aan hetzelfde scherm, dat uit `mode` opmaakt wat het te doen
       * heeft.
       *
       * De speler in het pad is geen recht maar een aanspreking: hij bepaalt of
       * je eigen naam vooraan staat en of hij oplicht in de telling. Invullen
       * mocht altijd al door elk van de vier, dus wie via het bord van de avond
       * binnenkomt hoeft zich niet eerst bekend te maken.
       */
      {
        path: 'wedstrijd/:gameId',
        loadComponent: matchScreen,
        data: { mode: 'recap' },
        title: 'Wedstrijd - Intraclub',
      },
      {
        path: 'wedstrijd/:gameId/score',
        loadComponent: matchScreen,
        data: { mode: 'entry' },
        title: 'Score invullen - Intraclub',
      },
      {
        path: 'wedstrijd/:gameId/bewaard',
        loadComponent: matchScreen,
        data: { mode: 'confirm' },
        title: 'Bewaard - Intraclub',
      },
      {
        path: 'wedstrijd/:gameId/speler/:playerId',
        loadComponent: matchScreen,
        data: { mode: 'recap' },
        title: 'Wedstrijd - Intraclub',
      },
      {
        path: 'wedstrijd/:gameId/speler/:playerId/score',
        loadComponent: matchScreen,
        data: { mode: 'entry' },
        title: 'Score invullen - Intraclub',
      },
      {
        path: 'wedstrijd/:gameId/speler/:playerId/bewaard',
        loadComponent: matchScreen,
        data: { mode: 'confirm' },
        title: 'Bewaard - Intraclub',
      },

      /*
       * De organisator: twee schermen onder één tabbalk, en de dialogen die erbij
       * horen als kindroute. Zo sluit de terugknop een dialoog in plaats van de app.
       */
      {
        path: 'organisator',
        loadComponent: () =>
          import('./pages/zaal/organisator/organisator').then((m) => m.Organisator),
        children: [
          { path: '', pathMatch: 'full', redirectTo: 'aanwezig' },
          {
            path: 'aanwezig',
            loadComponent: () =>
              import('./pages/zaal/organisator/attendance/attendance').then((m) => m.Attendance),
            title: 'Aanwezigheid - Intraclub',
            children: [
              {
                path: 'nieuwe-speler',
                loadComponent: () =>
                  import('./pages/add-player/add-player').then((m) => m.AddPlayer),
              },
            ],
          },
          {
            path: 'wedstrijden',
            loadComponent: () =>
              import('./pages/zaal/organisator/games/games').then((m) => m.Games),
            title: 'Wedstrijden - Intraclub',
            children: [
              { path: 'aanvullen', loadComponent: composeScreen, data: { filling: true } },
              { path: 'toevoegen', loadComponent: composeScreen },
            ],
          },
        ],
      },
    ],
  },
  { path: '**', redirectTo: '' },
];

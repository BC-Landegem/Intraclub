import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideHttpClient, withFetch, withXsrfConfiguration } from '@angular/common/http';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { routes } from './app.routes';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    // withComponentInputBinding: de route zet zijn parameters en zijn `data`
    // rechtstreeks op de inputs van het scherm, dus een scherm kan uit de URL
    // opgebouwd worden en heeft geen ouder nodig die het vertelt wat het toont.
    provideRouter(routes, withComponentInputBinding()),
    // Laravel zet het XSRF-TOKEN-cookie; Angular stuurt het terug als X-XSRF-TOKEN.
    provideHttpClient(
      withFetch(),
      withXsrfConfiguration({ cookieName: 'XSRF-TOKEN', headerName: 'X-XSRF-TOKEN' }),
    ),
  ],
};

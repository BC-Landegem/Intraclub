import { bootstrapApplication } from '@angular/platform-browser';
import { appConfig } from './app/app.config';
import { App } from './app/app';

bootstrapApplication(App, appConfig)
  .catch((err) => console.error(err));

// Minimale PWA: enkel om de app installeerbaar te maken, zonder caching.
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register(new URL('sw.js', document.baseURI));
}

// Bewust leeg: Chrome wil een service worker met een fetch-handler voor de
// installatieprompt, maar we cachen niets. De zaal-app heeft altijd verse
// standen nodig en draait op het zaalwifi-netwerk.
self.addEventListener('fetch', () => {});

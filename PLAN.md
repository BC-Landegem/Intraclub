# Plan: modernisering Intraclub

*Opgesteld 2026-08-26, na doorlopen beslisboom. Deadline: live vóór de eerste speeldag van seizoen 2026-2027.*

## Genomen beslissingen

| Onderwerp | Beslissing |
|---|---|
| Hosting | Bestaande shared hosting (DirectAdmin), deploy via FTP (geen SSH/composer op server). **⚠ Blokkade (26-08): PHP-versie kan enkel per volledig domein gezet worden en de oude Joomla-site vereist nog PHP 7.x.** Eerst lokaal bouwen; go-live vereist één van: (a) Joomla-site PHP 8-compatibel maken/upgraden, (b) aparte hosting voor de app, (c) hoster vragen naar per-subdomein PHP (soms tóch mogelijk via php-fpm selector) |
| Locatie | Subdomein, bv. `intraclub.bclandegem.be`, document root → `public/` van de Laravel-app (zodra PHP-blokkade opgelost) |
| Stack | Laravel 12 + Filament (beheerspaneel), publieke JSON-API, Angular zaal-app |
| Zaal-app | Angular (standalone components, signals), `ng build` output naar `public/zaal/` van Laravel → zelfde origin, Sanctum cookie-auth |
| Database | Nieuw snake_case schema via Laravel-migrations + herhaalbaar datamigratiescript vanaf het oude schema. Model heet `Game` (niet `Match` — gereserveerd keyword in PHP 8) |
| Auth | Eén `users`-tabel (e-mail + wachtwoord). Filament-login voor beheer; zaal-app logt in via Sanctum SPA cookie-mode; publieke lees-endpoints anoniem. **Alle schrijf-endpoints vereisen auth** (nu staan de match-endpoints open!) |
| Rekenlogica | 1:1 porten, geborgd met regressietest op een productie-dump (oude vs. nieuwe output diffen tot 0 verschillen). Daarna: automatisch herberekenen na score-invoer + handmatige force-knop in Filament |
| Admin-scope | CRUD spelers/speeldagen/wedstrijden/seizoenen, gebruikersbeheer, klassement-inzage + herberekening. Aanwezigheden/uitloting blijven een zaal-app-feature |
| Publieke site | Joomla blijft. De pagina’s worden in een **apart project** beheerd; daar volstaat het wijzigen van de API-base-URL, want het contract is identiek gebleven. `web/` in deze repo is enkel een referentiekopie — niet aanpassen. |
| Deploy | GitHub Actions: `composer install --no-dev` + `ng build` in CI, FTPS-sync van gewijzigde bestanden. Migrations via beveiligde web-route na deploy |
| Cutover | Big bang vóór seizoensstart. Fallback: oude systeem blijft staan; datamigratie is herhaalbaar, dus omschakelen kan desnoods ook na speeldag 1 |

## Doelstructuur repo

```
/app            → Laravel-app (Filament, API, serveert ook de zaal-app)
/zaal           → Angular-workspace, build-output → ../app/public/zaal
/web            → referentiekopie van de Joomla-pagina’s (worden elders beheerd — niet aanpassen)
/legacy         → oude api/, api-experimental/, intra-app/ (verwijderen na cutover)
```

## Fasen

### Fase 0 — Verifiëren (dag 1, blokkerend)
- [ ] PHP-versie op de host bevestigen (≥8.2, liefst 8.3) en instelbaar per subdomein
- [ ] Subdomein aanmaken met eigen document root
- [ ] FTPS-account voor CI aanmaken
- [ ] Productie-DB-dump trekken (nodig voor datamigratie én regressietest)
- [ ] Testen of PUT/PATCH werkt op het subdomein (los van Joomla's .htaccess); zo niet: `_method`-spoofing inbouwen
- [ ] Tweede MariaDB-database aanmaken voor de nieuwe app (oude blijft onaangeroerd tot cutover)

### Fase 1 — Fundament ✅ (afgerond 26-08)
- [x] Laravel 12-app in `/app`, Filament 5 geïnstalleerd (admin op /admin), Laravel Boost dev-tooling
- [x] Migrations: `players`, `seasons`, `rounds`, `games` (player1_id…player4_id; teams roteren per set), `player_round_statistics`, `player_season_statistics` — originele ID's blijven behouden
- [x] Eloquent-models + relaties, `Gender`-enum, accessors (`full_name`, `is_veteran` ≥45j, `is_recreant`, `is_complete`)
- [x] `php artisan intraclub:import-legacy --force`: idempotente import vanaf connectie `legacy` (intraclub_legacy). Geverifieerd: alle rijaantallen én aggregaten (scores, basispunten, gemiddelden) identiek aan de dump
- [x] Eerste admin-user via `make:filament-user`

### Fase 2 — Rekenlogica + regressietest ✅ (afgerond 26-08)
- [x] `App\Services\GameStatistics`: pure port van `Utilities::calculateMatchStatistics` (roterende teams per set, trim-naar-21-regel) — gedekt door unit tests
- [x] `App\Services\SeasonCalculator`: port van `calculateCurrentSeason` (verliezersgemiddelde per speeldag, voortschrijdend gemiddelde per speler, seizoenstellers; zet ook `is_calculated` zoals legacy)
- [x] `php artisan intraclub:verify-calculation [--season=]`: herberekent en dift tegen de geïmporteerde legacy-waarden — **alle 3 seizoenen bit-identiek (Δ = 0.0)**
- [x] Automatische herberekening via `GameObserver` (score ingevoerd/gewijzigd/game verwijderd → seizoen herberekend); end-to-end getest
- [x] **Opgelost in fase 3:** de `SeasonCalculator` rekent nu tot de eerste speeldag die niet volledig is ingevuld (een speeldag telt zodra ze games heeft én álle games compleet zijn). Speeldagen die niet meetellen worden teruggezet (`is_calculated = false`, gemiddelden `null`), dus de publieke tussenstand toont nooit een halve speeldag. Gedekt door feature tests.
- Vondst: legacy `RoundRepository::create` verwijst naar kolom `DrawClosed` die niet in de productie-DB bestaat (dode code) — speeldag-creatie liep dus al niet meer via dat pad. Bekijken bij de zaal-app of een "loting gesloten"-status alsnog nodig is.

### Fase 3 — Filament-beheerspaneel ✅ (afgerond 26-08)
- [x] Resources: Spelers, Speeldagen (met games-beheer als relation manager), Seizoenen, Gebruikers — Nederlandstalig, met filters en zinnige defaults
- [x] Games bewerken: spelers + de drie sets met de roterende teamsamenstelling in de labels (set 1 = 1+2 vs 3+4, set 2 = 1+3 vs 2+4, set 3 = 1+4 vs 2+3); elke speler mag maar één keer in een game
- [x] Nieuw seizoen aanmaken kent automatisch basispunten toe volgens de eindstand (`SeasonCreator`, port van legacy)
- [x] Klassement-pagina: 4 categorieën (algemeen, dames, veteranen 45+, recreanten) met stijgers/dalers t.o.v. de vorige speeldag, seizoenkeuze en knop "Herbereken tussenstand" (`RankingService`, port van legacy `RankingManager`)
- [x] Autorisatie: alles achter de Filament-login; gebruiker kan zichzelf niet verwijderen
- [x] Browser-getest op echte data: login, CRUD, games bewerken + opslaan (auto-herberekening bevestigd), klassement, herberekenknop
- Opmerking: relation managers zijn read-only op de "bekijken"-pagina van een speeldag (Filament-standaard); games bewerken gebeurt via "Bewerken".

### Fase 4 — Publieke API ✅ (afgerond 26-08)
- [x] Alle legacy read-endpoints geport: `rankings` (+ `/general|women|veterans|recreants`, met `$top`), `rounds` (+ `latest`, `latestCalculated`, `{id}`, `{id}/matches`), `players` (+ `{id}` met seizoensinfo, wedstrijden en rankinggeschiedenis), `seasons`, `seasons/latest/statistics`
- [x] **Contract geverifieerd tegen de live legacy-API** (https://www.bclandegem.be/intra-app/api/index.php/): response-voor-response identiek op alle endpoints. Vergelijkscript: zie git-historie van deze fase.
- [x] `JsonResource::withoutWrapping()` — de site verwacht kale arrays, geen `data`-wrapper
- [x] `gender` blijft `Man`/`Woman` in de API (intern een enum met `male`/`female`), want consumenten vergelijken er strikt op
- [x] CORS in `config/cors.php`, origins instelbaar via `CORS_ALLOWED_ORIGINS`; `supports_credentials` aan voor de zaal-app (Sanctum SPA-cookie)
- [x] 10 contracttests in `tests/Feature/PublicApiTest.php`; volledige suite: 22 tests groen
- **Legacy-bug gevonden:** `GET /rounds/{id}` gaf per wedstrijd de *speeldag*-id terug als `matches[].id` en `round: {id: 0, number: 0}` (de query selecteert `RND.id` en haalt `MT.id`/roundnummer niet op). Onze versie geeft de juiste waarden. De speeldagpagina gebruikt die velden niet, dus dit breekt niets.
- **Joomla-pagina's worden apart beheerd** (ander project) — het omzetten naar de nieuwe base-URL gebeurt daar. Omdat het contract identiek is, volstaat het wijzigen van de basis-URL; verder is geen enkele aanpassing nodig.

### Fase 5 — Zaal-app in Angular (±3 dagen)
- Login-scherm (Sanctum cookie), daarna permanent ingelogd op het zaaltoestel
- Features van de huidige intra-app: aanwezigheden aanduiden, uitloting, wedstrijden samenstellen, score-invoer, match-complete-visualisatie
- Donker thema / grote knoppen (tablet-UX zoals nu met MDB dark)
- Build naar `app/public/zaal/`

### Fase 6 — CI/CD + go-live (±1 dag)
- GitHub Actions: build (composer, ng) → FTPS-deploy naar subdomein
- Migrations-route (secret-token-beveiligd) of migratie bij eerste request
- Finale datamigratie draaien, regressietest nogmaals op verse dump
- Joomla-pagina's omzetten, zaaltoestel naar nieuwe URL
- Oude `api/` read-only zetten of uitschakelen; `legacy/` opruimen na een paar stabiele speelweken

## Lokale dev-omgeving (opgezet 26-08-2026)

- PHP 8.4.24 via winget (`%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_...`), php.ini met pdo_mysql/mbstring/intl/curl/zip/gd/opcache. Oude PHP 7.4 staat nog op `C:\tools\php74` maar is uit de PATH gehaald. **VS Code/terminal herstarten om de nieuwe PATH op te pikken.**
- MariaDB 12.3 als Windows-service `MariaDB` (root, geen wachtwoord — enkel lokaal). Client-tools in PATH.
- Databases: `intraclub` (nieuwe app) en `intraclub_legacy` (import van productie-dump `bclandegem_intraclub.sql`; dumps staan in de root en zijn ge-gitignored).
- Laravel 12 + Filament 5 in `/app`, `.env` wijst naar `intraclub`. Start: `cd app && php artisan serve` → admin op http://localhost:8000/admin (login: lmartens@metanous.be / intraclub-dev).

## Risico's

1. **Deadline (±11 werkdagen werk, seizoen start begin september).** Mitigatie: fase 4 en 5 kunnen na speeldag 1 (oude systeem blijft werken; datamigratie is herhaalbaar). Minimum voor cutover = fasen 0-3.
2. **Rekenverschillen.** De regressietest is de poortwachter — geen cutover zonder 0-diff.
3. **Shared-hosting-verrassingen** (mod_security, memory limits, geen `proc_open` voor bepaalde packages). Mitigatie: fase 0 test dit vroeg; Laravel draait bewezen op DirectAdmin shared hosting.
4. **Wachtwoord-reset vereist mail.** Shared hosting heeft meestal SMTP; anders: admins beheren wachtwoorden via Filament-gebruikersbeheer (gekozen scope dekt dit).

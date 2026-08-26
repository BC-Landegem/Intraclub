# Plan: modernisering Intraclub

*Opgesteld 2026-08-26, na doorlopen beslisboom. Deadline: live vóór de eerste speeldag van seizoen 2026-2027.*

## Genomen beslissingen

| Onderwerp | Beslissing |
|---|---|
| Hosting | Bestaande shared hosting (DirectAdmin), deploy via FTP (geen SSH/composer op server). **⚠ Blokkade (26-08): PHP-versie kan enkel per volledig domein gezet worden en de oude Joomla-site vereist nog PHP 7.x.** Eerst lokaal bouwen; go-live vereist één van: (a) Joomla-site PHP 8-compatibel maken/upgraden, (b) aparte hosting voor de app, (c) hoster vragen naar per-subdomein PHP (soms tóch mogelijk via php-fpm selector) |
| Locatie | Subdomein, bv. `intraclub.bclandegem.be`, document root → `public/` van de Laravel-app (zodra PHP-blokkade opgelost) |
| Stack | Laravel 12 + Filament (beheerspaneel), publieke JSON-API, Angular zaal-app |
| Zaal-app | Angular (standalone components, signals), `ng build` output naar `public/zaal/` van Laravel → zelfde origin, geen CORS, Sanctum cookie-auth |
| Database | Nieuw snake_case schema via Laravel-migrations + herhaalbaar datamigratiescript vanaf het oude schema. Model heet `Game` (niet `Match` — gereserveerd keyword in PHP 8) |
| Auth | Eén `users`-tabel (e-mail + wachtwoord). Filament-login voor beheer; zaal-app logt in via Sanctum SPA cookie-mode; publieke lees-endpoints anoniem. **Alle schrijf-endpoints vereisen auth** (nu staan de match-endpoints open!) |
| Rekenlogica | 1:1 porten, geborgd met regressietest op een productie-dump (oude vs. nieuwe output diffen tot 0 verschillen). Daarna: automatisch herberekenen na score-invoer + handmatige force-knop in Filament |
| Admin-scope | CRUD spelers/speeldagen/wedstrijden/seizoenen, gebruikersbeheer, klassement-inzage + herberekening. Aanwezigheden/uitloting blijven een zaal-app-feature |
| Publieke site | Joomla blijft; `web/*.html` pagina's worden éénmalig omgezet naar de nieuwe API-base-URL |
| Deploy | GitHub Actions: `composer install --no-dev` + `ng build` in CI, FTPS-sync van gewijzigde bestanden. Migrations via beveiligde web-route na deploy |
| Cutover | Big bang vóór seizoensstart. Fallback: oude systeem blijft staan; datamigratie is herhaalbaar, dus omschakelen kan desnoods ook na speeldag 1 |

## Doelstructuur repo

```
/app            → Laravel-app (Filament, API, serveert ook de zaal-app)
/zaal           → Angular-workspace, build-output → ../app/public/zaal
/web            → Joomla-pagina's (blijven, endpoints geüpdatet)
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
- [x] Migrations: `players`, `seasons`, `rounds`, `games` (semantische kolommen: home_player1_id…away_player2_id), `player_round_statistics`, `player_season_statistics` — originele ID's blijven behouden
- [x] Eloquent-models + relaties, `Gender`-enum, accessors (`full_name`, `is_veteran` ≥45j, `is_recreant`, `is_complete`)
- [x] `php artisan intraclub:import-legacy --force`: idempotente import vanaf connectie `legacy` (intraclub_legacy). Geverifieerd: alle rijaantallen én aggregaten (scores, basispunten, gemiddelden) identiek aan de dump
- [x] Eerste admin-user via `make:filament-user`

### Fase 2 — Rekenlogica + regressietest (±2 dagen, kritisch pad)
- Port van `RankingManager`, `SeasonManager::calculateCurrentSeason`, `RoundManager`-berekeningen naar services
- Artisan-command dat op de geïmporteerde prod-dump de nieuwe berekening draait en de statistiektabellen regel-voor-regel difft tegen de oude waarden
- Pas door naar fase 3 bij 0 verschillen
- Herberekening triggeren via event na score-wijziging

### Fase 3 — Filament-beheerspaneel (±2 dagen)
- Resources: Players, Rounds, Games, Seasons, Users
- Klassement-pagina (tussenstand per categorie: algemeen, dames, veteranen, recreanten) + knop "herbereken"
- Autorisatie: alleen ingelogde users

### Fase 4 — Publieke API (±1 dag)
- Read-only endpoints, functioneel gelijk aan de oude: rankings (4 varianten), rounds (+ latest/latestCalculated + matches), players (+ seizoensinfo), season statistics
- `web/*.html` (tussenstand, speler, speeldag, toptien) omzetten naar nieuwe base-URL en response-shape verifiëren

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

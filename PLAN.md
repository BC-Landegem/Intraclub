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
/zaal           → Angular 22-workspace (zaal-app), build-output → ../app/public/zaal
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

### Fase 5 — Zaal-app in Angular ✅ (afgerond 26-08)
- [x] Angular 22 in `/zaal` (standalone, signals, Signal Forms), build naar `app/public/zaal/` met `baseHref=/zaal/`; dev via `ng serve` met proxy naar `:8000`
- [x] Sessie-login op dezelfde origin (`/api/login`, CSRF via Angular's XSRF-ondersteuning); route-guard herstelt de sessie, het zaaltoestel blijft ingelogd
- [x] Donker thema, raakvlakken van minstens 56px, zoekveld en tabs (Aanwezig / Matches)
- [x] Aanwezigheden aanduiden, loting opvragen, matches bevestigen, scores invoeren, nieuwe speler toevoegen (staat meteen aanwezig, basispunten 19 zoals legacy)
- [x] Zaal-API achter authenticatie: `/api/zaal/round`, `rounds/{id}/attendance|draw|games|players`, `PUT games/{id}` — 13 tests
- [x] End-to-end getest in de browser: login, aanwezigheden, loting, bevestigen, scores, automatische herberekening, laatkomer-scenario

**Uitgeloot — nieuwe regels (vervangen legacy-gedrag):**
- Uitgeloot wordt **bewaard** in `player_round_statistics.is_drawn_out` (overleeft refresh, weegt mee in latere lotingen).
- **Een uitgelote speeldag telt niet mee** voor het gemiddelde van die speler: hij was er wél, maar mocht niet spelen. Legacy gaf hem het verliezersgemiddelde, net als een afwezige. Effect op historische data: 2 spelers in seizoen 2023-2024 (30 waarden); `intraclub:verify-calculation` rapporteert die apart als *verwachte* afwijking, alle andere waarden blijven bit-identiek.
- **Beschermingsvenster:** wie uitgeloot werd, blijft de volgende `DrawService::PROTECTED_ROUNDS` (= 4) speeldagen buiten schot. Moet er tóch iemand beschermd aan de kant, dan valt de keuze op wie het langst geleden zat.
- **Invallen is vrijwillig, nooit door de app opgelegd.** De loting deelt enkel spelers in die nog geen match hebben en vult een onvolledig viertal *niet* zelf aan. Blijven er 1-3 spelers over, dan biedt de zaal-app een knop **"Match aanvullen"**: de uitgelote spelers staan vast in de match en de zaal kiest zelf wie wil invallen. Kandidaten zijn alle aanwezigen die niet uitgeloot zijn (met hun aantal gespeelde matches erbij), plus een zoeklijst met de overige leden voor wie **net binnenkomt** — die wordt bij het bevestigen meteen aanwezig gezet. Voor de tussenstand telt enkel iemands eerste game van de speeldag, dus een invaller krijgt er geen statistieken bij; dat staat ook zo in het scherm. Belanden uitgelote spelers alsnog in een match, dan wist `GameObserver` hun vlag en telt de speeldag weer mee.
- **Vrije match:** met "+ Match toevoegen" kan de zaal zelf vier aanwezigen kiezen, los van loting of aanvulling.
- **Bonuspunten** staan bij elke speler in de zaal-app (aanwezigheidslijst, loting, matches, aanvul-scherm). Port van `helpers.calculateBonusPoints`: vrouw +2, recreant +5, competitiespeler naargelang dubbelklassement (>10 → +4, >8 → +3, >6 → +2, >4 → +1). Berekend in `Player::bonusPoints`, dus overal consistent.
- Er blijft alleen iemand aan de kant staan als de zaal niet aanvult.

**Score-invoer (na review 26-08):**
- Elke set toont de twee duo's mét volledige namen, zodat meteen duidelijk is welke score bij welke spelers hoort (zoals in de oude app).
- Grote invoervelden (76px hoog, 2,25rem cijfers) — bedoeld om op een tablet in te vullen vlak na het spelen.
- Een match kan set per set ingevuld worden; niet-ingevulde sets blijven leeg.
- Statusbadge per match: "Nog X van 3 sets" of "✓ Volledig ingevuld", met een groene rand en achtergrond wanneer alles ingevuld is.
- Na bewaren verschijnt "✓ Bewaard" bij de knop.
- `Game::is_complete` vereist nu alle drie de sets (was: de eerste twee). Historische games hebben altijd drie sets, dus de regressie blijft intact.

**Tussenstand in de zaal (26-08):** derde tabblad "Tussenstand", zodat spelers tussen twee matches door hun plaats kunnen opzoeken. Vier categorieën, zoekveld ("Zoek jezelf…"), en per rij een slanke meetlat onderaan die toont waar dat gemiddelde ligt tussen het laagste en hoogste van dat klassement — de stand is een gemiddelde op 21, dus die verhouding is betekenisvol. Toont ook na welke speeldag de stand berekend is. Voedt zich met de publieke endpoints `/api/rankings` en `/api/rounds/latestCalculated`.

**Inchecken als moment:** je naam aantikken is het enige in de app dat over de speler zelf gaat. De groene vulling veegt open vanaf het punt waar de vinger landde, het vinkje tekent zichzelf in een ring, en de aanwezigheidsteller pulseert. Uitchecken gebeurt bewust zonder ceremonie — dat is een correctie, geen moment. Alles valt terug op een directe toestandswissel bij `prefers-reduced-motion`.

Daarbij ook de basis opgeschoond: getekende SVG-iconen in plaats van unicode-vinkjes, geen dikke gekleurde randbalk meer op matchkaarten, en de browser-eigen oppervlakken (selectie, caret, placeholder, schuifbalk, cijfer-spinners) mee in het thema getrokken.
**Bewust weggelaten:** de zaal-app kan een match niet verwijderen — eenmaal aangemaakt blijft ze bestaan (de DELETE-route is uit de API gehaald). Corrigeren gebeurt in het beheerspaneel.

**Aandachtspunt voor go-live:** onder `php artisan serve` matcht de Laravel-route `/zaal/{path?}` niet, omdat de map `public/zaal` bestaat en Symfony dat als base-path afsplitst. In productie (Apache) is dat geen probleem, en `public/zaal/.htaccess` vangt diepe links sowieso statisch op. Voor lokale ontwikkeling: `ng serve` gebruiken.

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

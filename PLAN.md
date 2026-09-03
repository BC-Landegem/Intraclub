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
| Archief oude jaargangen | De jaargangen 2009-2023 uit de volledige sitedump gaan naar aparte, alleen-lezen `archive_`-tabellen — **niet** in `games`. Het oude format speelde met vaste teams in best-of-3 (derde set bleef leeg zodra een team er twee had), terwijl `games` vier spelers met per set roterende teams veronderstelt. Zie fase 7 |
| Publieke geschiedenis | Van alles vóór het lopende seizoen blijft publiek enkel de **eindstand**, de **erelijst/records** en de **seizoenstabel van wie nú lid is**. Speeldagen, wedstrijden en fiches van afgesloten seizoenen gaan dicht; de fiche van een niet-lid geeft `403 not_a_member`. Zie fase 11 |
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
- [x] Alle legacy read-endpoints geport: `rankings` (+ `/general|women|veterans|recreants`, met `$top`), `rounds` (+ `latest`, `latestCalculated`, `{id}`, `{id}/matches`), `players` (+ `{id}` met seizoensinfo, wedstrijden en rankinggeschiedenis), `seasons`, `seasons/latest/statistics` → **routetabel herzien in fase 8**
- [x] **Contract geverifieerd tegen de live legacy-API** (https://www.bclandegem.be/intra-app/api/index.php/): response-voor-response identiek op alle endpoints. Vergelijkscript: zie git-historie van deze fase.
- [x] `JsonResource::withoutWrapping()` — de site verwacht kale arrays, geen `data`-wrapper → **teruggedraaid in fase 8**
- [x] `gender` blijft `Man`/`Woman` in de API (intern een enum met `male`/`female`), want consumenten vergelijken er strikt op → **teruggedraaid in fase 8**
- [x] CORS in `config/cors.php`, origins instelbaar via `CORS_ALLOWED_ORIGINS`; `supports_credentials` aan voor de zaal-app (Sanctum SPA-cookie)
- [x] 10 contracttests in `tests/Feature/PublicApiTest.php`; volledige suite: 22 tests groen
- **Legacy-bug gevonden:** `GET /rounds/{id}` gaf per wedstrijd de *speeldag*-id terug als `matches[].id` en `round: {id: 0, number: 0}` (de query selecteert `RND.id` en haalt `MT.id`/roundnummer niet op). Onze versie geeft de juiste waarden. De speeldagpagina gebruikt die velden niet, dus dit breekt niets.
- **Joomla-pagina's worden apart beheerd** (ander project) — het omzetten naar de nieuwe base-URL gebeurt daar. Omdat het contract identiek is, volstaat het wijzigen van de basis-URL; verder is geen enkele aanpassing nodig. → **achterhaald na fase 8**: de Joomla-site wordt vervangen door de nieuwe Astro-site, en die vraagt wél aanpassingen. Zie fase 8 en `docs/website-migratie.md`.

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
**Speeldag van vandaag (26-08):** de zaal-app werkte op "de laatste speeldag van het seizoen". Was die van vandaag niet aangemaakt, dan landden aanwezigheden en matches stilzwijgend op een al afgesloten speeldag. Nu opent de app enkel de speeldag mét de datum van vandaag. Bestaat die niet, dan volgt een lege toestand met twee bewuste keuzes: **"Speeldag van vandaag starten"** (maakt er één aan, nummer volgt op de vorige) of de vorige speeldag openen om bijvoorbeeld scores af te werken. Wie een oudere speeldag opent, krijgt bovenaan een waarschuwing bij welke datum de invoer hoort.
**Bewust weggelaten:** de zaal-app kan een match niet verwijderen — eenmaal aangemaakt blijft ze bestaan (de DELETE-route is uit de API gehaald). Corrigeren gebeurt in het beheerspaneel.

**Aandachtspunt voor go-live:** onder `php artisan serve` matcht de Laravel-route `/zaal/{path?}` niet, omdat de map `public/zaal` bestaat en Symfony dat als base-path afsplitst. In productie (Apache) is dat geen probleem, en `public/zaal/.htaccess` vangt diepe links sowieso statisch op. Voor lokale ontwikkeling: `ng serve` gebruiken.

### Fase 6 — CI/CD + go-live (±1 dag)
- GitHub Actions: build (composer, ng) → FTPS-deploy naar subdomein
- Migrations-route (secret-token-beveiligd) of migratie bij eerste request
- Finale datamigratie draaien, regressietest nogmaals op verse dump

**Datamigratie — herhaalbare keten** (volledig geverifieerd 27-08, ±20 s vanaf lege databanken). De volgorde ligt vast: stap 4 wist `players`, en `archive_players.player_id` hangt daaraan.

```bash
# 1. huidige dump (bevat geen DROP TABLE, dus databank eerst weg)
mysql -u root -e "DROP DATABASE IF EXISTS intraclub_legacy;
  CREATE DATABASE intraclub_legacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root intraclub_legacy < bclandegem_intraclub.sql

cd app
php artisan intraclub:load-archive-dump ../bclandegem_database_*.sql   # 2. sitedump → intraclub_oud
php artisan migrate --force                                            # 3. schema (of migrate:fresh + make:filament-user)
php artisan intraclub:import-legacy --force                            # 4. huidige data
php artisan intraclub:player-map                                       # 5. controle: AMBIGU 0, VOORSTEL 0
php artisan intraclub:import-archive --force                           # 6. archief
php artisan intraclub:verify-calculation --season=1                    # 7. regressietest, per seizoen
php artisan intraclub:merge-duplicates --force                         # 8. dubbels samenvoegen
```

Stap 8 staat bewust ná de regressietest: die vergelijkt met de opgeslagen legacy-waarden, en na een samenvoeging wijken die voor de samengevoegde speler terecht af. Het commando leest `player_dubbels` uit hetzelfde `player-map-overrides.php`, houdt de fiche met de meeste wedstrijden over, zet wedstrijden en aanwezigheden over (bij een botsing op dezelfde speeldag wint aanwezig/uitgeloot van één van beide), herberekent de betrokken seizoenen en weigert als de fiche die zou verdwijnen er méér heeft. `--dry-run` toont het plan.

Acceptatie is geen rijaantal (dat groeit mee met de dump) maar: nul openstaande koppelingen in stap 5, nul verschil in stap 7 op de 30 gedocumenteerde uitloot-afwijkingen na, en dezelfde vijf brondata-meldingen in stap 6. Stap 1 is de enige zonder artisan-commando. `migrate:fresh` wist ook `users` — dan opnieuw een Filament-gebruiker aanmaken.

**Naar productie via phpMyAdmin.** Er is geen SSH, geen `mysql`-CLI en geen artisan op de host, dus de keten hierboven draait lokaal en het *resultaat* gaat als één bestand naar boven. Geverifieerd 27-08 met een round-trip in een lege databank.

```bash
DUMP="mysqldump -u root --single-transaction --no-tablespaces --default-character-set=utf8mb4"
DATA="players seasons rounds games player_round_statistics player_season_statistics
  archive_players archive_seasons archive_rounds archive_games
  archive_player_season_statistics archive_player_round_statistics"

# cutover: structuur van álle tabellen, daarna enkel de data die telt  → 1,66 MB / 0,26 MB gezipt
$DUMP --no-data --add-drop-table intraclub > cutover.sql
$DUMP --no-create-info --skip-add-locks --complete-insert intraclub $DATA migrations users >> cutover.sql

# refresh vóór go-live: enkel de datatabellen, users en sessies op productie blijven staan
$DUMP --add-drop-table --complete-insert intraclub $DATA > refresh.sql
```

- Zet vóór de cutover-export het echte wachtwoord lokaal met `php artisan intraclub:set-password [e-mail]` (vraagt het wachtwoord tweemaal, verborgen) — op productie kan `make:filament-user` niet draaien, dus het account moet mét de juiste hash mee in de dump.
- In phpMyAdmin eerst de doeldatabank selecteren: de dump bevat bewust geen `CREATE DATABASE`/`USE`, dus hetzelfde bestand werkt ongeacht hoe DirectAdmin de databank noemde. `.sql.gz` wordt zelf uitgepakt.
- Werkt omdat: `FOREIGN_KEY_CHECKS=0` staat in de kop (21 constraints, volgorde irrelevant), alles op `utf8mb4_unicode_ci` staat — dat bestaat op de MariaDB 10.6 van de host, terwijl lokaal 12.3 draait met nieuwere `uca1400`-collations die daar zouden falen — en `migrations` meegaat, zodat de migrations-route bij de cutover niets meer hoeft te doen.
- **Herhaalbaar tot en met de cutover, niet erna.** Zodra er in de zaal ingevoerd wordt is productie de bron van waarheid; een refresh-import is dan dataverlies.

Uitgeschreven runbook met verwachte uitvoer per stap: zie de gedeelde link in de projectnotities.
- Joomla-pagina's omzetten, zaaltoestel naar nieuwe URL
- Oude `api/` read-only zetten of uitschakelen; `legacy/` opruimen na een paar stabiele speelweken

### Fase 7 — Archief oude jaargangen (2009-2023) ✅ (afgerond 27-08)

De volledige sitedump bevat twee generaties van vóór het huidige systeem, die nooit mee gemigreerd waren:

| Generatie | Periode | Inhoud |
|---|---|---|
| `comp_*` | 2009-2010 t/m 2012-2013 | 68 speeldagen, 557 wedstrijden, 79 spelers. `comp_seizoen` mist 2010-2011 (afgeleid uit de datums), eindstanden voor 3 van de 4 seizoenen, geen klassementsevolutie per speeldag |
| `intra_*` | 2013-2014 t/m 2022-2023 | 155 speeldagen, 1528 wedstrijden, 166 spelers, 1003 seizoensstatistieken en ~11.800 rijen klassementsevolutie per speeldag |

**Waarom apart en niet in `games`:** het oude format speelde met **vaste teams in best-of-3** — team 1 speelt alle sets tegen team 2, en de derde set bleef leeg zodra een team er twee had (72% van de intra-wedstrijden, 69% van de comp-wedstrijden). Het huidige `games` gaat uit van vier spelers met per set roterende teams, en `GameStatistics` telt een lege set als winst voor het away-team. Oude uitslagen daarin schuiven zou elke herberekening stilzwijgend fout maken. Vastgesteld door beide interpretaties door te rekenen tegen de opgeslagen seizoenstotalen: vaste teams reproduceert 85-98% van de spelers per seizoen, roterende teams nauwelijks iets. Waar het afwijkt ligt de herberekening altijd hóger — de legacy-aggregaten misten wedstrijden. De statistieken worden daarom overgenomen zoals het oude systeem ze publiceerde; dit is een archief, geen herberekening.

- [x] Migration `archive_players`, `archive_seasons`, `archive_rounds`, `archive_games` (team1/team2-semantiek, lege derde set = `null`), `archive_player_season_statistics`, `archive_player_round_statistics`. Setkolommen zijn *signed*, in tegenstelling tot `games`: de oude data bevat een wedstrijd met `-1` als setstand en een archief bewaart dat zoals het was
- [x] `archive_players.player_id` is een nullable link naar `players`. Zo hoeven de 114 vertrokken spelers geen rij in het levende ledenbestand, en blijft `players.birth_date` (NOT NULL) ongemoeid
- [x] `php artisan intraclub:load-archive-dump <dump.sql>`: streamt de volledige sitedump en houdt enkel `comp_*` en `intra_*` over; maakt de databank `intraclub_oud` zelf aan. Vereist `sql_mode=''` (het `klassement`-enum bevat een lege waarde) en negeert `LOCK TABLES` bewust
- [x] `App\Services\Legacy\ArchivePlayerMatcher`: unificeert de drie generaties tot 192 personen op genormaliseerde naam + naamgelijkenis. Handmatig nagekeken beslissingen staan in `database/legacy/player-map-overrides.php`
- [x] `php artisan intraclub:player-map`: schrijft de mapping naar CSV om na te kijken (ge-gitignored, bevat ledengegevens). Nagekeken 27-08: 78 spelers bestaan nog, 114 zijn vertrokken, 0 openstaande beslissingen
- [x] `php artisan intraclub:import-archive`: 192 spelers, 14 seizoenen, 223 speeldagen, 2064 wedstrijden, 1191 seizoensstatistieken, 11831 klassementsrijen. Weigert te draaien zolang een koppeling niet beslist is
- [x] Feature tests op een sqlite-miniatuur van het oude schema
- [x] **Aanwezigheden en matchen worden uit de uitslagen berekend**, niet overgenomen. Geen van beide oude systemen hield ze betrouwbaar bij: `intra_*` begon er pas in 2019-2020 mee (daarvóór stond overal nul) en `comp_*` telde "gewonnen spelletjes", wat niet met gewonnen matchen overeenkomt. De berekening reproduceert de bewaarde intra-cijfers exact waar die bestaan — het importcommando controleert dat bij elke run en rapporteert elke afwijking. Zo geldt één definitie over alle veertien seizoenen
- [x] Filament: navigatiegroep **Archief** met Seizoenen (eindstand + speeldagen), Speeldagen (uitslagen met team1/team2 en de setstand) en Spelers (geschiedenis per seizoen, met link naar de huidige spelersfiche). Alles alleen-lezen; browser-getest op de echte data
- [x] Publieke API onder `/api/archive`: `seasons`, `seasons/{id}/rounds`, `seasons/{id}/standings`, `rounds/{id}`, `players` (filterbaar met `?playerId=`) en `players/{id}` (`?withMatches=1` voor de wedstrijden). Aparte paden, zodat het geverifieerde contract van de bestaande endpoints ongemoeid blijft. 6 contracttests → **in fase 8 op snake_case gezet**: `?player_id=` en `?include=games`, en `data`/`meta` zoals de rest
- [x] **De eindstand van de comp-seizoenen wordt herberekend uit de uitslagen** (`App\Services\Legacy\ArchiveCompStandings`, draait mee in `intraclub:import-archive`). De rekenregels blijken dezelfde als die van het huidige systeem, enkel toegepast op vaste teams: dagresultaat = herschaald setgemiddelde van je team, afwezig = verliezersgemiddelde, eindstand = gemiddelde van de basispunten en elke speeldag. Dat het écht die regels waren is na te rekenen — voor drie van de vier seizoenen reproduceert de berekening de bewaarde eindstand voor **álle** spelers (55/55, 82/82, 79/79), tot op de opslagprecisie, inclusief wie geen enkele wedstrijd speelde. **2010-2011 is de uitzondering:** daar kloppen de bewaarde tellers wél met de uitslagen maar de bewaarde punten niet, en geen enkele afwijkende afwezigheids- of setregel verklaart dat verschil — de stand van toen is blijven staan op uitslagen die daarna nog verschoven zijn. Voor dat seizoen is de herberekening de enige stand die met de bewaarde uitslagen overeenkomt; ze staat er nu met 73 spelers. **Alle 17 seizoenen hebben dus een eindstand**
- [x] **Verwijderde comp-spelers worden "Onbekende speler"** in plaats van overgeslagen. De uitslagen zijn wel degelijk gespeeld; alleen wie er speelde is niet meer te achterhalen. Elk onbekend bron-id krijgt een eigen archiefspeler (8 stuks) — niet één gedeelde, want twee wedstrijden hebben méér dan één onbekende en die zouden anders als dezelfde persoon in dezelfde match belanden. Levert 21 wedstrijden en 21 seizoensstatistieken extra op.
- [x] **`php artisan intraclub:merge-duplicates`**: voegt dubbel aangemaakte spelers samen op basis van `player_dubbels` uit `player-map-overrides.php`. David Inghels (36 ← 132) en Lieselot Van Haute (72 ← 145) hebben nu 15 en 22 wedstrijden op één fiche in plaats van 12+3 en 21+1.
- [x] **Seizoenstellers van de dubbel gaan mee, ze worden niet weggegooid.** Eerste versie schrapte de seizoensrij van de dubbel op de redenering dat tellers afgeleid zijn en meteen herberekend worden. Dat geldt enkel voor leden: `SeasonCalculator` filtert op `is_member`, en voor een niet-lid staan de tellers stil op wat de bron laatst berekend heeft. Beide dubbels zijn niet-lid en speelden 2025-2026 net onder de fiche die verdwijnt — hun seizoen viel daardoor op nul. Nu worden de tellers altijd opgeteld (de wedstrijden van beide fiches zijn disjunct). Spelen beide fiches in hetzelfde seizoen, dan waarschuwt het commando.
- [x] **Basispunten volgen de ledenstatus.** Ze zijn invoer, geen afgeleide, en de juiste keuze hangt af van of de rij nog leeft. Bij een **niet-lid** is de rij bevroren op wat de bron toonde, dus komen de basispunten van de fiche waaronder effectief gespeeld is — David Inghels 2025-2026 houdt 19, niet 19.0033. Bij een **lid** herberekent alles zich toch, en dan tellen de basispunten van de blijvende fiche, want die volgen uit zijn eindstand van vorig seizoen; de dubbel begon als nieuwkomer op 19,0000 en dat is niet zijn plaats. Lieselot Van Haute is terug lid en houdt dus 19.0054; dat scheelt vroeg in het seizoen ~25 plaatsen, op de laatste speeldag komen beide varianten op plaats 72 uit.
- [x] **Het lidvinkje van de dubbel gaat mee.** Wie terugkeert wordt soms opnieuw aangemaakt, en dan draagt net de fiche die verdwijnt de ledenstatus: Lieselot Van Haute staat op fiche 72 als `Member = 0` en op fiche 145 als `Member = 1`. De eerste versie verwijderde die rij en maakte haar zo tot ex-lid. Wie onder één van beide fiches lid is, is lid. Dat gebeurt vóór de statistieken (de ledenstatus bepaalt daar of een rij nog leeft) en zet meteen álle seizoenen van de speler op de herrekenlijst, niet enkel die van de merge — een lid neemt een plaats in het klassement in en verschuift iedereen onder zich. David Inghels is wél gestopt: beide fiches `Member = 0`, zijn rijen blijven bevroren en hij krijgt nergens een plaats.
- **Gevonden in de oude data** (het importcommando rapporteert ze bij elke run): 1 wedstrijd met setstand `-1`, 1 dubbel ingevoerde seizoensstatistiek (de oude tabel had geen unique key), en 22 comp-seizoensstatistieken zonder bijhorende uitslagen — `comp_historie` bewaarde daar een eindstand terwijl `comp_uitslagen` geen enkele wedstrijd van die speler in dat seizoen bevat. Die staan in de eindstand dus op nul speeldagen mét sets en punten; dat is een gat in de bron, niet in de import.
- **Volgorde bij cutover:** `intraclub:import-legacy` truncate `players`, dus daarna altijd opnieuw `intraclub:import-archive` draaien.

### Fase 8 — API-standaardisatie voor de nieuwe site ✅ (afgerond 28-08)

De nieuwe Astro-site (`bc-landegem.github.io/Website`) hing nog aan de legacy Slim-API op `www.bclandegem.be/intra-app/api/index.php`. Fase 4 hield bewust het legacy-contract aan omdat de oude Joomla-pagina's eraan hingen; die gaan mee met de legacy-API. Nu de nieuwe site nog in ontwikkeling is, is dit het laatste goedkope moment om het contract recht te trekken.

- [x] Conventies overal gelijk: veldnamen in `snake_case`, booleans als échte booleans, collecties in `data` met `meta` voor seizoen en speeldag, filters als queryparameters. `JsonResource::withoutWrapping()` is weg.
- [x] `?season=` slikt een id of `current`; weglaten = het lopende seizoen (`ResolvesSeason`). Een onbekend seizoen geeft 404 in plaats van stil op het huidige terug te vallen — anders lijkt een typefout in een build-script gewoon te werken.
- [x] Routes weg: `/rounds/latest`, `/rounds/latestCalculated`, `/seasons/latest/statistics` en `?$top=`. In de plaats: `meta.round` op `/rankings` (de speeldag waarop de stand geldt, null bij seizoensstart), `/rounds?calculated=1`, `/seasons/{id|current}/statistics`, `?limit=`.
- [x] `/rounds/{round}/matches` → `/rounds/{round}/games`; het model heet `Game`.
- [x] `GameResource`: de rotatie staat server-side. Per set `home`/`away` met `player_ids`, `score` en `bonus`, plus `is_played` en `winner`. De frontend leidde set 1 = 1+2 vs 3+4 enz. zelf af — domeinlogica die op één plek hoort. `player_ids` verwijst naar `players` in dezelfde payload in plaats van vier spelerobjecten zes keer te herhalen.
- [x] `RoundResource`: `games_count`, `players_present`, `players_drawn_out`. De site rekende "aanwezigen" uit als `games * 4`, wat fout is zodra er iemand uitgeloot is.
- [x] `RoundDetailResource`: `availabilityData` → `attendances`, mét de spelersnaam erbij, zodat een aanwezigheidslijst geen tweede call naar `/players` vraagt.
- [x] `gender` geeft de enum-waarde (`male`/`female`) in plaats van het legacy `Man`/`Woman`; `Gender::apiValue()` is verwijderd. De zaal-app gebruikte al `male`/`female`.
- [x] `?include=games,ranking_history` op `/players/{player}`, met `/players/{player}/games` en `/players/{player}/ranking-history` ook als eigen sub-resource (`ParsesIncludes`). Een onbekende include geeft 422 met de toegelaten lijst erbij.
- [x] `?members=0` op `/players` en `/seasons/{season}/statistics`. Nodig voor een pagina over een afgesloten seizoen: wie toen meedeed hoort in die eindstand, ook al is hij nu geen lid meer.
- [x] Archief-API mee omgezet (`?playerId=` → `?player_id=`, `?withMatches=1` → `?include=games`, `data`/`meta` overal), want die gaat de historiek-pagina's voeden.
- [x] `PublicCacheHeaders`: `Cache-Control: public, max-age=60` op de publieke GET's. De service worker van de site werkt network-first, dus dit vangt enkel herhaald opvragen binnen dezelfde minuut.
- [x] `bc-landegem.github.io` bij de CORS-origins; mag eruit zodra de site op het eigen domein staat.
- [x] Zaal-app: de tussenstand doet één call naar `/api/rankings` in plaats van twee. De `zaal/*`-payloads blijven bewust camelCase — interne vorm, één consument, moet woensdagavond werken, en levert de site niets op.
- [x] 24 contracttests in `PublicApiTest`, 7 in `ArchiveApiTest`; volledige suite 103 tests groen.

**Bevroren rank (`player_round_statistics.rank`).** De historische plaats werd bij elke opvraging opnieuw geteld uit de gemiddeldes, op twee plaatsen met verschillende filters: `RankingHistory` nam ook niet-leden mee, `RankingService` niet. Daarbovenop schoof ieders rank in álle voorbije speeldagen op zodra iemand de club verliet — "vijfde na speeldag 17" was geen vast cijfer. `SeasonCalculator::writeRanks()` bevriest nu de plaats op het moment van berekenen (één update per speeldag, want dit draait via `/api/deploy/migrate` op shared hosting); de migratie reconstrueert de bestaande speeldagen uit de opgeslagen gemiddeldes. Sindsdien zijn het twee scherp onderscheiden dingen: `/api/rankings` is de stand van vandaag en mag meebewegen met het ledenbestand, `rank` is wat het toen was.

**Beslissingen over de site zelf** (die repo staat elders, `bc-landegem/Website`):
- Huidig seizoen blijft **client-side** ophalen. Een hybride met build-time spelerspagina's vraagt een rebuild-trigger vanuit Laravel; dat is ducttape op een host zonder queue-worker, en de winst (mooiere URL's, SEO op spelerspagina's) weegt daar niet tegen op.
- Het **archief is bevroren** en mag dus wél build-time: 16 seizoenen als platte HTML, nul fetches, en de enige "trigger" is een gewone `git push`.
- Klassement en speeldagoverzicht blijven indexeerbaar; **spelerspagina's en speeldagdetail krijgen `noindex`** — wie de link heeft ziet ze, maar Google zet geen clublid met naam en aanwezigheidspercentage in de zoekresultaten.

**Tweede ronde (nog te doen):** afgeleide statistiek — partner- en tegenstanderbalans, dagscores per speeldag, clubrecords over 17 seizoenen — plus de pagina's die daarop leunen (historiek, erelijst, records). Vraagt eerst definities: telt een duo dat één set samen speelde als "samen gespeeld"?

`GameStatistics::averages` geeft de dagscore per speler per game, dus records als "hoogste dagscore" zijn berekenbaar zonder nieuwe kolom — maar wel met een pass over alle games, dus build-time voor het archief.

### Fase 9 — Afgeleide statistiek ✅ (afgerond 28-08)

De tweede ronde van fase 8. Twee stukken bleken géén definitie te vragen en zijn meteen gebouwd; voor de records zijn de keuzes expliciet gemaakt.

**Dagscore (`day_score`), niet opgeslagen.** Wat een speeldag voor een speler opbracht: het herschaalde puntengemiddelde over zijn drie sets, of het verliezersgemiddelde als hij afwezig was, of null als hij uitgeloot was zonder game. Staat nu bij `attendances` op `/rounds/{id}` en per speeldag in `ranking-history`. Bewust géén kolom: het is een pure functie van de zes setstanden, dus een kolom zou alleen een tweede waarheid zijn — anders dan `rank`, die van álle spelers samen afhangt en dus wél bevroren moet worden. Het maakt de formule navolgbaar: nagerekend op echte data is `(19,0057 + som van 17 dagscores) / 18 = 19,37`, exact wat de API als gemiddelde meldt.

**`?members=0` op `/rankings`.** Zonder dit filtert een klassement altijd op de huidige leden, ook dat van een afgesloten seizoen — en dan mist de erelijst van 2023-2024 36 van de 96 spelers die meededen. Bij de gelegenheid ook `whereNotNull('average')` in `rankingAfterRound`: met `members=0` komen er rijen bij van wie nooit berekend werd, en die hoorden niet met gemiddelde 0 onderaan te belanden.

**`/players/{player}/pairings`.** Eén rij per andere speler: aantal avonden samen, de set als partner en de twee sets als tegenstander. Dit stond in de vorige fase als "vraagt eerst definities: telt een duo dat één set samen speelde als samen gespeeld?" — **die vraag was gebaseerd op een verkeerde aanname.** Door de rotatie in `Game::LINE_UPS` speelt elke speler precies één set mét elk van de andere drie en twee sets tégen elk van hen. "Vaakste partner" is dus identiek aan "vaakst op dezelfde baan", en er valt niets te kiezen. De rotatie staat nu als constante op `Game` in plaats van in `GameResource`, want ook `Pairings` en `Records` hebben ze nodig.

**`/api/records`.** Vijf lijsten, alleen over het huidige format (2023-2024 →): `best_days`, `best_seasons`, `biggest_climbs`, `longest_streaks`, `most_played_duos`. De veertien oude jaargangen doen niet mee — vaste teams in best-of-3, dus een dagscore van toen is een ander getal. Dit is het enige endpoint waar `?season=` weglaten "alle seizoenen" betekent en niet het lopende; een clubrecord over één seizoen is er geen.

Drie dingen die pas uit de echte data bleken:

- **`best_days` heeft een tiebreak nodig.** Een dagscore van 21,00 betekent "alle drie de sets gewonnen" en komt vaak voor, dus de lijst was vijf keer 21,00 in willekeurige volgorde. Nu op puntensaldo: 63-28 boven 63-30 boven 63-31.
- **`biggest_climbs` mat eerst iets anders dan het beloofde.** Wie afwezig is krijgt het verliezersgemiddelde en zakt naar de staart; de eerste avond dat hij wél kwam leverde dan automatisch tientallen plaatsen op. De hele top bestond uit zulke sprongen. Nu tellen enkel sprongen waarbij de speler op béide speeldagen aanwezig was.
- **Vroeg in het seizoen ligt het veld op een kluitje**, dus de grootste sprongen komen structureel uit speeldag 1 → 2: +82 plaatsen met 0,55 punt erbij. Daar is geen willekeurige drempel voor verzonnen; `from_average`/`to_average` en `players_ranked` staan erbij zodat de site het getal in verhouding kan tonen.

**Bug gevonden en gedekt: `Collection::sortBy()` met closures.** Bij een array van sorteersleutels behandelt Laravel een closure als vergelijkingsfunctie `$fn($a, $b)`, niet als sleutelfunctie. Twee sorteringen deden daardoor stilzwijgend niets: de aanwezighedenlijst op `/rounds/{id}` stond in databankvolgorde en de partnerlijst negeerde het aantal avonden. Overal `[sleutel, richting]` nu. De twee tests die dit dekken zijn gecontroleerd door de oude sortering tijdelijk terug te zetten — beide falen dan, dus ze discrimineren echt.

**Nog te doen:** de pagina's zelf, in de `Website`-repo. Erelijst, historiek per seizoen, records, en de speeldag- en spelerspagina uitbreiden met wat de API nu geeft. Zie `docs/website-migratie.md`.

### Fase 10 — Includes op collecties ✅ (afgerond 28-08)

Terugkoppeling van de eerste echte consument (het build-script van de site): `?include=` werkte enkel op één resource. Op een collectie werd de parameter **stil genegeerd** — de kale lijst terug, en de rest dan alsnog per speler ophalen. Concreet 854 requests en ~175 s API-tijd waar 55 requests en ~20 s volstaan.

**Drie includes op collecties**, elk met exact dezelfde vorm als de losse resource:

- `/rounds?season={id}&include=attendances` — dezelfde `player_round_statistics`-rijen die `/rounds/{id}` al per speeldag serveert. Vervangt 325 spelerscalls door 3, en `is_present` staat er als echte boolean in, zodat aanwezigheid niet meer uit iemands wedstrijden afgeleid moet worden.
- `/archive/seasons/{id}/rounds?include=games` — 223 calls worden 14.
- `/archive/players?include=seasons` — 200 calls worden 1.

`RoundDetailResource::attendances()` is nu de enige plaats waar die rijvorm staat; een contracttest vergelijkt lijst en detail met elkaar, zodat ze niet uiteen kunnen lopen. Alles wordt in één keer ingeladen: een test laat zien dat drie speeldagen evenveel queries kosten als één.

**Stil negeren is weg.** `ValidateIncludes` hangt aan de hele publieke groep en kijkt `?include=` na tegen wat de route zelf opsomt (`->defaults('include', [...])` in `routes/api.php`). Onbekend geeft 422 met de toegelaten lijst erbij, ook op een route die géén includes kent. Dat is de eigenlijke fout die 800 requests kostte: niet dat de include ontbrak, maar dat niets waarschuwde. De toegelaten lijst staat daarmee op de route in plaats van als constante in de controller — één plaats, en die plaats leest als documentatie. `ParsesIncludes` leest alleen nog uit wat de middleware al goedkeurde.

6 contracttests erbij (124 groen).

### Fase 11 — Publieke geschiedenis afbakenen ✅ (API afgerond 31-08; site volgt in de `Website`-repo)

Na intern overleg: van alles vóór het lopende seizoen mag publiek nog maar drie dingen terugkomen — de **eindstand**, de **erelijst** en de **statistiek van wie nú lid is**. Voor de rest een zachte melding.

De regel, in één zin:

> **Eén regel uit een eindstand mag altijd — die regels van één persoon bij elkaar zetten mag alleen als die persoon nog lid is.**

Die formulering is er niet meteen. "Geen statistiek van ex-leden" klinkt eenvoudiger maar loopt vast op de eerste pagina waar je hem toepast: de eindstand van 2023-2024 had 96 spelers, van wie er nu 60 lid zijn. Daar 36 namen uit halen levert een stand met gaten op, plaatsen die van 12 naar 15 springen, en een kampioen die verdwijnt zodra hij de club verlaat. Een erelijst waar winnaars uit wegvallen is geen erelijst. **Namen blijven dus staan in een uitslag; wat dichtgaat is het dossier.**

**Wat publiek blijft**

| | |
|---|---|
| Lopend seizoen | alles, volledig ongewijzigd |
| Eindstand per seizoen, alle 17 | compleet, mét de namen van ex-leden — `?members=0` |
| Vijf kolommen per speler: plaats · gemiddelde · sets · matchen · aanwezig | dezelfde rijen als op de fiche, andere as |
| Erelijst en `/records`, incl. `most_played_duos` | compleet; een record is een onderscheiding, geen profiel |
| Fiche van een **huidig lid** | lopend seizoen volledig; geschiedenis enkel de seizoenstabel hierboven, niet openklapbaar |
| Fiche van een **niet-lid** | `403 not_a_member`, **zonder naam** in het antwoord |

**Drie deuren naar hetzelfde bestand.** De fiche afsluiten volstaat niet. Bij het doorlopen bleken er drie doorgangen die dezelfde geschiedenis langs een andere kant teruggeven, en elk daarvan maakt de gate cosmetisch zolang hij openstaat:

1. `GET /rounds?season={id}&include=attendances` — één call met de aanwezigheid **en de `day_score` van elke speler op elke speeldag** van een heel seizoen. Dat is een vollediger dossier dan de fiche zelf: wie er wanneer was, wat hij die avond scoorde, en dus zijn aanwezigheidspercentage en zijn vorm.
2. `GET /archive/players?include=seasons` — 192 spelers mét hun seizoenen in één call, waarvan er 114 vertrokken zijn. De eindstand gekanteld: niet per seizoen, maar per persoon.
3. `GET /players/{id}/games` over de 60 leden van 2023-2024 — een game verschijnt op de fiche zodra één van de vier nog lid is, dus zit er in zowat elke wedstrijd van dat seizoen minstens één. Ontdubbelen op `game.id` en de **volledige speeldaggeschiedenis** staat er weer, met alle namen en setstanden.

Een statische site is met één script leeg te halen, dus "je moet het wel willen vinden" telt hier niet als bescherming.

**Wat weggaat uit de publieke API**

| Weg | Reden |
|---|---|
| `/rounds`, `/rounds/{id}`, `/rounds/{id}/games` voor een afgesloten seizoen | speeldagen van vroeger staan niet in het lijstje van drie |
| `include=attendances` op `/rounds` buiten het lopende seizoen | deur 1 |
| `/archive/seasons/{id}/rounds`, `/archive/rounds/{id}` | idem, voor 2009-2023 |
| `/archive/players` (index) en `include=seasons` | deur 2 |
| `/players?members=0` — de index "iedereen die ooit meespeelde" | een bladerbare lijst van vertrokken leden |
| `/players/{id}/games`, `/ranking-history`, `/pairings` buiten het lopende seizoen | deur 3 |
| Diezelfde vier routes volledig, voor een niet-lid | `403 not_a_member` |

Die routes worden **verwijderd**, niet achter de gate geparkeerd. Een route die 403 geeft is een uitnodiging, en git onthoudt het wel.

**Waar de regel staat.** Niet verspreid over acht controllers, maar naast `ValidateIncludes` op de publieke groep: één middleware die het seizoen van de route bepaalt en alles wat niet het lopende is tegenhoudt, plus een `RequireMember` op de fiche-routes. De grens staat dan in `routes/api.php` te lezen als documentatie, net zoals `->defaults('include', …)` dat nu doet — precies de plek waar fase 10 geleerd heeft dat ze hoort. Daarnaast **`HistoryScopeTest`**: één bestand dat élke publieke route afloopt tegen een afgesloten seizoen en tegen een niet-lid, en vastlegt wat er terugkomt. Zonder dat bestand is de volgende handige include het gat weer open — dat is letterlijk hoe fase 10 ontstond.

**Bouwwijze: de gate in de API, de fiche client-side.** `is_member` is een boolean die beweegt (iemand stopt in oktober, komt terug in januari). Zet je de gate enkel in de site-build, dan is `/api/players/123` gewoon publiek op te vragen. Zet je hem enkel in de API, dan blijft de al gebouwde statische fichepagina staan tot iemand toevallig pusht — en een rebuild-trigger vanuit Laravel is bewust geen optie. De split loopt daarom langs de as die er echt toe doet:

- **uitslagen** (eindstand, erelijst, records) veranderen nooit meer → build-time, statisch, indexeerbaar;
- **status** (is deze speler nog lid?) beweegt → client-side fetch, `noindex`, klopt altijd op de seconde.

Gevolg voor de site: op de eindstand van 2023-2024 zijn **alle 96 namen klikbaar**, en 36 daarvan landen op de melding. Grijze namen zouden mooier zijn, maar de build weet niet wie er in maart nog lid is — een link die bij het bouwen klopte, beweert drie maanden later het verkeerde.

Daarbovenop een **`schedule:`-cron in de GitHub Actions van de `Website`-repo**, bijvoorbeeld nachtelijk. Dat is geen ducttape tussen twee diensten: Laravel weet er niets van en stuurt niets, de site haalt uit zichzelf op zoals ze bij elke push al doet. Het dicht het gat dat anders bij de seizoenswissel valt.

**De kanteling.** `Season::current()` is het laatst aangemaakte seizoen, dus het moment waarop een beheerder in Filament op "nieuw seizoen" klikt — één klik, die meteen ook de basispunten uit de eindstand toekent — klapt het vorige seizoen in dezelfde seconde dicht van *alles zichtbaar* naar *enkel de eindstand*. Dat is **hard, zonder overgangsperiode**: een uitzonderingsvenster is een tweede definitie van "huidig" op een tweede plaats, en de aanleiding voor deze hele beslissing was net minder publiek tonen. Wie de laatste speeldag nog even wil laten staan, maakt het nieuwe seizoen een week later aan — de regel zit dan in de agenda van het bestuur, niet in de code. De eindstandpagina van het net afgesloten seizoen bestaat pas na een build; daarvoor is de nachtelijke cron er.

**Wat níét verandert.** Filament blijft volledig: bestuur en beheerders zien achter de login elke speeldag van 2011 nog. De zaal-app werkt op het lopende seizoen en staat sowieso achter authenticatie. Fase 7 is geen verloren werk — het archief verhuist van "publiek" naar "intern, plus een eindstand".

**Wat het kost, eerlijk.** Het klassementsverloop per speeldag over voorbije seizoenen verdwijnt, ook voor leden en ook over hun eigen seizoen — dat was een van de leukere pagina's. De historiek-pagina's uit fase 8/9 worden kaler: per seizoen een eindstand in plaats van een doorbladerbaar seizoen. En twee van de drie collectie-includes uit fase 10 verliezen hun enige consument, drie weken nadat ze gebouwd zijn.

**Nog te doen**

- [x] `CurrentSeasonOnly` + `RequireMember`, per route aangehangen in `routes/api.php` — 31-08
- [x] Dode routes, resources en contracttests verwijderen (zie tabel), incl. de bijhorende includes
- [x] `403 not_a_member` met vaste body, zonder naam; 404 blijft voor "bestaat niet"
- [x] `/players/{id}` geeft voor een lid per afgesloten seizoen enkel de vijf kolommen (plaats, gemiddelde, sets, matchen, aanwezig) — dezelfde vorm als de eindstand
- [x] `HistoryScopeTest`: elke publieke route × afgesloten seizoen × niet-lid (14 tests)
- [x] `docs/website-migratie.md` herzien (§3, §7, §8, §11, checklist) — 31-08
- [ ] In `Website`: fiches client-side, eindstanden build-time, nachtelijke cron, speeldag- en spelerspagina's van vroeger eruit

**Vijf dingen die pas bij het bouwen beslist zijn.**

- **De grens hangt per route, niet op de groep.** De beslissing schreef "naast `ValidateIncludes` op de publieke groep", maar `/rankings?season={oud}&members=0` en `/seasons/{id}/statistics` moeten juist wél elk seizoen tonen — dát zijn de eindstanden. Op de groep zou de middleware precies datgene tegenhouden wat publiek moet blijven. Nu staat `CurrentSeasonOnly` op de drie `/rounds`-routes en op de vier fiche-routes, en `RequireMember` op die laatste vier. Het effect is hetzelfde en de routetabel leest nog steeds als documentatie.
- **De seizoensgrens geeft `403` met `error.code: season_closed`**, naast de `not_a_member` die al beslist was. Zelfde vorm, en een build-script dat op een afgesloten seizoen mikt ziet meteen waarom het faalt in plaats van een 404 die op een typefout lijkt. Een onbekend seizoen blijft 404, ook `?season[]=1`.
- **De fiche-gate staat ook op `/players/{id}` zelf**, niet enkel op de drie sub-resources: `?include=games,ranking_history` gaf anders langs dezelfde weg terug wat `/players/{id}/games` net tegenhoudt. Gevolg: de fiche bestaat enkel voor het lopende seizoen, en de geschiedenis komt uit `seasons` in diezelfde response.
- **`/archive/players/{id}` is óók weg, en de archiefseizoenen zitten nu in de seizoenstabel van `/players/{id}`.** Fase 12 noemde die route nog, maar zonder de index eronder was ze niet meer te vinden (de site kent enkel `players.id`, niet `archive_players.id`), en een aparte fiche voor het archief zou twee tabellen maken van wat één geschiedenis is. `PlayerSeasonHistory` zet beide generaties in één chronologische lijst met `is_archive` erbij; de seizoensnamen sorteren lexicografisch al op datum, ook met de inconsistente spatiëring in de echte data ("2022 - 2023" vs "2023-2024").
- **De plaats op de fiche komt uit de gepubliceerde eindstand, niet uit `player_round_statistics.rank`.** Fase 8 bevroor die rank op de leden van het moment van berekenen; de stand die de site toont staat op `?members=0` en bevat ook wie gestopt is. Wordt een oud seizoen later nog eens herrekend, dan verliest het ex-lid zijn plaats en schuift iedereen onder hem op — dan claimt de fiche een andere plaats dan de tabel waarnaar ze linkt. Fase 11 belooft letterlijk "dezelfde rijen, andere as", dus is `RankingService::finalStanding()` de enige bron. De bevroren rank houdt zijn rol binnen het lopende seizoen. `HistoryScopeTest` zet dat geval expliciet op en controleert eerst dát de twee bronnen daar verschillen — anders bewijst de assertie erna niets.

`include=attendances` op `/rounds` blijft bestaan voor het lopende seizoen: de speeldagpagina's van dit seizoen leunen erop, en de seizoensgrens op de route maakt deur 1 sowieso dicht. `docs/website-migratie.md` §11 leest alsof de include helemaal verdwijnt; de tabel hierboven ("buiten het lopende seizoen") is wat gebouwd is.

### Fase 12 — Wie hoort in een eindstand ✅ (afgerond 31-08)

Waarneming: de eindstand van 2022-2023 bevat zes spelers zónder gemiddelde. Nagekeken op de echte data, en het bleek een gat dat over beide generaties én over beide codepaden loopt.

De regel, in één zin:

> **Geen gemiddelde op de laatste (berekende) speeldag van een seizoen ⇒ die speler staat niet in de eindstand van dat seizoen, en dat seizoen staat niet op zijn fiche.**

**Het scharnier is de speeldagrij, niet de seizoensrij.** De aanvankelijke formulering was "wie geen seizoensstatistiek heeft, hoort niet in de stand" — maar die rij bestaat wél: 2022-2023 heeft er 85, ook voor de zes. Wat ontbreekt is hun rij op de láátste speeldag. Beide generaties stoppen simpelweg met speeldagrijen schrijven zodra iemand eruit is; dát is het spoor dat een uitschrijving achterlaat. In 2022-2023 staan er 85 rijen tot en met speeldag 10 en 79 vanaf speeldag 11.

**Twee groepen zonder gemiddelde**, en ze verdienen dezelfde behandeling:

| | rijen (archief) | wat het is |
|---|---|---|
| Nooit gespeeld | 240 | seizoensrij met basispunten, `rounds_present = 0`, geen enkele speeldagrij |
| Wel gespeeld, dan gestopt | 16 | speeldagrijen tot ronde N, dan niets — het uitschrijf-trucje |

2018-2019 is het ergst (61 van 142 namen), 2022-2023 met 6 juist een van de mildere. De vier `comp_`-seizoenen zijn niet geraakt: die hebben wél een `final_points`.

**Hetzelfde gat in het levende pad, met een ander gezicht.** `/api/rankings` eist al een speeldagrij mét gemiddelde (`RankingService::rankingAfterRound`), `/seasons/{id}/statistics` niet. In het archief is het één query, dus komt het eruit als `average: null`; in het levende pad zijn het twee endpoints, dus komt het eruit als twee tabellen van verschillende lengte:

| `?members=0` | `/rankings` | `/seasons/{id}/statistics` | na de regel |
|---|---|---|---|
| 2023-2024 | 96 | 96 | 96 |
| 2024-2025 | 95 | 111 | 95 |
| 2025-2026 | 88 | 121 | 88 |

Dat is precies de voorwaarde die fase 11 nodig heeft: de vijf kolommen (plaats · gemiddelde · sets · matchen · aanwezig) komen uit twee bronnen, en vandaag bestaat voor 33 rijen in 2025-2026 de helft van die kolommen niet.

**`members=1` verandert nergens** — 61 / 65 / 88 blijven wat ze zijn. De regel bijt uitsluitend op `?members=0`, dus de standaardweergave beweegt niet.

**De fiche volgt dezelfde verzameling.** Valt een seizoen uit de eindstand, dan valt het ook uit de seizoenstabel op de fiche. Anders claimt de fiche deelname die de eindstand ontkent, en fase 11 belooft letterlijk "dezelfde rijen als op de fiche, andere as".

**Wat niet doorgaat.** `is_member` blijft de handeling om iemand uit de lopende stand te halen; de data laat zien dat dat de laatste drie seizoenen al zo gebeurde (33× in 2025-2026). Geen "uitschrijven uit dit seizoen"-knop, geen per-seizoen stopmarkering, geen verplaatsing van de poort naar de seizoensrij — dat laatste zou een no-op zijn zolang `SeasonCalculator` op `is_member` filtert, want de échte poort zit daar en de rankingquery erft het resultaat gewoon.

**Wat het kost.** 240 archiefrijen "ingeschreven, nooit gespeeld" verdwijnen uit de eindstanden, 46 daarvan in 2018-2019 alleen. Ze hadden basispunten maar geen enkele speeldagrij; ze stonden toen ook nergens.

**`players_count` telt mee.** Dat veld staat publiek op `/seasons` en `/archive/seasons`, en fase 11 maakt die laatste net de publieke index van de historiek — het getal komt dus naast een link te staan die naar de eindstand leidt. Bleef het de rúwe seizoensrijen tellen, dan zou er "142 spelers" boven een tabel van 81 rijen komen zonder dat iets waarschuwt; precies het stille-afwijking-patroon waar fase 10 op stukliep. Het inschrijvingsgetal gaat daarmee verloren, en dat is aanvaard: 142 was voor 2018-2019 sowieso een misleidend antwoord, want 46 van hen speelden nooit één avond.

**Nog te doen**

- [x] `ArchiveStandings::forSeason()`: rijen zonder eindgemiddelde weg. Nagerekend op de echte data: 2022-2023 85 → 79, 2018-2019 142 → 81, comp-seizoenen onveranderd (55/73/82/79)
- [x] `SeasonController::statistics()`: dezelfde eis op de laatste **berekende** speeldag, zodat `/rankings?members=0` en `/seasons/{id}/statistics?members=0` gegarandeerd dezelfde rijen geven
- [x] Seizoenen zonder eindgemiddelde uit de seizoenstabel op `/players/{id}` (het archief zit daar nu mee in, zie fase 11)
- [x] Contracttest die de rijen van beide endpoints per seizoen tegen elkaar legt, met en zonder `?members=0`
- [x] `players_count` op `/seasons` en `/archive/seasons` telt de lengte van de eindstand. Gemeten: 96 / 95 / 88 levend; archief 55, 73, 82, 79, 62, 60, 51, 83, 80, 81, 89, 89, 72, 79

**Één verzameling, drie consumenten.** De eis staat niet als tweede query in de controller maar in `RankingService::finalStanding()`, en die geeft plaats + gemiddelde per speler in exact de vorm waarin `/rankings` ze publiceert. Daar hangen de drie plaatsen aan die het gelijk moeten hebben: de stand zelf, `players_count` op `/seasons`, en het `whereIn` van `/seasons/{id}/statistics`. Zouden ze elk hun eigen `whereExists` op de laatste berekende speeldag hebben, dan is de contracttest het enige dat ze samenhoudt — en fase 10 heeft geleerd hoe stil zoiets uiteenloopt. Aan de archiefkant doet `ArchiveStandings::query()` hetzelfde voor `forSeason()` en `countForSeason()`.

`players_count` staat daarom ook niet meer op een `withCount()`: de controller zet het getal per seizoen erbij. Dat kost twee queries per gearchiveerd seizoen op `/archive/seasons` — veertien seizoenen, bevroren data, build-time opgehaald en een minuut cachebaar, dus dat mag.

**Twee dingen om in het oog te houden**

- Een speler die in de zaal-app wordt toegevoegd heeft `average = null` tot de eerstvolgende herberekening, en is dus kort onzichtbaar in `?members=0`. Herberekening loopt bij score-invoer, dus binnen dezelfde avond.
- Het archief kent geen `is_calculated`; daar blijft "laatste speeldag op datum" het criterium.

### Fase 13 — Handicap per set zichtbaar (H/2-variant) ✅ (afgerond 03-09)

De intraclub speelt met een handicap: het verschil tussen de bonussommen van de twee duo's is de voorsprong waarmee het zwakkere duo aan een set begint. Tot en met 2025-2026 kreeg dat duo het hele verschil (6 → 6-0). Vanaf 2026-2027 wordt dat verschil **gesplitst**.

De regel, in één zin:

> **Het zwakke duo begint op `ceil(H/2)`, het sterke op `-floor(H/2)`.** Dus H=6 → 3 en −3, H=7 → 4 en −3. De afstand tussen de twee blijft exact H; alleen ligt ze nu rond nul in plaats van erboven.

**Waarom, en het is geen speelgevoel.** Het klassement draait op het getrimde puntengemiddelde per set (`GameStatistics::$averages` → `SeasonCalculator`), niet op gewonnen sets. Onder de oude regel gingen die gratis voorsprongpunten dus rechtstreeks in het seizoensgemiddelde van één kant. Gemeten over de 1737 sets met een stand in de productiedump:

| | punten die de regel per set injecteert |
|---|---|
| Volledige voorsprong aan het zwakke duo | **3,02** |
| H/2 | **0,50** (alleen nog de afronding bij oneven H) |

Op een schaal waar een seizoen met basispunten 19,0 begint, is drie punten per set geen detail: wie tegen sterke tegenstanders werd uitgeloot kreeg zijn gemiddelde gratis opgetild, en wie sterk was kreeg er niets voor terug. H/2 haalt die scheefheid er bijna volledig uit.

**De handicap verschilt per set, en dat is de hele reden dat dit per set moet.** De duo's roteren (1+2 vs 3+4, dan 1+3 vs 2+4, dan 1+4 vs 2+3), dus één wedstrijd heeft drie verschillende verschillen, in mogelijk drie richtingen. Van de 579 wedstrijden met een stand hebben er 449 in geen enkele set H=0.

| H per set | sets | aandeel |
|---|---|---|
| 0 | 213 | 12,3 % |
| 1–2 | 622 | 35,8 % |
| 3–5 | 660 | 38,0 % |
| 6–11 | 242 | 13,9 % |

Gemiddelde H = 3,02; hoogste ooit gemeten = 11.

**Negatieve setstanden hoeven niet ondersteund te worden.** Dat is de vraag waar dit op stond: als het sterke duo op −3 begint, kan de eindstand onder nul blijven, en `unsignedTinyInteger` / `min:0` / `PointsPerSet::allowsSet()` weigeren dat allemaal. Nagerekend in plaats van geraden — uit elke historische set de rally-winstkans van de sterke kant gehaald en die set opnieuw gesimuleerd onder H/2 (400 trials per set):

| | verwachte negatieve setstanden op 1737 sets |
|---|---|
| sets tot 21 | 0,0 (0,00 %) |
| sets tot 15 | 0,4 (0,03 %) — ±1 per 109 speeldagen |

Zelfs bij sets tot 15 is dat één keer per zes seizoenen. Het scoremodel blijft dus onaangeroerd: geen migratie, geen wijziging aan `allowsSet()`, `isPlayableSet()`, `directWins()` of de validatie in `ZaalController`. Als dat ene geval ooit opduikt, tikt iemand 15–0 in.

**De regel staat server-side, niet als seizoenskolom.** `points_per_set` moést een seizoensattribuut worden omdat er mee gerekend wordt — `trim()` herschaalt ermee, `startingBasePoints()` hangt ervan af. De handicapverdeling raakt géén enkele berekening: ze komt niet in `SeasonCalculator`, niet in `GameStatistics`, niet in de validatie, en bepaalt geen enkel bewaard getal. Ze bepaalt alleen hoe vier mensen hun bord zetten. Een kolom die door niets gelezen wordt behalve een label is een instelling die je later moet uitleggen. Dus één plek server-side, uitgeleverd via beide payloads: `ZaalController::gamePayload()` voor de zaal en `GameResource` voor de site, waar al `bonus` per setkant staat.

**Het historische-eerlijkheidsprobleem lost zich op met één regel.** Seizoen 2025-2026 werd met de volle voorsprong gespeeld; een oude speeldag tonen met "4 om −3" zou een leugen zijn over hoe die set gespeeld is. Dat is precies waarom een seizoenskolom aantrekkelijk lijkt. Maar:

> **De startstand verschijnt alleen bij een set zonder score.**

Een gespeelde set toont zijn echte cijfers, een ongespeelde zijn startstand. Alle historische data is compleet, dus daar verschijnt de startstand per constructie nooit — en op het invulscherm verdwijnt hij per set zodra die ingevuld is. Geen kolom, geen `isToday`-controle, geen vlag.

**Waar je het ziet, en waarom niet gewoon op het invulscherm.** Elk oppervlak van de zaal-app staat ná de wedstrijd: de naam-tik gaat bij een open wedstrijd rechtstreeks naar "Wie won set 1?", en `/uitslagen` maakt een wedstrijd zónder score bewust niet aantikbaar. De startstand moet je vóór het spelen weten, dus er is een leespad nodig. Dat bestond al bijna: `/uitslagen` lijst álle wedstrijden met de vier namen, alleen was de tik dood.

- **`/uitslagen` wordt `/wedstrijden`**, tegel en kop worden "Wedstrijden van vanavond". Dat woord was al lichtjes fout — het scherm lijstte ongespeelde wedstrijden met "nog geen score" — maar zolang die rijen dood waren viel het niet op. Als ze het enige pad naar de startstanden worden, is "Uitslagen" actief misleidend.
- **Lege wedstrijden worden aantikbaar naar `peek`.** Dat opent géén invoerpad: invullen vereist `me() !== null`, wat een `speler/:playerId`-segment in de route vereist. De vrees in het oude docblock van `Results` ging over scores, en `peek` heeft geen wijzigknop. De grove regel ("leeg = niet aantikbaar") wordt de fijne ("leeg = aantikbaar, maar alleen zonder naam in de URL").
- **`peek` krijgt een vijfde gedaante:** scorebordvorm, duonaam links, groot getal rechts, twee regels per set. Eén regel uitleg bovenaan het scherm, géén label per set — drie keer hetzelfde woord boven drie identieke blokken is ruis. De klassementstelling verdwijnt daar, want bij een ongespeelde wedstrijd is er niets te tellen.
- **`score-entry` krijgt de startstand op de setregel** in inline vorm ("begint op 4"). Daar is het geen speelinformatie meer maar een controlemiddel: klopt de stand die ik intik met de set die we gespeeld hebben?

Bij H=0 staat er **0 en 0**, bij H=1 **1 en 0**. Altijd twee getallen op dezelfde plek: 12,3 % van de sets zou anders een uitzondering zijn die de speler ter plekke moet interpreteren.

**Wat niet doorgaat.** Geen seizoenskolom voor de variant. Geen extra stop in het invulpad — de naam-tik blijft rechtstreeks naar de score gaan, want wie zijn score komt intikken kent zijn startstand al. Geen ruwe bonussommen naast de startstand (9 tegen 10 tonen náást 4 en −3 nodigt uit tot narekenen). En géén startstanden per set op het wedstrijdenscherm van de organisator: H≥10 komt in 0,6 % van de sets voor, te weinig om die lijst dichter te maken. De **bonuspunten per speler** staan daar wél bij, ook in de afwerklijst — die roept de organisator om bij het afkondigen van een match, en ze stonden al bij de voorstellen en bij wie uitgeloot is. Ze wegdenken zodra een match bevestigd was, betekende dat net de lijst waaruit omgeroepen wordt ze niet had.

**Nog te doen**

- [x] `App\Services\Handicap`: per set de twee startstanden uit de bonussommen, `ceil`/`floor` zoals de regel
- [x] `start` per setkant in `ZaalController::gamePayload()` en in `GameResource::side()`, naast `score` en `bonus` — null zodra de set gespeeld is, dus de regel staat in de payload en niet in twee frontends
- [x] `SetSide.start` in `zaal/src/app/core/models.ts`
- [x] `/uitslagen` → `/wedstrijden` in `app.routes.ts`, `kiosk.html`, `results.html` en `Match::close()`; docblock van `Results` herschreven
- [x] Lege wedstrijden aantikbaar in `results.html` ("moet nog gespeeld worden" in plaats van "nog geen score")
- [x] `match-recap`: scorebordvorm voor sets zonder score, tellingsectie verborgen bij een onvolledige wedstrijd, koptekst voor het nieuwe geval. Mintekens zijn U+2212, niet het koppelteken — op anderhalve meter is dat het verschil tussen een getal en een streepje
- [x] `score-entry`: "begint op 4 tegen −3" op de setregel van elke nog niet ingevulde set
- [x] Tests: `HandicapTest` (splitsing, richting, afstand blijft H, injectie blijft 0 of 1), `start` in beide payloads via `ZaalApiTest` en `PublicApiTest`. Volledige suite groen: 340 tests
- [x] Bonuspunten per speler in de afwerklijst van `admin/games`

### Fase 14 — Gebruikersreview zaal-app (03-09)

Eerste review door een clublid dat de app op een speeldag gebruikt heeft. Zeven punten,
maar geen zeven losse fixes: drie ervan botsen op een productprincipe, en één verbergt
een vraag die nog nergens beslist was. Wat hier staat is de uitkomst van dat gesprek —
de redenering, niet enkel de wijziging.

**De handicap is niet afroepbaar, en dat is een antwoord, geen tekort.** De vraag was of
de organisator de handicap mee moet omroepen bij het afkondigen van een match. Dat kan
niet: `Handicap::between()` rekent per set met de bonussommen van dát duo, en de duo's
roteren drie keer. Eén wedstrijd is dus geen getal maar zes. Fase 13 had het antwoord al
gebouwd — `/wedstrijden` is het leespad — maar de rij die erheen leidt zei
`moet nog gespeeld worden`, in accentoranje, gestippeld. De opmaak beloofde iets en de
copy sprak haar tegen. De copy wint, want mensen lezen. Dus: de rij nodigt uit, het bord
blijft één tik verder, en de organisator roept nummer en namen zoals hij altijd deed.

**"Beheer" was een bezet woord.** PLAN.md noemt Filament "het beheerspaneel", PRODUCT.md
noemt die gebruiker "Beheerder". De reviewer las het correct en belandde op de verkeerde
verwachting. `Spelers` en `Loting` — zijn eigen voorstellen — vallen allebei af: het
eerste trekt spelers aan naar een scherm dat niet voor hen is, het tweede liegt vanaf het
moment dat de loting gebeurd is en diezelfde deur de afwerklijst wordt. Elk label dat een
ínhoud noemt nodigt uit tot tikken. **"Organisator"** noemt het publiek, en dat is het
enige soort label dat tegelijk de juiste persoon binnenlaat en de rest wegduwt. Route,
map en klasse verhuizen mee: `Admin` in de zaal-app benoemde de verkeerde persoon.

**De luide knop was een toestandsfout, geen plaatsingsfout.** De knop in de balk is al
44px en transparant. De 68px oranje knop stond in de lege toestand van de kiosk — en die
toestand is geen randgeval maar de openingstoestand van élke speeldag, ruwweg het eerste
kwartier, precies wanneer de meeste mensen rond de tablet staan. Dat paneel adresseerde in
drie zinnen twee publieken. Nu spreekt het alleen de speler aan; de organisatordeur is de
hele avond dezelfde knop op dezelfde plek.

**"Dit scherm sluit vanzelf" beloofde schermgedrag dat overal geldt.** Er is geen
sluitmechanisme op de bevestiging — er is één terugvalklok in `Zaal` die op elk scherm
loopt. De tussenstand valt ook terug, zonder één woord uitleg, en daar heeft nog nooit
iemand iets over gezegd: een vangnet kondig je niet aan, want zodra je het aankondigt is
het een belofte en gaan mensen erop wachten. Gekozen is om de belofte dan ook waar te
maken: op `bewaard` telt dezelfde klok in 30 s af in plaats van 120, en hij is zichtbaar
als een haarlijn onder de kop. Geen cijfer en geen balk op de `Klaar`-knop — een primaire
knop die volloopt leest als "wacht", niet als "je mag weg". Elke aanraking zet hem terug,
want het ís `keepAwake()`, niet een tweede klok.

**Editen vanuit het overzicht gaat rechtstreeks.** Er zat eerst een naamtik tussen — vier
namen, "wie ben je?" — als bevestiging, omdat `score-entry` bij élke tik bewaart zonder te
vragen en wie op de verkeerde wedstrijd landt dus een score van een ander groepje kan
overschrijven. Dat is het faalgeval uit PRODUCT.md. Bij het uitproberen bleek die stap
overbodig, en bij nader inzien terecht: **het invulscherm opent bij een volledige wedstrijd
dicht.** `activeIndex` zoekt de eerste set zónder score, en die is er niet, dus staan er
drie afgeronde regels en is er niets open. Overschrijven vraagt een tik op "wijzig" en dáárna
nog twee (winnaar, dan stand), waarvan de eerste niets bewaart. De drempel zat al in het
scherm; er hoefde er geen tweede voor.

Wat blijft: de speler in het pad is een **aanspreking, geen recht**. Invullen mocht altijd al
door elk van de vier — `Elk van de vier spelers kan de score van dezelfde wedstrijd invullen`
staat zo in PRODUCT.md — dus elke gedaante bestaat nu twee keer, met en zonder
`speler/:playerId`. Met naam: "Jouw wedstrijd — Jan, …" en Klaar gaat terug naar de zaal.
Zonder: "Wedstrijd 3 — Jan, Piet, …" en Klaar gaat terug naar het bord waar je vandaan kwam.
Daarmee vallen `peek` en `read` samen: als elk wedstrijdscherm dezelfde knop draagt, is het
verschil tussen kijker en deelnemer alleen nog een geaccentueerde naam in de telling.

**Sets in willekeurige volgorde was de enige echte onmogelijkheid.** De setnummers zijn
paringen, geen tijdstippen (set 2 = P1+P3), dus vier spelers die met een andere paring
beginnen botsen op de app. En wie er maar twee speelt kan set 1 en 3 niet invullen met een
gat ertussen. `redo()` bestond al — hij werd alleen niet aangeboden op een wachtende set.
De automatische doorloop blijft, want negen op de tien viertallen spelen gewoon op volgorde.

**Het lettersysteem blijft.** Bij 44-48 aanwezigen zijn dat twee tikken zonder schuifbalk;
één lijst van 46 namen scrolt, en scrollen is een van de drie klachten waarmee dit dossier
begon. De puntenknoppen tonen enkel het getal van de verliezer omdat 90,7 % van de sets
exact op het setmaximum eindigt. Beide vormen zijn al door de data gedekt; "moest wat
wennen" is gewenning, geen ontwerpfout.

**Nog te doen**

- [ ] `beheer` → `organisator`: route, map `pages/zaal/admin/` → `pages/zaal/organisator/`, klasse `Admin` → `Organisator`, labels en `isAdmin` → `isOrganiser`
- [ ] Lege kiosktoestand spreekt alleen de speler aan; de grote knop naar de organisator verdwijnt, de tussenstand blijft de ene bruikbare bestemming
- [ ] Terugvalklok route-afhankelijk (30 s op `bewaard`, 120 s elders) en zichtbaar als haarlijn in `zaal.html`; de zin in `match-recap` verdwijnt
- [ ] `RecapMode` wordt `confirm | recap`; `peek` en `read` vallen samen, `match.ts` verliest een gedaante
- [x] Elke gedaante bestaat met en zonder `speler/:playerId`; `score-entry` werkt zonder speler (kop en uitweg passen zich aan), wijzigknop op elk wedstrijdscherm. Een tussenscherm met de vier namen is geprobeerd en weer weg: het invulscherm opent bij een volledige wedstrijd al dicht
- [ ] Afwerklijst in `organisator/games` wordt aantikbaar naar hetzelfde wedstrijdscherm
- [ ] Wachtende setregel in `score-entry` wordt aantikbaar (`redo`)
- [ ] `moet nog gespeeld worden` → een uitnodiging naar het bord

**Openstaand, niet in deze fase.** Een sterk duo begint op `−floor(H/2)`; bij H=14 (twee
vrouwelijke recreanten tegenover twee klassementsspelers) is dat −7. Scoren ze in die set
minder dan 7 punten, dan is hun bordstand negatief — een stand die `directWins()` niet
aanbiedt en die `isPlayableSet()` aan beide kanten weigert. De handicapregel kan dus een
stand produceren die de invoer niet kan opslaan. Zeldzaam, maar geen theorie, en de fix
raakt de serverkant. Eigen ronde.

### Fase 15 — Aanwezigheid is zelfbediening (03-09)

Uit dezelfde review, maar het is geen detail: **iedereen duidt zichzelf aan.** Daarmee
verandert het publiek van de aanwezigheidslijst, en dus waar ze hoort te staan.

**Het beginscherm ís voortaan dat blad.** Zolang er geen wedstrijden zijn toont `/` de
aanwezigheidsvinder; zodra er geloot is wordt het de namenvinder. Er is geen omleiding
voor nodig — "Speeldag van vandaag starten" laat het kader gewoon de outlet renderen en
die staat dan op het juiste scherm. Dat vervangt meteen het paneel uit fase 14 dat zei
dat er niets te doen was: het eerste kwartier van de avond heeft nu een taak in plaats
van een mededeling.

**Waarom een letterraster en niet de lijst die er al stond.** De club telt 88 leden. In
`repeat(auto-fill, minmax(240px, 1fr))` is dat op 1024px vier kolommen en 22 rijen — drie
à vier schermvullingen scrollen, plus een zoekveld dat een tabletklavier opent. Zolang die
lijst van de organisator was, was dat prima: hij gaat ze één keer af en hij weet hoe.
Vanaf het moment dat 46 mensen er hun eigen naam in zoeken is het hetzelfde probleem dat
`player-finder` al oplost, en die docblock is er stellig over: *"Twee tikken, en geen van
beide schermen scrollt."* Het zou vreemd zijn om dat op het drukste scherm van de avond
te negeren.

Dus twee wegen naar dezelfde tegels: de speler zoekt **zichzelf** (voorletter, dan een
handvol namen), de organisator zoekt **wie ontbreekt** (volle lijst, zoekveld). Een
letterraster verbergt 84 van de 88 namen en zit een overzichtstaak dus juist in de weg.
`AttendanceList` draagt de tegel en de incheck-animatie; hoe je bij die tegel komt staat
erbuiten.

**De terugkeer na een tik is geen versiering.** De tegel is een toggle
(`setAttendance(id, !present)`), en bij zelfbediening is dat scherper dan bij één
organisator: een tweede tik op je eigen groene tegel zet je er weer áf, stil, zonder
animatie — en dat merkt niemand tot de loting iemand mist. Na een geslaagde incheck keert
het scherm daarom na 1,1 s terug naar de letters: lang genoeg om de veeg en het vinkje te
zien, en daarna is er geen tegel meer om per ongeluk twee keer aan te raken. Afvinken doet
dat niet — dat ís een correctie, en dan wil je blijven staan.

**Nog te doen**

- [x] `AttendanceList`: alleen het tegelraster, met `players` als input en een `groot`-variant (84px) voor het spelersscherm, dat na een letterraster van 104px komt
- [x] `AttendanceFinder`: voorletter → namen, teller "n aanwezig" naast de vraag, terugkeer naar de letters na een incheck
- [x] `Kiosk` toont de vinder zolang `games()` leeg is; het lege paneel en de knop naar de organisator zijn weg
- [x] `Attendance` (organisator) houdt zoekveld, "+ Nieuwe speler", "Loten" en "Afmelden"

**Openstaand.** Na de loting is `/` de namenvinder, dus een laatkomer kan zichzelf daar
niet meer aanduiden. Dat pad bestaat wel — "Match aanvullen" zet wie je kiest meteen
aanwezig — maar het loopt via de organisator. Of dat volstaat, blijkt op een speeldag.

## Lokale dev-omgeving (opgezet 26-08-2026)

- PHP 8.4.24 via winget (`%LOCALAPPDATA%\Microsoft\WinGet\Packages\PHP.PHP.8.4_...`), php.ini met pdo_mysql/mbstring/intl/curl/zip/gd/opcache. Oude PHP 7.4 staat nog op `C:\tools\php74` maar is uit de PATH gehaald. **VS Code/terminal herstarten om de nieuwe PATH op te pikken.**
- MariaDB 12.3 als Windows-service `MariaDB` (root, geen wachtwoord — enkel lokaal). Client-tools in PATH.
- Databases: `intraclub` (nieuwe app) en `intraclub_legacy` (import van productie-dump `bclandegem_intraclub.sql`; dumps staan in de root en zijn ge-gitignored).
- Laravel 12 + Filament 5 in `/app`, `.env` wijst naar `intraclub`. Start: `cd app && php artisan serve` → admin op http://localhost:8000/admin (login: lenmartens@gmail.com / intraclub-dev). Wachtwoord wijzigen: `php artisan intraclub:set-password`.

## Risico's

1. **Deadline (±11 werkdagen werk, seizoen start begin september).** Mitigatie: fase 4 en 5 kunnen na speeldag 1 (oude systeem blijft werken; datamigratie is herhaalbaar). Minimum voor cutover = fasen 0-3.
2. **Rekenverschillen.** De regressietest is de poortwachter — geen cutover zonder 0-diff.
3. **Shared-hosting-verrassingen** (mod_security, memory limits, geen `proc_open` voor bepaalde packages). Mitigatie: fase 0 test dit vroeg; Laravel draait bewezen op DirectAdmin shared hosting.
4. **Wachtwoord-reset vereist mail.** Shared hosting heeft meestal SMTP; anders: admins beheren wachtwoorden via Filament-gebruikersbeheer (gekozen scope dekt dit).

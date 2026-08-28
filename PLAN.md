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
- [ ] Optioneel: 2010-2011 heeft wél uitslagen maar geen bewaarde eindstand. Uit de wedstrijden valt een gedeeltelijke stand af te leiden (sets, punten, matchen) — zonder klassementsgemiddelde, want dat vraagt de rekenlogica van toen
- [x] **Verwijderde comp-spelers worden "Onbekende speler"** in plaats van overgeslagen. De uitslagen zijn wel degelijk gespeeld; alleen wie er speelde is niet meer te achterhalen. Elk onbekend bron-id krijgt een eigen archiefspeler (8 stuks) — niet één gedeelde, want twee wedstrijden hebben méér dan één onbekende en die zouden anders als dezelfde persoon in dezelfde match belanden. Levert 21 wedstrijden en 21 seizoensstatistieken extra op.
- [x] **`php artisan intraclub:merge-duplicates`**: voegt dubbel aangemaakte spelers samen op basis van `player_dubbels` uit `player-map-overrides.php`. David Inghels (36 ← 132) en Lieselot Van Haute (72 ← 145) hebben nu 15 en 22 wedstrijden op één fiche in plaats van 12+3 en 21+1.
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

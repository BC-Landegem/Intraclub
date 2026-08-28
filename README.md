# Intraclub BC Landegem

Klassement- en wedstrijdbeheer voor de intraclubcompetitie van BC Landegem.
Live op **https://intra.bclandegem.be** — beheerspaneel op `/admin`, zaal-app op
`/zaal`, publieke JSON-API op `/api` (die de clubsite uitleest).

Dit vervangt een PHP 7.4-API met losse HTML-pagina's. Die oude code staat nog in de
repo en verdwijnt een paar stabiele speelweken na de cutover.

## Wat staat waar

| Map | Wat | Status |
|---|---|---|
| `app/` | Laravel 13 + Filament 5: beheerspaneel, publieke API, rekenlogica, en serveert ook de zaal-app | actief |
| `zaal/` | Angular 22 zaal-app (invoer op het zaaltoestel). `ng build` schrijft naar `app/public/zaal/` | actief |
| `web/` | referentiekopie van de Joomla-pagina's — die worden in een **ander** project beheerd, hier niet aanpassen | referentie |
| `api/`, `api-experimental/`, `intra-app/` | het oude systeem (PHP 7.4 / Slim) | dood |
| `.github/` | deploy-workflows en -scripts, zie [DEPLOY.md](DEPLOY.md) | actief |

Wat je aanpast zit dus altijd in `app/` of `zaal/`.

## Vereisten

| | Versie | Waarom |
|---|---|---|
| PHP | **≥ 8.4.1** | `composer.lock` bevat Symfony 8 (via Laravel 13) met `php: >=8.4.1` als harde require. In `composer.json` staat nog het lossere `^8.3` — dat volstaat niet. |
| Composer | 2.x | |
| MariaDB | 10.6 of nieuwer | productie draait 10.6, lokaal 12.3 |
| Node | 22 of nieuwer | enkel nodig voor `zaal/` |

PHP-extensies: `pdo_mysql`, `pdo_sqlite`, `mbstring`, `intl`, `curl`, `zip`, `gd`,
`openssl`, `fileinfo`, `tokenizer`, `dom`. **`pdo_sqlite` is niet optioneel** — de
testsuite draait op sqlite `:memory:`, ook al gebruikt de app zelf MariaDB.

Op Windows: PHP via `winget install PHP.PHP.8.4` (daarna je terminal herstarten voor de
nieuwe PATH), MariaDB via de installer van mariadb.org. De commando's hieronder zijn
identiek op macOS en Linux; alleen het installeren verschilt.

## Van nul naar draaiend

```bash
git clone <deze repo> && cd Intraclub

# 1. Databank aanmaken
mysql -u root -e "CREATE DATABASE intraclub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Laravel opzetten: composer install, .env kopiëren, APP_KEY, migrations
cd app
composer setup
```

Zet in `app/.env` je `DB_USERNAME`/`DB_PASSWORD` als je root niet zonder wachtwoord
gebruikt. De rest van `.env.example` klopt voor een lokale opzet.

```bash
# 3. Een beheerder aanmaken — zonder dit kan je niet inloggen op /admin.
#    Iedereen in de users-tabel is beheerder; er is geen rollenmodel.
php artisan make:filament-user

# 4. Starten
php artisan serve            # of `php artisan dev`, dat start ook de queue-worker
```

Je hebt nu een werkende, **lege** app op http://localhost:8000/admin. Zonder spelers,
seizoenen en speeldagen valt er weinig te testen — zie de volgende sectie.

Let op: `intraclub:set-password` **wijzigt** het wachtwoord van een bestaande gebruiker
en kan er geen aanmaken. Voor de eerste gebruiker is `make:filament-user` het commando.

## Data in je lokale databank

De echte data komt uit een dump van productie. Die dumps staan **niet** in de repo en
komen er ook niet in: deze repo is publiek en de dumps bevatten ledennamen,
geboortedatums en wachtwoordhashes. `.gitignore` blokkeert daarom alle `*.sql`.

Vraag de twee dumps op bij Lennart en behandel ze als persoonsgegevens: niet
doorsturen, niet in een issue plakken, na gebruik verwijderen. Daarna draai je de
importketen. Die is herhaalbaar, duurt ongeveer 20 seconden vanaf lege databanken, en
de **volgorde ligt vast** — `intraclub:import-legacy` wist `players`, waar het archief
aan hangt.

De keten met de exacte commando's en de verwachte uitvoer per stap staat in
[PLAN.md, fase 6](PLAN.md). Ze wordt hier niet herhaald, zodat er één versie van bestaat.

De importcommando's lezen uit de connecties `legacy` en `archive` in
[app/config/database.php](app/config/database.php). Die staan hard op de mariadb-driver
— vandaar dat sqlite lokaal geen optie is — en vallen zonder `.env`-instellingen terug
op `root` met een leeg wachtwoord. Heb je wél een root-wachtwoord, zet dan de
`LEGACY_DB_*`- en `ARCHIVE_DB_*`-regels die als commentaar in `.env.example` staan.

Er is voorlopig geen demo-seeder met verzonnen data. Wie zonder productiedump wil
werken, klikt spelers en een seizoen bij elkaar in Filament.

## Dagelijks werken

**Laravel (`app/`)** — `php artisan serve`, en verder de gewone artisan-commando's.
Er is géén frontend-buildstap: Filament levert zijn eigen assets via
`php artisan filament:assets`, en er staat nergens een `@vite`-directive in de app. Zoek
dus geen `npm` in `app/` — die is er niet.

**Zaal-app (`zaal/`)** — twee terminals:

```bash
cd app  && php artisan serve            # terminal 1: de API op :8000
cd zaal && npm install && npm start     # terminal 2: ng serve op :4200
```

De app opent op **http://localhost:4200/zaal/** (`baseHref` staat op `/zaal/`).
`proxy.conf.json` stuurt `/api` en `/sanctum` door naar `:8000`, waardoor de
sessiecookie van de Sanctum-login same-origin blijft en inloggen gewoon werkt.

Wil je de zaal-app zien zoals in productie, dan `npm run build` in `zaal/`: dat schrijft
naar `app/public/zaal/` en je surft naar http://localhost:8000/zaal/. Die map is
ge-gitignored — CI bouwt ze bij elke deploy opnieuw.

**Debuggen** — `.vscode/launch.json` bevat "Listen for Xdebug" (poort 9003). Start die,
draai ernaast `php artisan serve`, en zet een breakpoint. Xdebug installeer je via de
[wizard](https://xdebug.org/wizard), plus de PHP Debug-extensie in VS Code.

## Tests en stijl

```bash
cd app
php artisan test --compact          # 88 tests, ~15 s
php artisan test --filter=ZaalApiTest
vendor/bin/pint --dirty             # formatteert enkel wat jij gewijzigd hebt
```

Drie dingen om te weten:

- De testsuite negeert je `.env` volledig: `phpunit.xml` forceert sqlite `:memory:`.
  Je lokale databank wordt dus nooit aangeraakt.
- **De zaal-app heeft geen tests.** `npm test` in `zaal/` bestaat als script, maar er is
  geen enkel `.spec.ts`-bestand. CI bouwt de Angular-app wel, maar test ze niet.
- CI draait `pint` niet, en `pint --test` faalt momenteel op zes bestanden die al langer
  zo in de repo staan. Gebruik daarom `--dirty` en laat de rest ongemoeid.

## GitHub-flows

Twee workflows, beide in [.github/workflows](.github/workflows).

### Werkwijze: branch → PR → merge

**Werk niet direct op `main`.** Een push naar `main` bouwt en deployt binnen enkele
minuten naar de live clubsite — er is geen staging en geen handmatige goedkeuring.

```bash
git switch -c speeldag-vandaag
# ... werken, testen ...
git push -u origin speeldag-vandaag
gh pr create
```

Een PR draait alleen de **testjob**, niet de deploy. Merge pas als die groen is.
Er staat bewust geen branch protection op `main`: bij een hotfix vlak voor een speeldag
moet je erlangs kunnen. De regel is dus een afspraak, geen slagboom — houd je eraan.

Wijzig je enkel `*.md` of `.vscode/**`, dan slaat de workflow over (`paths-ignore`).

### Wat de deploy doet

De hosting is DirectAdmin shared hosting: **geen SSH, geen composer, geen artisan**.
Alles wordt daarom in CI gebouwd en dan over FTP gesynchroniseerd:

`tests` → `composer install --no-dev` + `filament:assets` + `ng build` → **FTP-sync** →
`migrate` → `optimize` → rooktest op `/api/rankings`

Die laatste drie stappen kunnen niet via de commandolijn op de server, dus ze gaan over
HTTP naar `POST /api/deploy/{migrate|optimize|clear}`, achter een Bearer-token. Zonder
dat token in de server-`.env` geven die routes 404.

Twee eigenaardigheden die je moet kennen:

- De FTP-tool **verwijdert** bestanden op de server die niet in de bron zitten. Wat de
  server zelf bezit — de productie-`.env`, de logs, de databank-snapshot — staat daarom
  in [.github/ftp-exclude.txt](.github/ftp-exclude.txt). Haal daar niets uit.
- De rooktest draait *na* de upload en *na* de migrations. Een rode deploy betekent dus
  een stukke live site, niet een afgebroken deploy.

Secrets instellen, de eerste (lange) sync en de databank-reset: zie [DEPLOY.md](DEPLOY.md).

### Als een deploy faalt

```bash
git revert <sha>
git push          # de sync zet de bestanden terug, optimize herbouwt de cache
```

**Code rolt terug, migrations niet.** Een `revert` zet je PHP-bestanden terug, maar een
migration die al gedraaid heeft blijft gedraaid — en een migration die een kolom heeft
gedropt, geeft die daarmee niet terug. Voor schemawijzigingen is de databank-snapshot je
enige net. Denk daar dus vóór het pushen aan, en start bij een risicovolle wijziging
eerst handmatig met **dry_run** aan om te zien wat er zou uploaden.

### De reset-workflow

"Databank resetten" zet de productiedatabank terug naar een snapshot, met drie
onafhankelijke remmen. Na de cutover moet die knop dood: vanaf het moment dat er in de
zaal ingevoerd wordt, is een reset dataverlies. Procedure in [DEPLOY.md](DEPLOY.md).

## AI-tooling in deze repo

`CLAUDE.md` en `AGENTS.md` (in de root en in `app/`) zijn richtlijnen voor AI-agents,
gegenereerd door [Laravel Boost](https://laravel.com/docs/ai) — `boost.json` bepaalt wat
erin komt, dus wijzig ze niet met de hand. `.claude/skills/` en `.agents/skills/` bevatten
skills (Angular, Laravel-conventies, testen), `skills-lock.json` pint hun versie, en
`.mcp.json` start de Boost-MCP-server. Het `.ai/rules`-blok in `app/CLAUDE.md` is
voorwaardelijk geformuleerd: die map bestaat hier niet, dus agents slaan hem over.

Niets hiervan is nodig om te ontwikkelen, en niets gaat mee naar de server — het staat in
de exclude-lijst van de sync.

## Verder lezen

- [PLAN.md](PLAN.md) — beslissingen, fasen, datamodel, de datamigratieketen, risico's
- [DEPLOY.md](DEPLOY.md) — secrets, eerste sync, deploy-taken, databank-reset, cutover
- [LICENSE](LICENSE) — MIT

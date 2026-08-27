# Deployen en resetten

Twee workflows in [.github/workflows](.github/workflows):

| Workflow | Trigger | Doet |
|---|---|---|
| `Deploy` | push naar `main`, of handmatig | tests → composer/ng build → FTP-sync → `migrate` → `optimize` → rooktest |
| `Databank resetten` | enkel handmatig, met bevestiging | tabellen kopiëren → wissen → snapshot herladen → `migrate` → `optimize` → rooktest |

Er is geen SSH op de host, dus wat lokaal een artisan-commando is, gebeurt over
HTTP: `POST /api/deploy/{migrate|optimize|clear}` en `POST /api/deploy/reset`,
achter een Bearer-token (`DEPLOY_TOKEN`). Zonder dat token in de `.env` geven die
routes 404. Zie [DeployController](app/app/Http/Controllers/DeployController.php)
en [config/deploy.php](app/config/deploy.php).

## Vereiste op de host: PHP ≥ 8.4.1

De `composer.lock` bevat Symfony 8.1.5 (via Laravel 13) met `"php": ">=8.4.1"` als
harde require — ook al staat er nog `"php": "^8.3"` in `composer.json`. PLAN.md
spreekt van "≥8.2, liefst 8.3"; dat volstaat niet meer. Controleer in DirectAdmin
dat het subdomein op 8.4 staat. Elke taakrespons bevat `"php"` met de versie van de
host, dus je ziet het in de Actions-log staan.

## Eenmalig instellen

### 1. GitHub — secrets (Settings → Secrets and variables → Actions → Secrets)

| Secret | Waarde |
|---|---|
| `FTP_SERVER` | hostnaam of IP van de FTP-server |
| `FTP_USERNAME` | FTP-gebruiker — **maak hier een apart account voor** dat enkel in de subdomeinmap mag, niet je hoofdaccount |
| `FTP_PASSWORD` | wachtwoord van dat account |
| `DEPLOY_URL` | `https://intra.bclandegem.be` (zonder slash op het einde) |
| `DEPLOY_TOKEN` | lang willekeurig token, bv. `openssl rand -hex 32` — zelfde waarde als in de `.env` |

### 2. GitHub — variables (zelfde pagina, tabblad Variables)

| Variable | Waarde |
|---|---|
| `FTP_SERVER_DIR` | doelmap van de sync, mét slash op het einde, bv. `domains/intra.bclandegem.be/public_html/` |
| `FTP_PROTOCOL` | `ftp` (zet op `ftps` zodra de host expliciete TLS aanvaardt; bij een certificaatfout `ftps-legacy`) |
| `FTP_PORT` | `21` |

### 3. Op de server, met FTP

1. `app/.env.production.example` uploaden als `.env` in de app-map (naast `artisan`), aanvullen met databank­gegevens en `DEPLOY_TOKEN`.
2. `APP_KEY` zetten: genereer lokaal met `php artisan key:generate --show` en plak de waarde.
3. Document root: DirectAdmin laat die hier **niet** verzetten (geen Custom HTTPD Configurations op gebruikersniveau, en Subdomain Management heeft geen veld ervoor). Daarom staat de docroot op `public_html` en vangt [app/.htaccess](app/.htaccess) dat op: het blokkeert alles buiten `public/` en stuurt de rest naar `public/index.php`. Dat bestand gaat mee met de sync, dus er is geen handwerk. Kan je later tóch de docroot verzetten, verwijder het dan — Laravel's eigen `public/.htaccess` neemt over.
4. Snapshot voor de reset: lokaal `bash app/cutover.sh` draaien en `app/cutover.sql.gz` uploaden naar `storage/app/private/cutover.sql.gz`. Die map staat in de exclude-lijst van de sync, dus een deploy raakt hem niet.

### 4. Eerste deploy

`vendor/` is ~16.900 bestanden / 132 MB. De **eerste** sync duurt daardoor een half uur
tot een uur; daarna uploadt de action enkel wat gewijzigd is (seconden), want ze houdt
`.ftp-deploy-sync-state.json` bij op de server. Wijzigt `composer.lock`, dan is het
opnieuw veel bestanden.

Twee manieren, en ze zijn uitwisselbaar:

**a. Vanaf je laptop** — vaak sneller (lagere latency naar een Belgische host, en
FTP-tijd zit in de round-trip per bestand, niet in bandbreedte):

```bash
cp .deploy.local.example .deploy.local      # vul de FTP-gegevens in
bash .github/scripts/local-sync.sh               # proefdraai, wijzigt niets
bash .github/scripts/local-sync.sh --go          # echt uploaden
```

Het script bouwt in **`.deploy-staging/`** en raakt je eigen `app/vendor` nooit aan.
Dat is geen luxe: `composer install --no-dev` in je werkmap en daarna terugschakelen
laat op Windows een half verwijderde `vendor/` en een ontregelde classmap achter — de
autoloader verwijst dan naar packages die net verwijderd zijn, en zo'n build zou
gedeployd worden. Staging installeert vers, dus er valt niets te verwijderen.

Wat het doet: broncode van `app/` naar staging (zonder `vendor`, `node_modules`,
`.env`), `storage/` en `bootstrap/cache/` leeggemaakt op de `.gitignore`-stubs na
— net als een verse checkout in CI — dan `composer install --no-dev -o`,
`key:generate`, `filament:assets`, `ng build`, en de zaal-app erin gekopieerd.
Resultaat: ~14.300 bestanden / 93 MB.

Daarna vier controles vóór er één byte de deur uit gaat: geen dev-verwijzingen in
`vendor/composer/autoload_static.php`, `public/zaal/index.html` aanwezig,
`public/index.php` aanwezig, en géén `.env` in staging.

Het gebruikt **dezelfde tool** (`@samkirkland/ftp-deploy@1.2.5`; de action draait op
`^1.2.4`) en **dezelfde exclude-lijst** uit
[.github/ftp-exclude.txt](.github/ftp-exclude.txt), dus het laat hetzelfde
state-bestand achter en de workflow doet daarna een echte delta-sync. Achteraf draai je
eenmalig `deploy-task.sh migrate` en `optimize` (het script drukt de commando's af).

De CLI kent geen `--protocol`, dus dit pad werkt met gewone FTP — schakel je later naar
FTPS, laat de workflow dan syncen.

**b. Via de workflow** — start handmatig met **dry_run aan** om eerst te zien wat er
zou gebeuren. Dit kost je geen GitHub-minuten: de repo is publiek, en Actions op
standaard runners is daar onbeperkt gratis.

Na een lokale sync kan een volgende workflow-run nog een deel opnieuw uploaden, voor
bestanden die lokaal en in CI niet byte-identiek gebouwd worden. Dat is een fractie van
de 16.900, niet het geheel.

## De reset

Drie remmen, los van elkaar:

1. je moet in de workflow letterlijk `RESET` typen;
2. `INTRACLUB_ALLOW_RESET=true` moet in de `.env` staan;
3. het snapshotbestand moet op de server staan.

Daarbovenop kopieert de server elke tabel naar `bak_<jjmmdduumm ss>_<tabel>` vóór hij
iets wist, en bewaart de laatste twee reeksen (`INTRACLUB_BACKUP_SETS`).

Wat een reset doet: alle tabellen kopiëren → alle tabellen wissen →
`cutover.sql.gz` inlezen (structuur + data + `users` + `migrations`) →
`migrate --force` voor migrations die nieuwer zijn dan de snapshot → `optimize`.

Gevolgen om te weten:

- **Je moet opnieuw inloggen**: `SESSION_DRIVER=database`, dus de sessies gaan mee.
- **Het admin-wachtwoord is dat uit de snapshot.** Wijzig je het op productie en
  reset je daarna, dan is de wijziging weg. Zet het echte wachtwoord vóór het
  exporteren lokaal met `php artisan intraclub:set-password`.

## Bij de cutover — de knop dood maken

PLAN.md is duidelijk: zodra er in de zaal ingevoerd wordt is productie de bron van
waarheid en is een reset dataverlies. Doe dan alle drie:

1. `INTRACLUB_ALLOW_RESET=false` in de `.env`;
2. **de deploy opnieuw draaien** (of de taak `optimize`) — anders leest de app die
   wijziging nooit, want `optimize` heeft de configuratie in de cache gezet;
3. `storage/app/private/cutover.sql.gz` via FTP verwijderen. Dit is de enige rem die
   niet van de configuratiecache afhangt, dus dit is de belangrijkste.

## Handmatig een taak draaien

```bash
URL=https://intra.bclandegem.be TOKEN=<het token> \
  bash .github/scripts/deploy-task.sh migrate     # of optimize / clear / reset
```

## Wat de sync níet aanraakt

De lijst staat op één plek: [.github/ftp-exclude.txt](.github/ftp-exclude.txt),
gelezen door zowel de workflow als `local-sync.sh`. Dat is geen netheid maar
noodzaak — de FTP-tool **verwijdert** server-bestanden die niet in de bron staan, en
jouw laptop heeft een `app/.env` die naar `localhost` wijst. Twee lijsten die uit
elkaar lopen betekent vroeg of laat een productie-`.env` overschreven met dev-config.

[exclude-list.sh](.github/scripts/exclude-list.sh) weigert daarom te draaien als
`**/.env` of `storage/app/private/**` uit die lijst verdwenen is.

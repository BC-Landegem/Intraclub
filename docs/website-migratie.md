# Nieuwe site omzetten naar de Laravel-API

Voor wie in `bc-landegem/Website` werkt. De intraclub-pagina's halen hun data vandaag uit de **legacy Slim-API** op `https://www.bclandegem.be/intra-app/api/index.php`. Die verdwijnt samen met de Joomla-site. Dit document zegt wat er in de plaats komt en wat er in deze repo moet wijzigen.

De nieuwe API staat op `https://intra.bclandegem.be/api`. Contract en beslissingen: `PLAN.md`, fase 8.

---

## 1. Waar de calls vandaan komen

| Bestand in `Website` | Call vandaag |
| --- | --- |
| `src/pages/intraclub/index.astro` (island) | `/rankings/{category}`, `/rounds`, `/seasons/latest/statistics` |
| `src/pages/index.astro` (homepage-teaser) | `/rankings/general?$top=10` |
| `src/pages/intraclub/speler.astro` | `/players/{id}` |
| `src/pages/intraclub/speeldag.astro` | `/rounds/{id}` |
| `src/lib/intra.ts` (of gelijkaardig) | base-URL, `fetch`-helper, naamopmaak, set-rotatie |
| `public/sw.js` | datacache, herkent `/intra-app/api/` op pad |

## 2. Base-URL en CORS

```ts
// src/lib/intra.ts
const BASE = import.meta.env.PUBLIC_INTRA_API ?? 'https://intra.bclandegem.be/api';
```

```
# .env
PUBLIC_INTRA_API=https://intra.bclandegem.be/api
```

`https://bc-landegem.github.io` staat bij de toegelaten origins in `config/cors.php` van de API. Werkt het niet, controleer dan `CORS_ALLOWED_ORIGINS` in de `.env` op de host — de standaardwaarde in de code geldt alleen wanneer die variabele er niet staat.

**Service worker.** `sw.js` beslist op `target.pathname.startsWith('/intra-app/api/')` of iets data is. Dat pad is nu een andere host:

```js
function isData(target) {
  return target.hostname === 'intra.bclandegem.be' && target.pathname.startsWith('/api/');
}
```

Zonder deze wijziging valt de API-respons in de assetcache of nergens, en verlies je de network-first-met-fallback die er nu is.

## 3. Routes: oud → nieuw

| Oud | Nieuw |
| --- | --- |
| `/rankings` | `/rankings` — ongewijzigd pad, andere vorm |
| `/rankings/general` | `/rankings/general` of `/rankings?category=general` |
| `/rankings/general?$top=10` | `/rankings/general?limit=10` |
| `/rounds` | `/rounds` (+ `?calculated=1`, `?season=`) |
| `/rounds/latest` | **weg** — `/rounds` en zelf de laatste nemen |
| `/rounds/latestCalculated` | **weg** — zit in `meta.round` van `/rankings` |
| `/rounds/{id}` | `/rounds/{id}` |
| `/rounds/{id}/matches` | `/rounds/{id}/games` |
| `/players` | `/players` (+ `?members=0`) |
| `/players/{id}` | `/players/{id}` (+ `?include=games,ranking_history`) |
| `/seasons` | `/seasons` |
| `/seasons/latest/statistics` | `/seasons/current/statistics` |

Nieuw beschikbaar: `/players/{id}/games`, `/players/{id}/ranking-history`, `/players/{id}/pairings`, `/records`, en het volledige archief onder `/archive/…`.

## 4. Conventies die overal veranderen

1. **`snake_case`.** `firstName` → `first_name`, `name` → `last_name`, `averageAbsent` → `average_absent`. Er is een `full_name` bij, dus `${e.firstName} ${e.name}` mag weg.
2. **`data`-wrapper.** Elke collectie zit in `data`, met `meta` ernaast. `await get('/rounds')` wordt `(await get('/rounds')).data`.
3. **Echte booleans.** Zie punt 6 — dit is de val.
4. **Onbekende input faalt.** Een onbestaand seizoen geeft 404 in plaats van stil het huidige.

## 5. Per pagina

### Klassement (`/intraclub/`)

```ts
const { data, meta } = await get(`/rankings/${category}`);
// data: [{ id, first_name, last_name, full_name, average, rank, difference }]
// meta: { category, season: {id,name}, round: {id,number,date} | null }
```

`meta.round` is de speeldag waarop de stand geldt — dat is de "Stand na speeldag 17"-regel. Is hij `null`, dan heeft het seizoen nog geen berekende speeldag en staat het klassement op de basispunten; toon dan geen speeldagregel.

De aparte call naar `/rounds` om die speeldag te vinden mag weg.

**Tab Speeldagen.** `players_present` staat nu in de payload:

```ts
const { data } = await get('/rounds');
// [{ id, number, date, is_calculated, average_absent,
//    games_count, players_present, players_drawn_out }]
```

De kolom "Spelers" werd berekend als `parseInt(matches) * 4`. Dat klopt niet zodra er iemand uitgeloot is: bij een oneven aantal aanwezigen speelt niet iedereen mee. Gebruik `players_present`, en overweeg "49 aanwezig · 12 banen · 1 uitgeloot".

**Tab Aanwezigheden.** `/seasons/current/statistics` geeft `data: [{ player, statistics }]`, al gesorteerd op aanwezigheid en dan gewonnen sets — de sortering in de browser mag weg. De deler voor het percentage is het aantal berekende speeldagen: `(await get('/rounds?calculated=1')).data.length`.

### Spelerspagina (`/intraclub/speler/`)

```ts
const { data } = await get(`/players/${id}?include=games,ranking_history`);
// data.statistics       : { base_points, points, sets, games, rounds }
// data.ranking_history  : [{ round_id, number, date, average, rank }]
// data.games            : zie hieronder
```

`statistics.matches` heet nu `statistics.games`. De rank in `ranking_history` is bevroren op het moment van berekenen en is voortaan dezelfde als die in het klassement — vroeger konden die twee verschillen.

### Speeldagpagina (`/intraclub/speeldag/`)

```ts
const { data } = await get(`/rounds/${id}`);
```

De grootste wijziging. De helper die de rotatie samenstelde (set 1 = P1+P2 vs P3+P4, enz.) mag **weg** — die staat nu in de API:

```json
{
  "id": 596,
  "round": { "id": 56, "number": 17, "date": "2026-05-20" },
  "players": [ { "id": 93, "full_name": "Sigrid Wille", "bonus_points": 7 }, … ],
  "is_complete": true,
  "sets": [
    { "number": 1, "is_played": true, "winner": "home",
      "home": { "player_ids": [93, 88], "score": 21, "bonus": 9 },
      "away": { "player_ids": [61, 78], "score": 11, "bonus": 10 } }
  ]
}
```

- `player_ids` verwijst naar `players` in dezelfde wedstrijd — één lookup, geen zes herhaalde spelerobjecten.
- `is_played` in plaats van `score > 0` raden. Een ongespeelde set heeft `score: null`.
- `winner` volgt de regel waarmee de gemiddeldes berekend zijn: een gelijke of lege set telt als winst voor het uitduo.
- `bonus` is de som van de bonuspunten van dat duo. Het verschil tussen de twee is de voorsprong waarmee het zwakkere duo start — een afspraak op het terrein, niet in de scores. Handig om te tonen, want "zo werkt het" legt die regel uit.

Nieuw en nog ongebruikt: `data.attendances` — elke speler met een statistiekrij voor die speeldag, dus aanwezig, uitgeloot én afwezig in één lijst, met de naam erbij.

## 6. De val: `calculated === '1'`

De legacy-API gaf `"1"` als string. De code doet nu op twee plaatsen een strikte stringvergelijking:

```js
// src/lib/intra.ts
t.calculated === '1'                        // laatste berekende speeldag zoeken
// intraclub island
e.filter(e => e.calculated === '1').length  // deler voor het aanwezigheidspercentage
```

De nieuwe API geeft `is_calculated: true`. Beide vergelijkingen worden dan altijd `false`, **zonder foutmelding**: de regel "Stand na speeldag 17" verdwijnt en elk aanwezigheidspercentage wordt 0%. Als je één ding uit dit document meeneemt, dan dit.

De eerste vergelijking verdwijnt sowieso als je `meta.round` gebruikt. De tweede wordt `/rounds?calculated=1`.

## 7. Zichtbaarheid

Klassement, speeldagoverzicht en de historiek-pagina's blijven indexeerbaar. Op **spelerspagina's en speeldagdetail** komt `noindex, follow`: wie de link heeft ziet ze, maar Google zet geen clublid met naam en aanwezigheidspercentage in de zoekresultaten. Zet die pagina's ook niet in `sitemap.xml`.

## 8. Nieuw: 16 seizoenen historiek

Vandaag toont de site alleen het lopende seizoen. In de databank zitten:

- **2023-2024** en **2024-2025** in het huidige format, via de gewone endpoints met `?season=1` en `?season=6`;
- **2009-2023**, veertien seizoenen, onder `/archive/…`.

Die data is bevroren, dus ze hoort **build-time** opgehaald te worden: `getStaticPaths()` over `/archive/seasons`, platte HTML, nul fetches in de browser, meteen indexeerbaar. De enige "rebuild-trigger" die je daarvoor nodig hebt is een `git push`.

```
GET /archive/seasons                      → 14 seizoenen, met rounds_count en players_count
GET /archive/seasons/{id}/standings       → eindstand, met badmintonranking en games.won
GET /archive/seasons/{id}/rounds          → speeldagen
GET /archive/rounds/{id}                  → uitslagen (team1/team2, best-of-3)
GET /archive/players?player_id={huidig}   → wat het archief weet over een huidig lid
GET /archive/players/{id}?include=games
```

Twee dingen om te weten:

- Het oude format speelde met **vaste teams in best-of-3**, niet met duo's die per set wisselen. Een gearchiveerde wedstrijd heeft `team1`/`team2` en soms maar twee sets. Hergebruik de speeldagcomponent van het huidige format hier dus niet.
- **2010-2011 heeft geen eindstand.** Dat seizoen werd destijds nooit gearchiveerd, enkel de uitslagen bleven bewaard. `standings` geeft daar een lege `data`. Vang dat op met een uitleg in plaats van een leeg tabelletje.

Voor de seizoenen in het huidige format: gebruik **`?members=0`**. Zonder die parameter geeft de API enkel de huidige leden, en dan valt de helft van de eindstand van 2023-2024 weg — dat seizoen had 96 spelers, waarvan er nu 60 nog lid zijn.

## 9. Wat er sinds de omzetting bijgekomen is

Drie dingen die er nog niet waren toen dit document geschreven werd.

### `day_score` — de formule wordt navolgbaar

Op `/rounds/{id}` staat per aanwezigheid, en in `ranking-history` per speeldag, wat die avond opbracht:

```json
{ "player": {…}, "is_present": true, "is_drawn_out": false,
  "day_score": 19.67, "average": 19.37, "rank": 3 }
```

Gespeeld → het herschaalde puntengemiddelde over de drie sets. Afwezig → het verliezersgemiddelde van die speeldag (gelijk aan `average_absent`). Uitgeloot zonder wedstrijd → `null`, want die speeldag telt niet mee.

Daarmee is op de spelerspagina na te rekenen waar een gemiddelde vandaan komt: het gemiddelde na speeldag N is het gemiddelde van de basispunten en alle dagscores tot en met N. Precies wat "zo werkt het" uitlegt, nu met echte cijfers per speler.

### `/players/{id}/pairings` — met wie, en met welk resultaat

```json
{ "player": {…}, "games": 4,
  "as_partner":  { "sets": 4, "sets_won": 3 },
  "as_opponent": { "sets": 8, "sets_won": 4 } }
```

Eén rij per andere speler, gesorteerd op aantal avonden. `as_partner.sets` is altijd gelijk aan `games` en `as_opponent.sets` altijd het dubbele — dat is geen toeval maar de rotatie: op één baan speelt elke speler precies één set mét elk van de andere drie en twee sets tégen elk van hen. De noemers staan er expliciet bij zodat de site ze niet zelf moet afleiden.

### `/records` — clubrecords

Vijf lijsten in één call: `best_days`, `best_seasons`, `biggest_climbs`, `longest_streaks`, `most_played_duos`. Alleen over het huidige format; de veertien oude jaargangen krijgen hun eigen erelijst uit `/archive`.

**Let op twee afwijkingen.** `?season=` weglaten betekent hier *alle* seizoenen, niet het lopende — een clubrecord over één seizoen is er geen. En `?limit=` geldt per lijst, standaard 10.

Drie velden die je op de pagina beter wél toont:

- `best_days` bestaat bijna volledig uit dagscores van 21,00 (alle drie de sets gewonnen). De lijst is op puntensaldo gesorteerd, dus zet `points_won`/`points_conceded` erbij — anders lijkt de volgorde willekeurig.
- `biggest_climbs` geeft `from_average`/`to_average`. Vroeg in het seizoen ligt het veld op een kluitje, dus de grootste sprongen zijn +80 plaatsen met een halve punt erbij. Zonder die twee getallen leest het record veel groter dan het is.
- `players_ranked` staat bij elke rij die over een plaats gaat: een sprong op een stand van 60 is een ander getal dan een sprong op een stand van 121.

### `?members=0` geldt nu ook op `/rankings`

Nodig voor de erelijst. Zonder deze parameter filtert een klassement op de huidige leden, ook dat van een afgesloten seizoen — en dan mist de eindstand van 2023-2024 er 36 van de 96.

## 10. Checklist

- [ ] `PUBLIC_INTRA_API` in `.env` en in de build-omgeving van GitHub Actions
- [ ] `intra.ts`: base-URL, `data`-wrapper, `full_name`, set-rotatiehelper weg
- [ ] `sw.js`: `isData()` op host + `/api/`
- [ ] Klassement: `meta.round` gebruiken, `/rounds`-call laten vallen
- [ ] Tab Speeldagen: `players_present` in plaats van `× 4`
- [ ] Tab Aanwezigheden: `/seasons/current/statistics`, deler uit `?calculated=1`
- [ ] Homepage-teaser: `?limit=10`
- [ ] Spelerspagina: `?include=games,ranking_history`, `statistics.games`
- [ ] Speeldagpagina: `sets[].home/away`, `is_played`, `winner`
- [ ] **Beide `calculated === '1'`-vergelijkingen weg**
- [ ] `noindex` op speler- en speeldagpagina's, en uit de sitemap
- [ ] "Zo werkt het": beslissen of het uitgewerkte voorbeeld op speeldag 17 van 2025-2026 blijft staan
- [ ] Speeldagpagina: `attendances` gebruiken, met `day_score` naast het gemiddelde
- [ ] Spelerspagina: `day_score` in het klassementsverloop, en `/pairings` als tweede blok
- [ ] Nieuwe pagina's: erelijst, historiek per seizoen (build-time), records

Die laatste verdient uitleg. De pagina bevat de 48 echte aanwezigen van 20 mei 2026 in een `<select>`, met een rekenmachine erbij — een build-time momentopname van één speeldag, geen live cijfers. Dat is een goede keuze: de prose eromheen ("48 spelers aanwezig") blijft kloppen omdat het voorbeeld niet beweegt. Maar het blijft ook op 20 mei 2026 staan tot iemand het aanpast. Twee opties: het voorbeeld bewust vastpinnen op één speeldag en dat in de tekst zeggen ("een voorbeeld van 20 mei 2026"), of de build altijd de laatst berekende speeldag nemen — `/rankings` geeft die in `meta.round`, en `/rounds/{id}` de bijhorende wedstrijden en aanwezigen. De tweede optie vraagt dat de tekst geen enkel concreet getal meer noemt buiten wat uit de data komt.

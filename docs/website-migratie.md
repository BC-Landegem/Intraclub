# Nieuwe site omzetten naar de Laravel-API

Voor wie in `bc-landegem/Website` werkt. De intraclub-pagina's halen hun data vandaag uit de **legacy Slim-API** op `https://www.bclandegem.be/intra-app/api/index.php`. Die verdwijnt samen met de Joomla-site. Dit document zegt wat er in de plaats komt en wat er in deze repo moet wijzigen.

De nieuwe API staat op `https://intra.bclandegem.be/api`. Contract en beslissingen: `PLAN.md`, fasen 8 en 11.

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
| `/players` | `/players` — het ledenbestand; `?members=0` bestaat niet meer |
| `/players/{id}` | `/players/{id}` (+ `?include=games,ranking_history`, + `seasons` voor de geschiedenis) |
| `/seasons` | `/seasons` |
| `/seasons/latest/statistics` | `/seasons/current/statistics` |

> Een deel van deze routes geldt sinds fase 11 **enkel nog voor het lopende seizoen**, en `/players?members=0` bestaat niet meer. Zie §7 en §8.

Nieuw beschikbaar: `/players/{id}/games`, `/players/{id}/ranking-history` en `/players/{id}/pairings` (lopend seizoen), `/records`, en de eindstanden van het archief onder `/archive/seasons/…`.

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

## 7. Zichtbaarheid en de grens van de geschiedenis

Sinds 31-08 geldt er een harde grens rond alles vóór het lopende seizoen (`PLAN.md`, fase 11). De regel, in één zin:

> **Eén regel uit een eindstand mag altijd — die regels van één persoon bij elkaar zetten mag alleen als die persoon nog lid is.**

Concreet voor deze repo:

- **Lopend seizoen:** er verandert niets. Klassement, speeldagen, spelerspagina's, alles blijft zoals de rest van dit document beschrijft.
- **Afgesloten seizoenen:** enkel de **eindstand** — alle deelnemers, ook wie gestopt is — plus de erelijst en `/records`. Geen speeldagpagina's meer van vroeger, geen wedstrijden, geen aanwezigheden, geen klassementsverloop.
- **Spelerspagina:** het lopende seizoen volledig; de geschiedenis enkel als tabel met vijf kolommen per seizoen (plaats, gemiddelde, sets, matchen, aanwezig), niet openklapbaar naar speeldagen.
- **Spelerspagina van een niet-lid:** de API geeft `403` met `{"error":{"code":"not_a_member"}}` en **geen naam**. Toon daar een zachte melding ("Deze speler is geen lid meer van de club"), geen 404-pagina.

**Dit is gebouwd** (fase 11 en 12, 31-08). Twee foutcodes en één nieuw veld, en daarmee is het contract volledig:

| | |
| --- | --- |
| `403` `{"error":{"code":"not_a_member"}}` | de vier fiche-routes van een niet-lid: `/players/{id}` en zijn `/games`, `/ranking-history`, `/pairings` |
| `403` `{"error":{"code":"season_closed"}}` | diezelfde vier met `?season=` op een afgesloten seizoen, plus `/rounds`, `/rounds/{id}` en `/rounds/{id}/games` daarbuiten |
| `404` | onbestaande speler, onbestaand seizoen, en de verwijderde routes uit §8 — een typefout blijft dus een typefout |

Het nieuwe veld is **`seasons` op `/players/{id}`**: de geschiedenis zit in dezelfde response als de fiche, dus er is geen tweede call en geen aparte archief-fiche meer.

```json
"seasons": [
  { "season_id": 12, "season_name": "2022 - 2023", "is_archive": true,
    "rank": 18, "average": 18.62,
    "sets": { "won": 21, "total": 34 }, "games": { "total": 17 }, "rounds": { "present": 17 } },
  { "season_id": 1, "season_name": "2023-2024", "is_archive": false, "rank": 46, "average": 19.11, … }
]
```

Vijf dingen om te weten:

- **Beide generaties staan in één chronologische lijst.** `is_archive` zegt bij welke eindstand een rij hoort — `/archive/seasons/{id}/standings` of `/rankings?season={id}&members=0` — want de twee id-reeksen staan los van elkaar. Vergelijk de gemiddelden niet over die grens heen (§8).
- **Het lopende seizoen staat er niet in.** Dat is de fiche zelf: `statistics` en `meta.season`.
- **`rank` is de plaats uit de gepubliceerde eindstand**, dus met de gestopte leden meegeteld. Dezelfde rijen als in de seizoenstabel, andere as — je mag ze naast elkaar zetten.
- **Een seizoen zonder eindgemiddelde staat er niet in**, net zoals het niet in de eindstand staat (fase 12). Voor een lid dat een seizoen halverwege verliet, ontbreekt dat seizoen dus; dat is geen gat maar dezelfde regel aan beide kanten.
- **`players_count` op `/seasons` en `/archive/seasons` is de lengte van die eindstand.** Je mag het dus als teller boven de tabel zetten. Vroeger telde het de inschrijvingen: voor 2018-2019 stond er 142 boven een stand van 81.

Twee gevolgen voor de bouwwijze, en die zijn niet vrijblijvend:

1. **Spelerspagina's moeten client-side.** Of iemand nog lid is verandert ná de build. Een build-time fiche blijft anders maanden staan nadat iemand gestopt is, want er is bewust geen rebuild-trigger vanuit Laravel.
2. **Eindstanden mogen build-time**, want die veranderen nooit meer. Zet er wel een **`schedule:`-cron** naast in de workflow — nachtelijk volstaat. Zonder die cron valt er bij de seizoenswissel een gat: het net gespeelde seizoen is dan uit de API verdwenen als speeldagen, en nog niet gebouwd als eindstand.

**Alle namen in een eindstand blijven klikbaar**, ook die van gestopte leden. De build weet niet wie er in maart nog lid is; een link die bij het bouwen klopte, beweert drie maanden later het verkeerde. De fiche beslist het zelf, live.

`noindex, follow` blijft op spelerspagina's en op speeldagdetail van het lopende seizoen, en die pagina's blijven uit `sitemap.xml`. Klassement, eindstanden en records blijven indexeerbaar.

## 8. Zeventien eindstanden

Vandaag toont de site alleen het lopende seizoen. In de databank zitten er zeventien:

- **2023-2024** en verder in het huidige format, via de gewone endpoints met `?season={id}`;
- **2009-2023**, veertien seizoenen, onder `/archive/…`.

Van een afgesloten seizoen is publiek enkel de **eindstand** beschikbaar (§7). Die verandert nooit meer, dus ze hoort **build-time** opgehaald te worden: `getStaticPaths()` over de seizoenen, platte HTML, nul fetches in de browser, meteen indexeerbaar.

```
GET /seasons                            → alle seizoenen, met meta.current_season_id
GET /rankings?season={id}&members=0     → eindstand huidig format: plaats + gemiddelde
GET /seasons/{id}/statistics?members=0  → sets, punten, matchen, speeldagen aanwezig
GET /archive/seasons                    → de veertien oude seizoenen
GET /archive/seasons/{id}/standings     → eindstand, met badmintonranking en games.won
GET /records                            → clubrecords over het huidige format
```

**`?members=0` is niet optioneel.** Zonder die parameter filtert de API op de huidige leden, ook in de stand van een afgesloten seizoen — en dan mist de eindstand van 2023-2024 er 36 van de 96.

De vijf kolommen die een eindstand toont — plaats, gemiddelde, sets, matchen, aanwezig — zijn dezelfde als die op de spelerspagina onder "geschiedenis". Dat is opzet: het zijn letterlijk dezelfde rijen, één keer per seizoen gerangschikt en één keer per speler.

Twee dingen om te weten:

- **2010-2011 heeft wél een eindstand**, met 73 spelers. Dit document zei eerder dat dat seizoen leeg terugkwam; dat klopt niet meer. Het werd destijds nooit correct gearchiveerd — de bewaarde punten kloppen niet met de bewaarde uitslagen — en de stand wordt daarom herberekend uit de wedstrijden. Dat dat mag is nagerekend: de rekenregels van toen zijn dezelfde als die van nu, en voor de drie andere comp-seizoenen reproduceert de berekening de bewaarde stand voor *álle* spelers (55/55, 82/82, 79/79). **Alle zeventien seizoenen hebben dus een stand**; er is geen leeg geval meer om op te vangen.
- Vergelijk gemiddelden **niet over de twee generaties heen**. Het oude format speelde met vaste teams in best-of-3, het huidige met duo's die per set roteren. De cijfers zien er hetzelfde uit en betekenen iets anders.

**Weg uit de API** (bestonden in fase 7-10, verdwijnen met fase 11): `/archive/seasons/{id}/rounds`, `/archive/rounds/{id}`, `/archive/players`, `/archive/players/{id}`, `/rounds` en `/rounds/{id}` buiten het lopende seizoen, en `/players?members=0`.

## 9. Wat er sinds de omzetting bijgekomen is

Drie dingen die er nog niet waren toen dit document geschreven werd. `day_score` en `/pairings` gelden sinds fase 11 **enkel binnen het lopende seizoen** (§7); `?members=0` op `/rankings` is juist bedoeld voor de eindstanden van vroeger.

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

Vijf lijsten in één call: `best_days`, `best_seasons`, `biggest_climbs`, `longest_streaks`, `most_played_duos`. Alleen over het huidige format — de veertien oude jaargangen speelden met vaste teams in best-of-3, dus een dagscore van toen is een ander getal. Zij hebben hun eigen eindstanden onder `/archive` (§8).

**Let op twee afwijkingen.** `?season=` weglaten betekent hier *alle* seizoenen, niet het lopende — een clubrecord over één seizoen is er geen. En `?limit=` geldt per lijst, standaard 10.

Drie velden die je op de pagina beter wél toont:

- `best_days` bestaat bijna volledig uit dagscores van 21,00 (alle drie de sets gewonnen). De lijst is op puntensaldo gesorteerd, dus zet `points_won`/`points_conceded` erbij — anders lijkt de volgorde willekeurig.
- `biggest_climbs` geeft `from_average`/`to_average`. Vroeg in het seizoen ligt het veld op een kluitje, dus de grootste sprongen zijn +80 plaatsen met een halve punt erbij. Zonder die twee getallen leest het record veel groter dan het is.
- `players_ranked` staat bij elke rij die over een plaats gaat: een sprong op een stand van 60 is een ander getal dan een sprong op een stand van 121.

### `?members=0` geldt nu ook op `/rankings`

Nodig voor de erelijst. Zonder deze parameter filtert een klassement op de huidige leden, ook dat van een afgesloten seizoen — en dan mist de eindstand van 2023-2024 er 36 van de 96.

## 11. Includes op collecties

> **Let op, fase 11 haalt hier twee van de drie weg.** `include=seasons` op `/archive/players` en `include=games` op `/archive/seasons/{id}/rounds` zijn verdwenen met hun routes. `include=attendances` op `/rounds` **bestaat nog, maar enkel voor het lopende seizoen** — de speeldagpagina's van dit seizoen leunen erop, en over een afgesloten seizoen geeft de route zelf `403 season_closed`. Wat verder blijft is `?include=games,ranking_history` op de spelerspagina van het lopende seizoen. Lees de rest van deze paragraaf als achtergrond bij het contract, niet als bouwinstructie.

`?include=` werkte eerst alleen op één resource. Op een collectie werd de parameter **stil genegeerd**: je kreeg de kale lijst terug en haalde de rest dan alsnog per speler op. Dat kostte een build-script 854 requests waar er 55 volstaan, zonder dat er iets waarschuwde.

Twee dingen zijn daarom veranderd.

**Drie includes op collecties.**

```
GET /rounds?season={id}&include=attendances     → aanwezigheden van élke speeldag
GET /archive/seasons/{id}/rounds?include=games  → speeldagen mét uitslagen
GET /archive/players?include=seasons            → spelers mét hun seizoenen
```

De vorm is per stuk identiek aan die van de losse resource: `attendances` is rij voor rij hetzelfde als op `/rounds/{id}` (met `is_present`, `is_drawn_out`, `day_score`, `average`, `rank`), `games` hetzelfde als op `/archive/rounds/{id}`, `seasons` hetzelfde als op `/archive/players/{id}`. Een contracttest vergelijkt de twee responses met elkaar, dus dat blijft zo.

Aanwezigheid hoeft daarmee niet meer uit iemands wedstrijden afgeleid te worden: `is_present` staat er als echte boolean, ook voor wie afwezig of uitgeloot was.

Alles wordt in één keer ingeladen, dus een seizoen van twintig speeldagen kost evenveel queries als één speeldag — ook dat staat in een test.

**Een genegeerde include bestaat niet meer.** Elke publieke route zegt in `routes/api.php` welke includes ze kent; al de rest geeft **422** met de toegelaten lijst erbij, ook op een route die er geen enkele kent. Een typefout in een build-script faalt dus meteen, in plaats van stil de dubbele hoeveelheid requests te veroorzaken.

## 12. Checklist

- [ ] `PUBLIC_INTRA_API` in `.env` en in de build-omgeving van GitHub Actions
- [ ] `intra.ts`: base-URL, `data`-wrapper, `full_name`, set-rotatiehelper weg
- [ ] `sw.js`: `isData()` op host + `/api/`
- [ ] Klassement: `meta.round` gebruiken, `/rounds`-call laten vallen
- [ ] Tab Speeldagen: `players_present` in plaats van `× 4`
- [ ] Tab Aanwezigheden: `/seasons/current/statistics`, deler uit `?calculated=1`
- [ ] Homepage-teaser: `?limit=10`
- [ ] Spelerspagina: `?include=games,ranking_history`, `statistics.games` — **enkel voor het lopende seizoen** (§7)
- [ ] Speeldagpagina: `sets[].home/away`, `is_played`, `winner` — **enkel voor het lopende seizoen**
- [ ] **Beide `calculated === '1'`-vergelijkingen weg**
- [ ] `noindex` op speler- en speeldagpagina's, en uit de sitemap
- [ ] Speeldagpagina: `attendances` gebruiken, met `day_score` naast het gemiddelde
- [ ] Spelerspagina: `day_score` in het klassementsverloop, en `/pairings` als tweede blok

Uit fase 11 (§7) komt daar dit bij, en die haalt ook werk wég. Lees §7 dus vóór je aan de historiek-pagina's begint.

- [ ] **Spelerspagina client-side** in plaats van build-time, en `403 not_a_member` opvangen met een zachte melding zonder naam
- [ ] **Geen speeldag- of wedstrijdpagina's voor afgesloten seizoenen.** Alles wat vandaag `/rounds/{id}` of `/archive/rounds/{id}` gebruikt buiten het lopende seizoen, vervalt
- [ ] Spelerspagina: geschiedenis uit het veld **`seasons`** van diezelfde `/players/{id}`-call (§7) — vijf kolommen per seizoen, beide generaties in één lijst op `is_archive`, **niet openklapbaar** naar speeldagen
- [ ] `403 season_closed` net zo opvangen als `not_a_member`: het betekent "die pagina bestaat niet meer voor dat seizoen", niet "er is iets stuk"
- [ ] Nieuwe pagina's: **eindstand per seizoen** (build-time, 17×), erelijst, records
- [ ] Alle namen in een eindstand blijven klikbaar, ook van gestopte leden — de fiche beslist zelf
- [ ] `schedule:`-cron in de deploy-workflow (nachtelijk), zodat een net afgesloten seizoen vanzelf als eindstand verschijnt
- [ ] "Zo werkt het": het voorbeeld **niet** vastpinnen op een speeldag-id (zie hieronder)

Dat laatste punt verdient uitleg, want fase 11 haalt er een optie weg. De pagina bevat de 48 echte aanwezigen van 20 mei 2026 in een `<select>`, met een rekenmachine erbij — een build-time momentopname van één speeldag. Tot nu waren er twee mogelijkheden: het voorbeeld bewust vastpinnen op die datum, of de build altijd de laatst berekende speeldag laten nemen. **De eerste kan niet meer.** Zodra het seizoen 2025-2026 afgesloten is, geeft `/rounds/{id}` voor die speeldag niets meer terug en breekt de build. Blijft over: `meta.round` van `/rankings` volgen en `/rounds/{id}` van het lopende seizoen ophalen — wat vraagt dat de tekst geen concreet getal meer noemt buiten wat uit de data komt.

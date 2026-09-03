# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

**Zaal-app (primair oppervlak van dit dossier).** Badmintonspelers van BC Landegem die
net een intraclub-wedstrijd gespeeld hebben en de setstanden komen invullen op **één
gedeelde tablet** die in de sporthal staat. Ze staan recht, zijn bezweet, hebben haast,
komen mogelijk in groepjes aanschuiven, en gebruiken het toestel één keer per avond.
Ze kennen hun eigen naam, niet het nummer van hun wedstrijd.

**Organisator.** Dezelfde avond, hetzelfde toestel: duidt aanwezigheden aan, doet de
loting, bevestigt de wedstrijden, vult uitgelote spelers aan en moet aan het eind van de
avond weten welke wedstrijden nog geen score hebben zodat hij de juiste mensen kan
aanspreken.

**Beheerder.** Achteraf, in Filament: correcties, spelersbeheer, herberekening.

## Product Purpose

De intraclub-competitie van BC Landegem administreren: aanwezigheden, loting in
viertallen, setstanden, en daaruit een doorlopend klassement. Succes op de speeldag zelf
is smal en meetbaar: **aan het einde van de avond hebben alle wedstrijden een correcte
score, zonder dat iemand aan de tablet heeft moeten zoeken, twijfelen of iets van een
ander groepje heeft overschreven.**

## Positioning

Het wedstrijdformat is eigen aan de club en bepaalt de hele UI: één wedstrijd is
**vier spelers en drie sets met per set roterende teams** (set 1 = P1+P2 vs P3+P4,
set 2 = P1+P3 vs P2+P4, set 3 = P1+P4 vs P2+P3). Er is dus geen vaste partner en geen
winnaar van de wedstrijd; wat telt is per speler het aantal gewonnen sets en de gehaalde
punten, die in een voortschrijdend gemiddelde gaan. Elk van de vier spelers kan de score
van dezelfde wedstrijd invullen.

## Operating Context

- Eén tablet, staand op een tafel in de sporthal; PWA, donker thema, vingerbediening.
- **7 tot 16 wedstrijden per speeldag, doorgaans 11 à 12** (gemeten over 52 speeldagen in
  de productiedump) — dus 44 tot 48 aanwezige spelers.
- Scores komen **al de avond door binnen**, per groepje, niet in één batch aan het eind.
- De avond verloopt in golven: bij de start staan alle wedstrijden open, tegen het einde
  nog een handvol.
- Iedereen aan de tablet is ingelogd als hetzelfde zaal-account; de app kent geen
  individuele spelerslogin.

## Capabilities and Constraints

- **Legale setstanden zijn een kleine, opsombare verzameling.** Bij sets tot 15 met cap 21
  bestaan er per winnende kant precies 21 geldige standen: 15-0 t/m 15-13, plus 16-14,
  17-15, 18-16, 19-17, 20-18, 21-19 en 21-20. Setmaximum en cap komen per seizoen uit de
  API (`pointsPerSet`, `maxScore`), niet uit de front-end.
- **90,7% van de sets eindigt met de winnaar op exact het setmaximum**; 9,3% gaat in
  verlenging (1734 sets gemeten).
- Elke score-save herberekent via `GameObserver` het **volledige seizoen** in één
  transactie (`SeasonCalculator`, alle speeldagen × alle spelers). Invoer mag daarom nooit
  op de server wachten.
- Elke API-aanroep geeft de volledige speeldagtoestand terug; `ZaalApi.run()` zet één
  globale `busy`-vlag en vervangt de hele toestand.
- `games` heeft `timestamps()`, dus het tijdstip van bewaren is beschikbaar. Er is **geen**
  kolom die vastlegt wie de score invulde, en die komt er bewust niet.
- Er is **geen terrein-/veldnummer** in het model; wedstrijden hebben enkel een id en een
  volgorde. `SetSide.field` is de API-veldnaam, geen speelveld.
- Een aangemaakte wedstrijd kan in de zaal niet verwijderd worden; corrigeren gebeurt in
  het beheerspaneel.
- Een speeldag telt pas mee voor het klassement wanneer **álle** wedstrijden erop compleet
  zijn; halve speeldagen worden teruggezet en verschijnen niet publiek.
- Doeltoestel: tablet, ontworpen op 1024×768 landschap, moet ook portret werken.
  Minimale raakhoogte 56px (`--raakhoogte`).

## Brand Commitments

- Naam: Intraclub, BC Landegem. Interface **volledig in het Nederlands**, spelers worden
  getutoyeerd.
- De bestaande visuele wereld van de zaal-app is de norm en blijft: donkere achtergrond
  (`#0f1115`), panelen (`#191d24`), oranje accent (`#f5a524`), groen voor bevestiging
  (`#17a34a`), rood voor fouten (`#dc2626`), 12px hoekradius, systeemfont.
- Toon: kort, feitelijk, zonder jargon; meldingen zeggen wat er gebeurd is, niet wat de
  code deed.

## Evidence on Hand

- Productiedump `bclandegem_intraclub.sql` (52 speeldagen, 1734 sets) — de bron van de
  cijfers hierboven.
- Werkende zaal-app in `zaal/`, Laravel-API in `app/`, plan en deploy in `PLAN.md` en
  `DEPLOY.md`.
- Geen gebruikersonderzoek, geen analytics, geen sessieopnames. De drie gerapporteerde
  klachten (veel scrollen, per ongeluk aanpassen, onzekerheid over bewaren) komen uit
  waarneming door de opdrachtgever en zijn de enige gebruikersfeedback die er is —
  daar mag niets bijverzonnen worden.

## Product Principles

1. **De speler kent zijn naam, niet zijn wedstrijdnummer.** Elke weg naar een score
   begint bij een naam.
2. **Wat al bewaard is, is geen formulier meer.** Een ingevulde wedstrijd wordt gelezen,
   niet bewerkt; wijzigen is een aparte, bewuste daad.
3. **Onmogelijke invoer wordt niet gemeld maar onmogelijk gemaakt.** Bied enkel legale
   standen aan; dan is er geen validatiemelding om te lezen.
4. **Een tik wacht nooit op de server.** De zaal is geen plek voor spinners; bewaren
   gebeurt achter de rug van de speler en meldt zich pas als het klaar is.
5. **Het toestel hoort na elk gebruik leeg te zijn.** De volgende speler begint bij nul,
   nooit halfweg in het scherm van iemand anders.

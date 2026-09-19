# De twee formulieren van de clubwebsite

De site is statisch en staat op een ander domein dan deze app. Beide formulieren
zijn daarom een gewone cross-origin `<form method="post">`: geen `fetch`, geen JSON,
geen CSRF-token, en altijd een 302 terug naar de pagina waar de bezoeker vandaan
kwam.

| | Contactformulier | Meldformulier |
| --- | --- | --- |
| Pagina | `/club/contact/` | `/club/melden/` |
| Endpoint | `POST https://intra.bclandegem.be/api/contact` | `POST https://intra.bclandegem.be/api/melding` |
| Code | [ContactController](../app/app/Http/Controllers/ContactController.php), [config/contact.php](../app/config/contact.php) | [MeldingController](../app/app/Http/Controllers/MeldingController.php), [config/melding.php](../app/config/melding.php) |
| Velden | `name`, `email`, `message` — alle verplicht | `message` verplicht (max 10 000); `name` (max 100) en `contact` (max 190, vrije tekst: e-mail óf telefoon) optioneel |
| Anti-spam | honeypot `website`, `loaded_at`, 3 per IP per uur, Turnstile met `action=contact` | idem, met `action=melding` en een eigen emmer van 3 per IP per uur |
| Turnstile onbereikbaar | dicht | **door**, met `[ongeverifieerd]` in het onderwerp |
| Terug | `return_ok` / `return_error`, getoetst aan een allowlist van origins | idem; terugval `/club/melden/` |
| Ontvanger | `CONTACT_TO`, standaard `info@bclandegem.be` | `MELDING_TO`, **zonder standaardwaarde** |
| Onderwerp | `[Website] Bericht van <naam>` | `[Melding] <dd-mm-jjjj uu:mm>` — nooit de naam |
| Reply-To | de bezoeker | enkel als `contact` een geldig e-mailadres is |
| In de mail | naam, e-mail, bericht | waarschuwing, bericht, naam en `contact` als ingevuld, tijdstip — geen IP, geen user-agent, geen Turnstile-details |
| Bewaring | mail-only | mail-only in de applicatie; er is geen bevestiging naar de inzender en geen logregel met de inhoud |

Wat de twee delen zit in [`Concerns/RedirectsToSite`](../app/app/Http/Controllers/Concerns/RedirectsToSite.php)
(de redirect-allowlist — het enige gedeelde stuk waar uiteenlopen een
beveiligingsfout zou zijn) en [`Concerns/VerifiesTurnstile`](../app/app/Http/Controllers/Concerns/VerifiesTurnstile.php)
(de call naar Cloudflare, die enkel een oordeel teruggeeft). De rest — honeypot,
tijdslot, begrenzer — staat bewust dubbel: zes regels per stuk, en zo blijft elke
controller in één keer leesbaar. De redirect-allowlist en het Turnstile-secret staan
voor beide in `config/contact.php`: dezelfde site, hetzelfde sleutelpaar. Enkel de
ontvanger staat apart, en dat is het punt van `config/melding.php`.

---

## Waarom het meldformulier op zes punten afwijkt

Alles hieronder komt uit één vaststelling: **een melder heeft geen tweede, anonieme
weg.** Het adres van het aanspreekpunt staat op haar vraag nergens op de site, er is
geen bevestigingsmail, geen logregel en geen tweede ontvanger. Een melding die
sneuvelt, kent niemand behalve de melder zelf. Bij het contactformulier staat
`info@bclandegem.be` gewoon op de pagina.

**1. De ontvanger heeft geen terugval.** `config/contact.php` doet
`env('CONTACT_TO', 'info@bclandegem.be')`. Datzelfde patroon hier zou een formulier
opleveren dat er correct uitziet, netjes bedankt, en elke melding stil naar het
bestuur stuurt — en `.env` staat niet in git, dus bij een nieuwe server is "vergeten"
het standaardgeval. Staat `MELDING_TO` leeg, dan stopt de controller vóór de
begrenzer met `?error=unavailable`. Verliezen is beter dan verkeerd bezorgen.

**2. Een onbereikbare Turnstile laat door.** Contact gaat dicht bij twijfel; dat kan,
want die bezoeker kan altijd nog rechtstreeks mailen. Hier is een dichte deur het
einde van de poging. Er is onderscheid tussen *afgekeurd* (Cloudflare heeft
gesproken, of een 4xx → dicht) en *geen oordeel* (time-out, 5xx of een
onleesbaar antwoord → door, met `[Melding][ongeverifieerd]` in het onderwerp, zodat
het aanspreekpunt het zelf ziet).
Een spammer kan onze uitgaande verbinding naar Cloudflare niet platleggen, dus dat
venster kan hij niet zelf openen — en honeypot, tijdslot en de begrenzer gelden nog.

**3. De onderwerpregel draagt geen naam.** Een naam in het onderwerp staat in de
notificatie op een vergrendeld telefoonscherm en in de berichtenlijst. Belangrijker:
`From` blijft `MAIL_FROM_ADDRESS`, dus een Beantwoorden gaat naar de bestuursmailbox.
De naam in de body kan het aanspreekpunt wissen vóór ze antwoordt, het onderwerp
niet. Vandaar datum en uur — genoeg om twee meldingen uit elkaar te houden, en een
vast voorvoegsel `[Melding]` om een mailfilter op te zetten.

**4. Een eigen emmer voor de begrenzer.** Bij een gedeelde sleutel kost een gezin dat
vanmiddag drie keer het contactformulier gebruikte vanavond een melding. De
begrenzer staat in de controller en niet in `throttle`-middleware, om dezelfde reden
als bij contact: middleware antwoordt met een JSON-429, en daar staat iemand die net
een melding probeerde te doen dan naar te kijken.

**5. De `action` van Turnstile wordt echt gecontroleerd**, op beide endpoints. Met
één sleutelpaar is een token van het contactformulier anders gewoon geldig op
`/api/melding`. Veel houdt dat niet tegen (tokens zijn eenmalig en leven enkele
minuten), maar het is de enige manier om de twee formulieren bij Cloudflare uit
elkaar te houden, en achteraf kan die historiek niet gesplitst worden.

**6. Geen bijlagen en geen bevestiging.** Een upload betekent bestanden op schijf,
virusscanning en een bewaartermijn; een bevestigingsmail betekent een kopie van de
melding in de inbox van een kind op een gedeelde computer.

## Wat bewust aanvaard is

`From` blijft `info@bclandegem.be` — een eigen afzenderadres vraagt een alias bij de
hostingpartij en eigen SMTP-rechten. Gevolg: drukt het aanspreekpunt op
**Beantwoorden**, dan vertrekt haar antwoord met de melding eronder gequote naar de
bestuursmailbox. De mail waarschuwt daar bovenaan voor, vóór de inhoud. De regel
"geen CC/BCC naar het bestuur" beschrijft dus wat de applicatie doet, niet wat er in
een mailprogramma kan gebeuren.

"Mail-only" slaat op de applicatie. De webserverlogs van beide domeinen houden IP en
tijdstip bij volgens het gewone hostingbeleid; dat is bewust niet aangeraakt.

---

## Wat de Website-repo moet doen

Voor wie in `bc-landegem/Website` werkt. Het contactformulier bestaat al; dit is wat
`/club/melden/` daar anders doet.

### Het formulier

```html
<form method="post" action={endpoint} autocomplete="off">
  <input type="hidden" name="loaded_at" />          <!-- ms sinds epoch, via JS bij het laden -->
  <input type="hidden" name="return_ok"    value="https://bclandegem.be/club/melden/bedankt/" />
  <input type="hidden" name="return_error" value="https://bclandegem.be/club/melden/" />
  <input type="text" name="website" tabindex="-1" autocomplete="off" hidden />  <!-- honeypot -->

  <textarea name="message" required maxlength="10000"></textarea>
  <input type="text" name="name"    maxlength="100" />
  <input type="text" name="contact" maxlength="190" />

  <div class="cf-turnstile" data-sitekey={siteKey} data-action="melding"></div>
</form>
```

Drie dingen die geen detail zijn:

- **`data-action="melding"`.** De server controleert die waarde nu; staat er iets
  anders, dan wordt elke inzending afgewezen met `?error=captcha`. Het
  contactformulier draagt om dezelfde reden `data-action="contact"`.
- **`maxlength="10000"` op het tekstvak**, gelijk aan de serverlimiet. Zonder die
  koppeling kan `?error=validation` afgaan op een lengte die nergens stond, en de
  redirect brengt de bezoeker terug op een **leeg** formulier — alles kwijt.
- **`autocomplete="off"`.** De tekst wordt nergens bewaard, ook niet in
  `localStorage`: een melding die achterblijft in de browser van een gedeelde
  computer is hetzelfde risico als de bevestigingsmail die er bewust niet is. Zet dat
  ook als zin op het formulier, vóór men begint te typen.

### De build moet falen zonder endpoint

```js
const endpoint = import.meta.env.PUBLIC_REPORT_ENDPOINT
if (!endpoint) throw new Error('PUBLIC_REPORT_ENDPOINT ontbreekt — /club/melden/ zou stil in het niets posten')
```

Zonder deze regel rendert `<form action={undefined}>` als een `<form>` zonder action,
post de browser naar de pagina zelf, en krijgt de melder een kale 405 van de
statische host. Voor het contactformulier hoeft dit niet — daar staat het
mailadres op de pagina.

```
PUBLIC_REPORT_ENDPOINT=https://intra.bclandegem.be/api/melding
```

### Twee foutteksten, niet zes

`/club/melden/` krijgt `?error=<code>` terug. Splits die in twee boodschappen:

| Codes | Boodschap |
| --- | --- |
| `unavailable`, `mail`, `throttle`, `captcha` | **Je bericht is niet aangekomen.** Spreek het aanspreekpunt aan in de zaal. |
| `validation`, `bot` | Milde herstelboodschap: vul een bericht in, of wacht even en probeer opnieuw. |

Voor de melder maakt het niet uit of Cloudflare of SMTP de boosdoener was; wat telt
is of hij iets anders moet doen. Bij `validation` en `bot` is er niets misgelopen —
daar hoort het alternatief juist níet bij, anders wordt een typfout onnodig
dramatisch.

### Bij een wissel van aanspreekpunt

`INTEGRITY_NAME` in `src/data/contact.ts` en de tekst op
`/club/aanspreekpunt-integriteit/` zijn twee van de vier plaatsen. De andere twee
staan aan de Laravel-kant en zijn de enige waar niets voor waarschuwt — zie
[DEPLOY.md](../DEPLOY.md), sectie "Als het Aanspreekpunt Integriteit wisselt".

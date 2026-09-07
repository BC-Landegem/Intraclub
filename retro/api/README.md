# Intraclub Api
Dit project is de backend van https://www.bclandegem.be/intraclub .

Hiermee beheren we de volledige intraclubapplicatie:
- Beheer van spelers
- Beheer van speeldag/wedstrijden/seizoenen
- Tussenstanden
- Statistieken per speler, speeldag en seizoen
- Loting van nieuwe speeldag

Alle functionaliteit wordt exposed via een REST Api. Zie src/routes.php voor alle endpoints.

## Installatie
- Alle dependencies kunen geïnstalleerd worden met [Composer](https://getcomposer.org/)
- database.sql kan uitgevoerd worden op een MySQL/MariaDB omgeving.
- Voeg een settings.php toe, op basis van settings.example.php
- Upload naar hostingbedrijf naar keuze. Endpoints beschikbaar via /index.php/{route}
    - index.php kan verwijderd worden uit URL mits .htaccess


## Dependencies
- [Joomla v3](https://www.joomla.org)
    - Voor authenticatie en authorisatie
    - Kan vervangen worden door eigen systeem. Zie src/routes.php -> CheckAccessRights
- [Slim Framework v3](http://www.slimframework.com/docs/v3/)
- PHP 7.+
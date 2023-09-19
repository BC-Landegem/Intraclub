# Intraclub

## API
### Installatie
1. Installeer PHP 7.4. Activeer pdo_mysql in php.ini
2. Download en installeer Composer https://getcomposer.org/download/
3. Download en installeer MariaDB https://mariadb.org/download/. Je kan dit beheren via DbBeaver https://dbeaver.io/download/
4. Run `php bin/console.php setup` en volg de instructies
5. Run `composer install` in de apifolder van het project
6. `composer start` om de server te starten


### Debugging
1. Installeer Xdebug m.b.v. wizard: https://xdebug.org/wizard
2. Installeer PHP Debug extensie in VS Code
3. Run and debug: Listen for Xdebug
4. Run `composer start` in de apifolder van het project


Gebaseerd op https://github.com/odan/slim4-skeleton
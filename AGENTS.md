# RankSphere für WordPress – Regeln für Agenten

WordPress-Plugin, das eine Website mit RankSphere (Laravel-App, Repo `tobiashaas/ranksphere`) verbindet.
Ziel: Veröffentlichung im Plugin-Verzeichnis von WordPress.org (Slug `ranksphere`). Plan in `docs/ARCHITECTURE.md`,
Schnittstelle in `docs/CONTRACT.md`, SEO-Plugins in `docs/SEO-PLUGINS.md`.

## Arbeiten

- Die offiziellen WordPress-Skills liegen in `.claude/skills` (unverändert, Herkunft in deren README) – zuerst dort
  nachsehen: `wp-plugin-development`, `wp-rest-api`, `wp-abilities-api`, `wp-plugin-directory-guidelines`, `wp-phpstan`, `wp-env`.
- Vor jedem Push `composer check` (Composer validieren, Versionen gleich, PHPCS/WPCS, PHPStan Level max, Unit-Tests).
  Integrationstests gegen echtes WordPress: `npm run env:start` und `npm run test:integration` (Docker nötig) – die
  CI fährt sie gegen WordPress 6.6 und aktuell, je SEO-Plugin einzeln. Die SEO-Plugins stehen nicht in `.wp-env.json`
  (wp-env würde alle gleichzeitig aktivieren); lokal installiert sie `npm run env:seo-plugins`, geladen werden sie im
  Test über `RANKSPHERE_TEST_SEO_PLUGIN=<ordner/datei.php>`.
- Unit-Tests (`tests/Unit`) sind reines PHP ohne WordPress; alles mit WordPress gehört nach `tests/Integration`.
- Mindestversionen: PHP 8.1, WordPress 6.6 – in Plugin-Header, `readme.txt`, `phpcs.xml.dist` (`testVersion`,
  `minimum_wp_version`) und CI-Matrix gleich halten. Version steht dreimal (Header, `VERSION`, `Stable tag`) –
  `composer check-versions` prüft das.
- Release-Zip: `bash bin/build-zip.sh` (nur Produktions-Autoloader, `.distignore` beachtet). Die CI prüft dasselbe
  Verzeichnis mit Plugin Check.

## Code

- `declare(strict_types=1)`, Namespace `RankSphere\` (PSR-4 in `src/`), Präfix `ranksphere` für alles Globale
  (Optionen, Hooks, Transients, Cron, Handles). Optionen nur über `Support\Options`, damit `uninstall.php` sie findet.
- Quelltexte der Oberfläche auf **Englisch** mit Text-Domain `ranksphere` (WordPress-Konvention, übersetzt wird über
  translate.wordpress.org); Deutsch kommt als Übersetzung.
- Sicherheit (immer): Eingaben früh säubern, Ausgaben spät escapen, Nonce **und** Rechteprüfung, REST-Routen nie
  ohne `permission_callback`, SQL nur mit `$wpdb->prepare()`. Anfragen von RankSphere zusätzlich per HMAC
  (`Security\Signature`) – nie nur dem Application Password vertrauen.
- Nichts beim Laden der Datei ausführen; Hooks in `Plugin::boot()`, Admin-Code nur im Admin.
- **Nie veröffentlichen:** Texte von RankSphere werden immer als Entwurf angelegt – serverseitig erzwungen.
- **Keine Daten ohne Zustimmung** (Guideline 7): Vor dem Verbinden geht nichts an RankSphere; die Zählung der
  KI-Crawler speichert keine IP-Adressen oder anderen personenbezogenen Daten.
- **Kein Dashboard-Hijacking** (Guideline 11): keine dauerhaften Hinweise, kein Upselling; die Übersicht bleibt auf
  der eigenen Seite bzw. in einem schließbaren Dashboard-Widget.
- Externe Bibliotheken nur mit Präfix (Strauss), Skripte/Styles nur aus dem Plugin, nichts von CDNs (Guideline 8).
- SEO-Felder nie direkt „irgendwie“ schreiben: je SEO-Plugin den in `docs/SEO-PLUGINS.md` festgelegten Weg
  (eigene Abilities, wo sie es können; sonst deren Speicher plus Cache/Index-Aktualisierung).

## Was nie ins Repo gehört

- Pro-/Premium-Versionen von SEO-Plugins (lizenziert) – nur lokal zum Nachsehen, Tests laufen gegen die freien Versionen.
- Zugangsdaten, Secrets, `.wp-env.override.json`.

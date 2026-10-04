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
- Ohne wp-env (z. B. Docker Hub drosselt): WordPress + `wordpress-develop/tests/phpunit` herunterladen, MariaDB starten,
  `wp-tests-config.php` in das Test-Verzeichnis legen und `WP_TESTS_DIR=<pfad> vendor/bin/phpunit -c phpunit.xml.dist`.
- Anmeldung per Application Password simulieren Tests mit `$GLOBALS['wp_rest_application_password_uuid']`
  (liest `rest_get_authenticated_app_password()`); ausgehende Anfragen fängt `pre_http_request` ab.
- Mindestversionen: PHP 8.1, WordPress 6.6 – in Plugin-Header, `readme.txt`, `phpcs.xml.dist` (`testVersion`,
  `minimum_wp_version`) und CI-Matrix gleich halten. Version steht dreimal (Header, `VERSION`, `Stable tag`) –
  `composer check-versions` prüft das.
- Release-Zip: `bash bin/build-zip.sh` (mit Updater, für Downloads von ranksphere.cloud) bzw. `--wporg` (ohne
  `src/Updates` und ohne `Update URI` – WordPress.org erlaubt keine eigenen Updater). Plugin Check läuft auf `--wporg`.
- **Release:** Version an drei Stellen setzen (Header, `VERSION`, `Stable tag`), Changelog-Abschnitt `= <version> =` in
  `readme.txt`, mergen, dann Tag `v<version>` auf `main` pushen → `release.yml` prüft, baut beide Zips +
  `ranksphere.json` und legt das GitHub-Release an (mit Suffix = Pre-Release). RankSphere bietet es danach je Kanal an.
  Reihenfolge bis 1.0: `1.0.0-alpha.N` → `1.0.0-beta.N` → `1.0.0-rc.N` → `1.0.0` (**1.0.0 nur nach Freigabe durch Tobias**).

## Code

- `declare(strict_types=1)`, Namespace `RankSphere\` (PSR-4 in `src/`), Präfix `ranksphere` für alles Globale
  (Optionen, Hooks, Transients, Cron, Handles). Optionen nur über `Support\Options`, damit `uninstall.php` sie findet.
- Quelltexte der Oberfläche auf **Englisch** mit Text-Domain `ranksphere` (WordPress-Konvention, übersetzt wird über
  translate.wordpress.org); Deutsch kommt als Übersetzung.
- Sicherheit (immer): Eingaben früh säubern, Ausgaben spät escapen, Nonce **und** Rechteprüfung, REST-Routen nie
  ohne `permission_callback`, SQL nur mit `$wpdb->prepare()`. Anfragen von RankSphere zusätzlich per HMAC –
  jede neue Route prüft im `permission_callback` über `Security\RequestVerifier` (Signatur, einmalig, genau das
  freigegebene Application Password) und danach die Rechte des Benutzers. Nie nur dem Application Password vertrauen.
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

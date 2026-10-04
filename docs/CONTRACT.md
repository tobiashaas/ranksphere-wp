# Schnittstelle RankSphere ↔ WordPress

Version 1 (`ranksphere/v1`). M1 (Verbinden, Status, Trennen) umgesetzt, der Rest Entwurf für M2–M5; Änderungen hier zuerst, dann in beiden Repos.

## Sicherheit (gilt für alle Aufrufe)

**RankSphere → WordPress** (`/wp-json/ranksphere/v1/…`):

- `Authorization: Basic <user_login:application_password>` – über HTTPS; der Benutzer bestimmt die Rechte
  (`edit_posts` für Entwürfe, `edit_post` je Beitrag für SEO-Felder, `manage_options` für die Verbindung).
- `X-RankSphere-Timestamp: <unix>` und `X-RankSphere-Signature: <hex>` =
  `HMAC-SHA256( secret, timestamp + "\n" + METHOD + "\n" + pfad + "\n" + body )` (`Security\Signature`).
  - `secret` ist der übergebene String selbst (kein Base64-Dekodieren), mindestens 32 Zeichen.
  - `pfad` = **REST-Route** (`/ranksphere/v1/status`, unabhängig von Permalinks, `/wp-json` oder Unterverzeichnis),
    bei Query-Parametern `?` + Parameter nach Schlüssel sortiert, RFC 3986 (`a=1&b=x%20y`); `rest_route` zählt nicht mit.
  - `body` = roher Body, bei GET leer.
  - Testvektor (beide Repos testen ihn): Secret `a-secret-that-is-long-enough-for-hmac-sha256`, `1700000000`, `GET`,
    `/ranksphere/v1/status?a=1&b=x%20y`, leerer Body → `1385b09a84e589be88f16929e87e77f4be98f4f4157112516b2495e6aed27be6`.
  Abweichung > 300 s, falsche oder schon benutzte Signatur → `401 ranksphere_bad_signature`. Ausnahme: der erste
  Aufruf `POST /connection`, der das Secret erst überträgt (nur Application Password + `manage_options`).
- Die Anfrage muss mit **genau dem** Application Password kommen, das bei der Verbindung benutzt wurde (Benutzer und
  UUID werden gespeichert); ein anderes Passwort, auch eines Administrators → `403 ranksphere_forbidden`.
- Jede Route hat einen `permission_callback`; Eingaben laufen durch das JSON-Schema der Route.

**WordPress → RankSphere** (`https://ranksphere.cloud/api/wordpress/v1/…`):

- `Authorization: Bearer <site_token>` (bei der Verbindung ausgegeben, nur serverseitig gespeichert) plus dieselbe
  HMAC-Signatur; `pfad` ist hier der URL-Pfad bei RankSphere (`/api/wordpress/v1/disconnect`). Nie aus dem Browser –
  immer `wp_safe_remote_*` vom Server.

## Fehlerform

WordPress-üblich: `{ "code": "ranksphere_…", "message": "…", "data": { "status": 4xx } }`. Codes u. a.
`ranksphere_not_connected`, `ranksphere_bad_signature`, `ranksphere_forbidden`, `ranksphere_not_found`,
`ranksphere_invalid_content`, `ranksphere_seo_plugin_failed`.

## Routen in WordPress

### `GET /status` (M1)

Für RankSphere vor jeder Aktion: verbunden?, Version, WordPress-/PHP-Version, aktives SEO-Plugin, Abilities verfügbar.
Signiert, Benutzer braucht `edit_posts`. Ob das Plugin überhaupt installiert ist, sieht RankSphere vorher ohne Anmeldung
im REST-Index (`GET /wp-json/` bzw. `/?rest_route=/`: Namespace `ranksphere/v1`; dort steht auch die Adresse von
`authorize-application.php` unter `authentication.application-passwords.endpoints.authorization`).

```json
{ "connected": true, "project_id": "01J…", "plugin_version": "0.2.0", "wp_version": "7.0", "php_version": "8.3",
  "seo_plugin": { "slug": "wordpress-seo", "name": "Yoast SEO", "version": "28.6" },
  "abilities": true, "site_url": "https://example.com" }
```

### `POST /connection` · `DELETE /connection` (M1)

```json
{ "project_id": "01J…", "project_name": "Bäckerei Muster", "project_url": "https://ranksphere.cloud/projects/…",
  "api_url": "https://ranksphere.cloud/api/wordpress/v1", "secret": "<32–256 Zeichen>", "site_token": "<32–256 Zeichen>" }
```

- Nur mit Application Password (Cookie-Login reicht nicht → `401 ranksphere_app_password_required`) eines Benutzers
  mit `manage_options` (sonst `403`). Antwort `201` mit dem Status von `GET /status`.
- `api_url`/`project_url` nur `https` (`http` nur bei `wp_get_environment_type()` `local`/`development`) → sonst `400 ranksphere_invalid_url`.
- Schon verbunden: nur **signiert mit dem aktuellen Secret** (RankSphere erneuert die Verbindung), sonst
  `409 ranksphere_already_connected` mit `data.project_name`. Neu verbinden mit neuem Application Password: erst
  `DELETE /connection` mit den alten Zugangsdaten, dann `POST`.

`DELETE` (signiert) trennt: löscht die Verbindung und **das Application Password selbst** – danach hat RankSphere keinen
Zugang mehr. Antwort: Status mit `connected: false`.

Die Website trennt von sich aus, wenn ein Administrator in WordPress „Trennen“ klickt, das Application Password
widerrufen oder der freigebende Benutzer gelöscht wird – und meldet das an `POST /disconnect` (siehe unten).

### `GET /posts/{id}/seo` · `PUT /posts/{id}/seo` (M2)

```json
{ "title": "…", "description": "…", "focus_keywords": ["…"], "canonical": null, "noindex": false }
```

- `PUT` ist ein Teil-Update: nur gesendete Felder ändern sich; `null` = Vorlage des SEO-Plugins verwenden.
- Antwort: `{ "before": {…}, "after": {…}, "rendered": { "title": "…", "description": "…" }, "history_id": 12 }`.
- `POST /posts/{id}/seo/undo` mit `{ "history_id": 12 }` stellt den Stand davor wieder her.
- Gilt für Beiträge, Seiten und öffentliche Beitragstypen; Terme und Startseite folgen.

### `POST /drafts` · `PUT /drafts/{ranksphere_id}` (M3)

```json
{ "ranksphere_id": "draft_123", "post_type": "post", "title": "…", "slug": "…",
  "content": "<!-- wp:heading -->…<!-- /wp:heading -->", "excerpt": "…",
  "seo": { "title": "…", "description": "…", "focus_keywords": ["…"] },
  "categories": [], "tags": [] }
```

- Status ist **immer** `draft`, egal was gesendet wird. Ein Entwurf mit derselben `ranksphere_id` wird aktualisiert,
  solange er noch Entwurf ist; ist er veröffentlicht → `409 ranksphere_already_published`.
- Inhalt wird mit `parse_blocks()` geprüft und mit `wp_kses_post()` gefiltert.
- Antwort: `{ "post_id": 42, "edit_url": "https://…/wp-admin/post.php?post=42&action=edit", "preview_url": "…" }`.

### `GET /overview` (M4, für die eigene Admin-Oberfläche)

Nur für eingeloggte Benutzer mit `edit_posts` (Cookie + REST-Nonce), Daten aus dem 10-Minuten-Cache.

## Routen in RankSphere

| Route | Zweck |
|---|---|
| `GET /overview` | Kennzahlen, Datenstand, Aufgaben (Top 5), Texte, Tonalität |
| `GET /pages?url=…` | Daten zu einer Seite für das Editor-Panel: Suchanfragen, Position, Vorschläge für Titel/Beschreibung |
| `POST /crawler-visits` | `{ "day": "2026-10-04", "visits": [ { "bot": "GPTBot", "path": "/", "hits": 12 } ] }` (M5) |
| `POST /disconnect` | die Website hat die Verbindung getrennt: `{ "reason": "admin" \| "revoked" \| "user_deleted" }` (M1) |

## Plugin-Updates (Builds von ranksphere.cloud, nicht WordPress.org)

Header `Update URI: https://ranksphere.cloud/wordpress/plugin` → WordPress fragt den Filter
`update_plugins_ranksphere.cloud` (`Updates\Updater`), höchstens alle 6 Stunden (Transient je Kanal):

`GET https://ranksphere.cloud/api/wordpress/v1/plugin?channel=alpha&version=1.0.0-alpha.1` (ohne Anmeldung,
User-Agent `RankSphere-WordPress/<version>`, keine Website-Adresse) →

```json
{ "version": "1.0.0-alpha.2", "package": "https://ranksphere.cloud/wordpress/plugin/ranksphere-1.0.0-alpha.2.zip",
  "url": "https://github.com/tobiashaas/ranksphere-wp/releases/tag/v1.0.0-alpha.2",
  "requires": "6.6", "requires_php": "8.1", "tested": "7.1", "changelog": "<ul><li>…</li></ul>" }
```

- Kanäle `stable` < `rc` < `beta` < `alpha`: ein Kanal bekommt seine Stufe und jede stabilere (`Updates\Channel`).
  Ohne Wahl gilt die Stufe der installierten Version.
- `404`, wenn es im Kanal nichts gibt. Das Plugin nimmt nur ein `package` vom Host von RankSphere an.
- Quelle in RankSphere: die GitHub-Releases dieses Repos (`ranksphere.zip` + `ranksphere.json`, siehe Release-Workflow).

## Versionierung

Neue Felder sind abwärtskompatibel; Brüche nur mit `ranksphere/v2`. RankSphere liest die Plugin-Version aus
`GET /status` und bietet nur an, was die installierte Version kann.

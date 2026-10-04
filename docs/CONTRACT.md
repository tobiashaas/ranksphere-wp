# Schnittstelle RankSphere ↔ WordPress

Version 1 (`ranksphere/v1`). Entwurf – wird mit M1–M5 umgesetzt; Änderungen hier zuerst, dann in beiden Repos.

## Sicherheit (gilt für alle Aufrufe)

**RankSphere → WordPress** (`/wp-json/ranksphere/v1/…`):

- `Authorization: Basic <user_login:application_password>` – über HTTPS; der Benutzer bestimmt die Rechte
  (`edit_posts` für Entwürfe, `edit_post` je Beitrag für SEO-Felder, `manage_options` für die Verbindung).
- `X-RankSphere-Timestamp: <unix>` und `X-RankSphere-Signature: <hex>` =
  `HMAC-SHA256( secret, timestamp + "\n" + METHOD + "\n" + path_mit_query + "\n" + body )` (`Security\Signature`).
  Abweichung > 300 s oder falsche Signatur → `401 ranksphere_bad_signature`. Ausnahme: der erste Aufruf
  `POST /connection`, der das Secret erst überträgt (nur Application Password + `manage_options`).
- Jede Route hat einen `permission_callback`; Eingaben laufen durch das JSON-Schema der Route.

**WordPress → RankSphere** (`https://ranksphere.cloud/api/wordpress/v1/…`):

- `Authorization: Bearer <site_token>` (bei der Verbindung ausgegeben, nur serverseitig gespeichert) plus dieselbe
  HMAC-Signatur. Nie aus dem Browser – immer `wp_remote_*` vom Server.

## Fehlerform

WordPress-üblich: `{ "code": "ranksphere_…", "message": "…", "data": { "status": 4xx } }`. Codes u. a.
`ranksphere_not_connected`, `ranksphere_bad_signature`, `ranksphere_forbidden`, `ranksphere_not_found`,
`ranksphere_invalid_content`, `ranksphere_seo_plugin_failed`.

## Routen in WordPress

### `GET /status` (M1)

Für RankSphere vor jeder Aktion: verbunden?, Version, WordPress-/PHP-Version, aktives SEO-Plugin, Abilities verfügbar.

```json
{ "connected": true, "plugin_version": "0.2.0", "wp_version": "7.0", "php_version": "8.3",
  "seo_plugin": { "slug": "wordpress-seo", "name": "Yoast SEO", "version": "28.6" },
  "abilities": true, "site_url": "https://example.com" }
```

### `POST /connection` · `DELETE /connection` (M1)

```json
{ "project_id": "01J…", "project_name": "Bäckerei Muster", "api_url": "https://ranksphere.cloud/api/wordpress/v1",
  "secret": "<64 Byte, base64>", "site_token": "<token>" }
```

`DELETE` trennt (löscht Secret + Token, das Application Password widerruft RankSphere über die Core-Route
`DELETE /wp/v2/users/me/application-passwords/<uuid>`).

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
| `POST /disconnect` | die Website hat die Verbindung getrennt |

## Versionierung

Neue Felder sind abwärtskompatibel; Brüche nur mit `ranksphere/v2`. RankSphere liest die Plugin-Version aus
`GET /status` und bietet nur an, was die installierte Version kann.

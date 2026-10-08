# Schnittstelle RankSphere ↔ WordPress

Version 1 (`ranksphere/v1`). M1–M4 (Verbinden, SEO-Felder, Entwürfe, Übersicht) umgesetzt, der Rest Entwurf für M5; Änderungen hier zuerst, dann in beiden Repos.

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

Antworten von RankSphere: ein `422`/`429` trägt `message` (Satz in der Sprache des Benutzers) – das Plugin zeigt ihn
so an (`RankSphereClient::failure()`), alle anderen Fehler als eigenen Satz zum Statuscode.

## Routen in WordPress

### `GET /status` (M1)

Für RankSphere vor jeder Aktion: verbunden?, Version, WordPress-/PHP-Version, aktives SEO-Plugin, Abilities verfügbar.
Signiert, Benutzer braucht `edit_posts`. Ob das Plugin überhaupt installiert ist, sieht RankSphere vorher ohne Anmeldung
im REST-Index (`GET /wp-json/` bzw. `/?rest_route=/`: Namespace `ranksphere/v1`; dort steht auch die Adresse von
`authorize-application.php` unter `authentication.application-passwords.endpoints.authorization`).

```json
{ "connected": true, "project_id": "01J…", "plugin_version": "0.2.0", "wp_version": "7.0", "php_version": "8.3",
  "seo_plugin": { "slug": "wordpress-seo", "name": "Yoast SEO", "version": "28.6" },
  "abilities": true, "site_url": "https://example.com",
  "post_types": [ { "name": "post", "label": "Beitrag" }, { "name": "leistungen", "label": "Leistung" } ] }
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

### `GET /lookup?url=…` (M2)

Welcher Beitrag zu einer Adresse der Website gehört (`url_to_postid()`, statische Startseite eingeschlossen). Recht
`edit_posts`, und den Beitrag muss der Benutzer bearbeiten dürfen – sonst `404 ranksphere_not_found`.

```json
{ "post_id": 42, "post_type": "page", "status": "publish", "title": "Leistungen", "url": "https://…/leistungen/",
  "edit_url": "https://…/wp-admin/post.php?post=42&action=edit", "preview_url": "…" }
```

### `GET /posts/{id}/seo` · `PUT /posts/{id}/seo` (M2)

Recht `edit_post` für diesen Beitrag. `GET` liefert die Felder dieses Beitrags (wie `/lookup`) plus:

```json
{ "seo_plugin": { "slug": "wordpress-seo", "name": "Yoast SEO", "version": "28.6" },
  "supports": ["title", "description", "focus_keywords", "canonical", "noindex"],
  "seo": { "title": "…", "description": "…", "focus_keywords": ["…"], "canonical": null, "noindex": null } }
```

- Rohwerte, nie gerenderte (aufgelöste Variablen); `null` = Vorlage/Standard des SEO-Plugins. `seo_plugin` ist `null`
  ohne SEO-Plugin – dann gibt das Plugin die Felder selbst aus. `supports` sagt, was das Plugin speichern kann
  (TSF und Slim SEO frei: kein Fokus-Keyword).
- `PUT` ist ein Teil-Update: nur gesendete Felder ändern sich, `null` stellt den Standard wieder her. Falscher Typ →
  `400 ranksphere_invalid_seo`. Texte werden zu einer Zeile Klartext, `canonical` nur http(s), höchstens 10 Keywords.
- Antwort: `{ "post_id": 42, "before": {…}, "after": {…}, "history_id": "<uuid>" | null, "unsupported": [] }` –
  `history_id` ist `null`, wenn sich nichts geändert hat; `unsupported` nennt gesendete Felder, die das Plugin nicht kennt.
- `POST /posts/{id}/seo/undo` mit `{ "history_id": "<uuid>" }` stellt den Stand davor wieder her (neuer Verlaufseintrag).
  Schon zurückgenommen → `409 ranksphere_already_undone`; Feld seitdem anders → `409 ranksphere_changed_since`.
- Verlauf je Beitrag in der Post-Meta `_ranksphere_seo_history` (die letzten 20 Änderungen).
- Gilt für Beiträge, Seiten und öffentliche Beitragstypen; Terme und Startseiten-Optionen folgen.

### `POST /drafts` (M3)

```json
{ "ranksphere_id": "text-123", "post_type": "page", "title": "…", "slug": "…",
  "content": "<!-- wp:heading -->…<!-- /wp:heading -->", "excerpt": "…",
  "seo": { "title": "…", "description": "…", "focus_keywords": ["…"] } }
```

- Recht `edit_posts` und `create_posts` des Beitragstyps (nur öffentliche Typen, keine Anhänge) – sonst `400`.
- Status ist **immer** `draft`, egal was gesendet wird. Ein Entwurf mit derselben `ranksphere_id` (Post-Meta
  `_ranksphere_draft_id`) wird aktualisiert, solange er Entwurf/ausstehend ist; ist er veröffentlicht →
  `409 ranksphere_already_published` (mit `data.post_id`).
- Inhalt (Block-Markup) mit `wp_kses_post()` gefiltert. SEO-Felder wie bei `PUT /posts/{id}/seo`.
- Antwort `201` (neu) bzw. `200` (aktualisiert): Felder wie `/lookup` plus `"created": true|false`.
- **Platzhalter-Sperre:** Enthält ein Entwurf von RankSphere noch `[[…]]` (fehlende Angaben), setzt das Plugin
  „Veröffentlichen“/„Planen“ zurück auf Entwurf und erklärt das im Editor. Kategorien/Schlagwörter folgen.
- **`revises`** (ab 1.0.0-alpha.7, optional): der Text ist für diesen bestehenden Beitrag (Recht `edit_post` darauf, sonst
  `403 ranksphere_forbidden`). Unveröffentlicht → der Text kommt in diesen Beitrag (Autor bleibt, der Inhalt davor wird
  WordPress-Revision). Veröffentlicht/geplant/privat → das Original bleibt unberührt; der Text wird ein eigener Entwurf
  desselben Typs mit `_ranksphere_revises` = Original, den der Autor im Editor mit „In Original übernehmen“ übernimmt.
  Die Antwort trägt dann `post_id` des Entwurfs bzw. Originals.

`post_types` (ab 1.0.0-alpha.5): öffentliche Typen mit Editor, die der Benutzer von RankSphere anlegen darf – auch
eigene wie „Leistungen“, mit dem Namen der Website. RankSphere fragt damit beim ersten Senden eines Textes, als was er
angelegt wird (`POST /drafts` mit diesem `post_type`); ohne die Liste (ältere Plugins) Beitrag oder Seite.

### `GET|POST /page-suggestion?post_id=…`, `POST /page-suggestion/apply` (ab 1.0.0-alpha.5, nur eingeloggte Benutzer)

„Vorschlag erstellen“ in der Box im Editor, Recht `edit_post` für genau diesen Beitrag. `POST` fragt RankSphere
(`POST /suggestions` mit Permalink, den aktuellen Werten des SEO-Plugins und dem Text des Beitrags ohne Shortcodes),
`GET` fragt den Stand ab (das Skript alle 3 s, solange `pending`). Antwort jeweils `{ "status": "none|pending|done|failed",
"html": "…" }`, auf dem Server gerendert und escaped. `apply` mit `field` (`title|description`) und `value` schreibt über
das SEO-Plugin mit Verlauf (`source: suggestion`) und meldet die Änderung an RankSphere (`POST /changes`); der Hinweis
danach bittet, die Seite neu zu laden, bevor der Beitrag gespeichert wird (sonst schreiben die Felder des SEO-Plugins im
Editor den alten Wert zurück).

### `GET /editable-posts?post_type=…&search=…`, `POST /revisions/applied` (ab 1.0.0-alpha.7, nur eingeloggte Benutzer)

`editable-posts`: Beiträge eines Typs, die der Benutzer bearbeiten darf (`perm: editable`, neueste Änderung zuerst, 30)
für „bestehenden Beitrag überarbeiten“ auf der Seite „Texte“ – `{ "posts": [ { "id", "title" } ] }`.
`revisions/applied` `{ "original", "revision", "before" }`: der Block-Editor hat das Original mit der Überarbeitung
gespeichert (Recht `edit_post` auf beide, `revision` muss `_ranksphere_revises` = `original` tragen). SEO-Felder der
Überarbeitung folgen (SEO-Verlauf `source: revision`), `before` ist die WordPress-Revision mit dem Inhalt davor (Verlauf
→ WordPress' Vergleich/Wiederherstellen). Der klassische Editor meldet dasselbe über versteckte Felder im Formular.

### `GET /page-insights?post_id=…` (M4, nur für eingeloggte Benutzer – nicht für RankSphere)

Füllt die RankSphere-Box im Editor: Cookie + REST-Nonce, Recht `edit_post` für genau diesen Beitrag. Antwort
`{ "html": "…" }`, auf dem Server gerendert und escaped (`Admin\PageBox`). Veröffentlicht → Daten aus RankSphere
`GET /pages?url=<permalink>`; Entwurf → nur der Link zum Text in RankSphere (`_ranksphere_draft_id`).
Die Übersichtsseite und das Dashboard-Widget rendert der Server direkt, ohne eigene Route.

## Routen in RankSphere

| Route | Zweck |
|---|---|
| `GET /overview?lang=de_DE` | Übersicht für den Admin (M4), Sätze in der Sprache des WordPress-Benutzers |
| `GET /pages?url=…&lang=…` | Daten zu einer Seite für die Box im Editor (M4); nur Adressen der verbundenen Website, sonst `422` |
| `POST /suggestions` | Vorschlag starten: `{ "url", "lang", "current": { "title", "description" }, "content" }` → `{ "status": "pending" }` oder `failed` mit `error` (kein KI-Zugang, Abo abgelaufen, mehr als 20 je Stunde) |
| `GET /suggestions?url=…` | Stand: `none`, `pending`, `done` mit `title`/`description` (ein Feld, das RankSphere' Prüfung nicht besteht, ist `null`) und `why`, `failed` mit `error` |
| `POST /changes` | in WordPress übernommene Änderung: `{ "url", "post_id", "before", "after", "history_id", "by" }` → `201`; erscheint im Änderungsprotokoll, Rückgängig auch aus RankSphere |
| `GET /texts?lang=…` | Seite „Texte“ (ab 1.0.0-alpha.6): `{ "types": [ { key, label, hint, required: [ { key, label } ] } ], "texts": [ Text-Zeile wie in `/overview` ], "blocked": "…" \| null, "url" }` – `blocked` sagt, warum gerade kein Text starten kann (kein KI-Zugang, Abo) |
| `POST /texts` | Text starten: `{ "type", "topic", "post_type", "notes", "required": { key: "…" }, "by": "Anzeigename", "lang", "source": { post_id, post_type, status, title, path, content } , "site_pages": [ { title, path } ] }` → `201 { "id" }`; `422`/`429` mit `message` (gesperrt, mehr als 10 je Stunde und Website). Die KI-Kosten trägt, wer die Website verbunden hat. `source` (ab alpha.7): der bestehende Beitrag – sein Text (max. 12 000 Zeichen, auch von Entwürfen) gilt als Aussage des Unternehmens. `site_pages` (max. 200): veröffentlichte Seiten und Beiträge – die einzigen erlaubten internen Linkziele. Ältere Plugins senden `target_page` |
| `GET /texts/ideas?lang=…` | (ab alpha.7) `{ "ideas": [ { title, why, area, impact, type, type_label, topic, page } ] }` – offene Aufgaben, die einen Text brauchen; das Plugin hält sie 10 Minuten |
| `GET /texts/{id}` | Stand: Text-Zeile + `topic`, `error`, `html` (Markdown → HTML, eingegebenes HTML escaped, `[[…]]` in `<mark>`), `seo_title`, `meta_description`, `questions`, `answers`, `review` `{ score, passed, issues: [ { priority, problem, fix } ] }` \| null, `post_type`, `default_post_type`, `source` `{ post_id, title, path }` \| null, `versions` `[ { number, reason, label, by, score, created_at } ]` (neueste = aktuelle zuerst) |
| `POST /texts/{id}/answers` | `{ "answers": { "Frage": "Antwort" }, "by" }` → RankSphere schreibt neu (`409`, solange er noch schreibt) |
| `POST /texts/{id}/notes` | (ab alpha.7) `{ "note": "kürzer", "by" }` → RankSphere überarbeitet die aktuelle Fassung mit dem Hinweis; `422`/`429` wie beim Starten |
| `GET /texts/{id}/versions/{n}` | (ab alpha.7) eine Version: `number`, `label`, `by`, `score`, `created_at`, `html`, `seo_title`, `meta_description`, `questions` |
| `POST /texts/{id}/restore` | (ab alpha.7) `{ "version": n, "by" }` → die Version wird wieder die aktuelle (ohne KI, selbst eine neue Version) → `{ "version": neue Nummer }` |
| `GET /texts/{id}/draft?post_type=…` | Payload wie `POST /drafts` (Block-Markup, SEO-Felder, `revises` bei einem Text für einen bestehenden Beitrag); ein Text mit gespeichertem Beitragstyp behält ihn, einer für einen bestehenden Beitrag bekommt dessen Typ. Das Plugin legt den Entwurf mit dem aktuellen Benutzer als Autor an |
| `POST /texts/{id}/pushed` | `{ "post_id", "edit_url", "post_type" }` – der Entwurf liegt in WordPress |
| `POST /crawler-visits` | `{ "day": "2026-10-04", "visits": [ { "bot": "GPTBot", "path": "/", "hits": 12 } ] }` (M5) |
| `POST /disconnect` | die Website hat die Verbindung getrennt: `{ "reason": "admin" \| "revoked" \| "user_deleted" }` (M1) |

GETs werden wie POSTs signiert (Pfad + sortierte Query, leerer Body). Das Plugin hält jede Antwort 10 Minuten im
Transient (je Sprache und Projekt), eine fehlgeschlagene 2 Minuten.

`GET /overview` →

```json
{ "project": { "name": "…", "url": "https://ranksphere.cloud/projects/…" },
  "period": { "from": "2026-09-04", "to": "2026-10-01", "days": 28 },
  "verdict": { "tone": "good|watch|act|neutral", "label": "…", "text": "…" },
  "search": { "clicks": 1234, "impressions": 40210, "position": 8.4,
              "previous": { "clicks": 1122, "impressions": 41000, "position": 9.1 } | null, "url": "…" } | null,
  "ai": { "mention_rate": 0.25, "citation_rate": 0.1, "measured_at": "…", "url": "…" } | null,
  "data_problems": ["…"],
  "tasks": [ { "title": "…", "why": "…", "area": "…", "impact": "high|medium|low", "state": "open|not_fixed", "url": "…" } ],
  "tasks_total": 7,
  "texts": [ { "id": 7, "title": "…", "type": "…", "status": "pending|done|failed", "state": "…", "tone": "good|watch|act|neutral",
               "open_questions": 2, "wordpress_post_id": 42 | null, "source_post_id": 12 | null, "by": "…", "created_at": "…", "url": "…" } ],
  "texts_url": "…",
  "voice": { "address": "Sie", "voice": "…", "do": [], "dont": [], "preferred_terms": [], "taboo_words": [] } | null,
  "voice_url": "…" }
```

`GET /pages` → `{ "path", "period", "search": { clicks, previous_clicks, impressions, previous_impressions, position,
previous_position } | null, "queries": [ { query, clicks, impressions, position } ], "analytics", "website_check":
[ { code, severity: error|warning|notice, title, fix } ] | null, "broken_backlinks": 2, "url" }`.

`previous` ist `null`, wenn der Zeitraum davor kein fairer Vergleich ist (neues Projekt, Datenlücken) – dann zeigt das
Plugin keine Veränderung. Aufgaben ohne Einrichtungsschritte (die sind für RankSphere, nicht für WordPress).

## Plugin-Updates (Builds von ranksphere.cloud, nicht WordPress.org)

Header `Update URI: https://ranksphere.cloud/wordpress/plugin` → WordPress fragt den Filter
`update_plugins_ranksphere.cloud` (`Updates\Updater`), höchstens alle 6 Stunden (Transient je Kanal):

`GET https://ranksphere.cloud/api/wordpress/v1/plugin?channel=alpha&version=1.0.0-alpha.1` (ohne Anmeldung,
User-Agent `RankSphere-WordPress/<version>`, keine Website-Adresse) →

```json
{ "version": "1.0.0-alpha.2", "package": "https://ranksphere.cloud/api/wordpress/v1/plugin/ranksphere-1.0.0-alpha.2.zip",
  "url": "https://github.com/tobiashaas/ranksphere-wp/releases/tag/v1.0.0-alpha.2",
  "requires": "6.6", "requires_php": "8.1", "tested": "7.1", "changelog": "<ul><li>…</li></ul>" }
```

- Kanäle `stable` < `rc` < `beta` < `alpha`: ein Kanal bekommt seine Stufe und jede stabilere (`Updates\Channel`).
  Ohne Wahl gilt die Stufe der installierten Version.
- `404`, wenn es im Kanal nichts gibt. Das Plugin nimmt nur ein `package` vom Host von RankSphere an.
- Quelle in RankSphere: die GitHub-Releases dieses Repos (`ranksphere.zip` + `ranksphere.json`, siehe Release-Workflow).
- Zum Installieren von Hand: `https://ranksphere.cloud/api/wordpress/v1/plugin/latest.zip` (neueste stabile Version,
  vor 1.0 die neueste Testversion; `?channel=alpha` usw. für einen Kanal).

## Versionierung

Neue Felder sind abwärtskompatibel; Brüche nur mit `ranksphere/v2`. RankSphere liest die Plugin-Version aus
`GET /status` und bietet nur an, was die installierte Version kann.

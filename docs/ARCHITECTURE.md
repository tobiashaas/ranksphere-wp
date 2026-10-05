# Architektur

## Ziel

Eine WordPress-Website mit ihrem RankSphere-Projekt verbinden, damit

1. Texte aus RankSphere als **Entwurf** in WordPress landen (nie veröffentlicht),
2. Seitentitel, Beschreibung, Fokus-Keyword, Canonical und noindex aus RankSphere im **aktiven SEO-Plugin**
   gesetzt werden können – für neue Entwürfe und für bestehende Seiten,
3. die RankSphere-Übersicht (Kennzahlen, Aufgaben, Texte, Tonalität) **im WordPress-Admin** sichtbar ist,
4. RankSphere sieht, wann KI-Crawler (GPTBot, ClaudeBot, PerplexityBot …) welche Seiten abrufen.

## Grundsätze

- **Das Plugin bleibt klein, RankSphere denkt.** Texte, Prüfung, Regeln, Markdown→Blöcke passieren in RankSphere;
  das Plugin nimmt entgegen, prüft, schreibt, zeigt an. Weniger Code beim Kunden = weniger Angriffsfläche, seltener Updates.
- **Nichts wird veröffentlicht.** Entwürfe bleiben Entwurf (serverseitig erzwungen). Enthält ein Text noch
  Platzhalter `[[Angabe fehlt: …]]`, lässt WordPress ihn nicht veröffentlichen.
- **Jede Änderung an einer bestehenden Seite ist nachvollziehbar und umkehrbar** (Änderungsprotokoll je Seite mit
  alt/neu, wer, wann; „Rückgängig“ im Admin und per API).
- **Kein Datenfluss ohne Zustimmung** (WordPress.org-Guideline 7): erst nach „Mit RankSphere verbinden“.
- **WordPress-Weg vor Eigenbau:** Application Passwords für die Anmeldung, Abilities API für Funktionen,
  Settings API, `@wordpress/components` für die Oberfläche, WP-Cron für Hintergrundarbeit.

## Bausteine

```
ranksphere.php            Header, Autoloader, Lifecycle-Hooks, Plugin::boot()
src/
  Plugin.php              registriert alle Hooks
  Lifecycle/              Activator, Deactivator, Upgrader (Schema-/Datenmigration je Version)
  Connection/             Verbindung: Start, Freigabe, Speichern, Trennen, Widerruf erkennen
  Security/               Signature (HMAC), Request-Prüfung (Zeitstempel, Wiederholungen)
  Rest/                   Routen unter ranksphere/v1 (siehe CONTRACT.md)
  Seo/                    SeoAdapter-Interface + Yoast, RankMath, SeoPress, Aioseo, SeoFramework, SlimSeo, Native
  Content/                Entwürfe anlegen/aktualisieren, Platzhalter-Sperre
  History/                Änderungsprotokoll + Rückgängig
  Crawlers/               Zählung der KI-Crawler (eigene Tabelle, täglich an RankSphere)
  Admin/                  Übersicht, Einstellungen, Dashboard-Widget, Box im Editor
  Insights/               Daten aus RankSphere holen, cachen, typgeprüft lesen
  Abilities/              eigene Abilities (WordPress 6.9+), damit auch KI-Agenten/MCP sie nutzen können
```

## Verbinden

Ausgelöst im WordPress-Admin („Mit RankSphere verbinden“) oder in RankSphere (Projekt → WordPress verbinden):

1. RankSphere: Nutzer meldet sich an und wählt das Projekt.
2. RankSphere leitet auf `https://<site>/wp-admin/authorize-application.php?app_name=RankSphere&app_id=<uuid>&success_url=…`
   – **WordPress-Bordmittel** (Application Passwords, seit 5.6): der Nutzer sieht, wer Zugriff bekommt, und genehmigt.
3. WordPress leitet mit `user_login` + Passwort zurück; RankSphere speichert beides verschlüsselt.
4. RankSphere ruft `POST /wp-json/ranksphere/v1/connection` auf (Basic Auth mit dem Application Password) und
   übergibt Projekt, API-Adresse, ein **frisches Signatur-Secret** und ein Site-Token für Rückrufe.
5. Ab jetzt ist jede Anfrage doppelt gesichert: Application Password (welcher Benutzer, welche Rechte) **und**
   HMAC-Signatur mit Zeitstempel (kommt wirklich von RankSphere, nicht wiederholbar).

Trennen: im Admin oder in RankSphere; widerruft der Nutzer das Application Password im Profil, merkt das Plugin es
(401 bei RankSphere) und zeigt „Verbindung getrennt“. Secrets liegen als Option ohne Autoload und verlassen den
Server nie (nicht im Browser, nicht in REST-Antworten).

## SEO-Felder

`Seo\SeoAdapter` mit `detect()`, `read( $post_id )` (roh + gerendert), `write( $post_id, SeoFields )`. Die Wahl
fällt zur Laufzeit auf das aktive Plugin; ohne SEO-Plugin gibt `Native` die Felder selbst aus
(`pre_get_document_title`, `wp_head`). Wie jedes Plugin korrekt geschrieben wird – eigene Ability, wo vorhanden,
sonst Speicher plus Index/Cache – steht in `SEO-PLUGINS.md`. Jeder Adapter hat Integrationstests gegen die echte
freie Version (CI-Matrix).

## Entwürfe

RankSphere schickt fertiges **Block-Markup** (Markdown→Blöcke passiert in RankSphere) plus SEO-Felder. Das Plugin
prüft mit `parse_blocks()` und `wp_kses_post()`, legt den Beitrag als `draft` an (oder aktualisiert einen
bestehenden Entwurf derselben RankSphere-ID) und schreibt die SEO-Felder über den Adapter. Veröffentlichen mit
Platzhaltern verhindert ein Filter auf `wp_insert_post_data` (zurück auf Entwurf + Hinweis) und im Block-Editor
ein Hinweis vor dem Veröffentlichen.

## Übersicht im WordPress-Admin

Menü „RankSphere“ → **Übersicht** (Recht `edit_posts`, für alle, die schreiben) und **Einstellungen** (`manage_options`:
verbinden, trennen, Update-Kanal). Auf dem Server gerendert (PHP, WordPress-Admin-Stile + `assets/admin.css`) – kein
Build-Schritt, weniger Code beim Kunden:

- **Überblick:** Urteil, Klicks/Impressionen mit Veränderung, Position, Nennung in KI-Antworten, Datenprobleme.
- **Nächste Schritte:** die fünf wichtigsten offenen Aufgaben, der Rest als Link nach RankSphere.
- **Texte:** die letzten Texte mit Stand; „Entwurf bearbeiten“, wenn der WordPress-Entwurf existiert.
- **So schreiben wir:** Anrede, Tonalität, So / So nicht, bevorzugte Begriffe, Tabu-Wörter.
- **Box im Editor** (klassische Meta-Box, seitlich): erscheint im Block- **und** im klassischen Editor, auch für eigene
  Beitragstypen und Seiten, die mit Page-Buildern gebaut sind. Klicks, Impressionen, Position, Suchanfragen,
  Website-Check-Befunde, tote Backlinks; bei Entwürfen aus RankSphere der Link zum Text. Lädt nach dem Editor
  (`assets/page-box.js` → `ranksphere/v1/page-insights`), damit das Öffnen eines Beitrags nie auf RankSphere wartet.
- **Dashboard-Widget:** Urteil, vier Kennzahlen, nächster Schritt; über „Ansicht anpassen“ ausblendbar (Guideline 11).

Die Daten holt **der Server** von RankSphere (Site-Token, signiert, `Insights\Insights`) und hält sie 10 Minuten im
Transient, je Sprache und Projekt; der Browser spricht nur mit WordPress. Alles aus RankSphere wird typgeprüft gelesen
(`Insights\Value`) und escaped ausgegeben, Links nur mit https.

**Vorschlag erstellen** (ab 1.0.0-alpha.5): in der Box SEO-Titel und Meta-Beschreibung aus den echten Suchanfragen der
Seite, ihrem Text und der Tonalität – geschrieben und geprüft in RankSphere (Job, Länge, Tabu-Wörter), Vorher → Nachher,
„Übernehmen“ je Feld über das SEO-Plugin mit Verlauf; RankSphere bekommt die Änderung fürs Protokoll.

**Übersetzung:** Quelltexte Englisch; Deutsch liegt als `languages/ranksphere-de_DE.l10n.php` bei (WordPress' PHP-Format
ab 6.5), bis translate.wordpress.org übernimmt. `tests/Unit/TranslationsTest.php` prüft, dass jeder Text übersetzt ist.

## KI-Crawler

Auf Frontend-Anfragen (`template_redirect`) wird der User-Agent mit einer gepflegten Liste verglichen (GPTBot,
OAI-SearchBot, ChatGPT-User, ClaudeBot, Claude-User, Claude-SearchBot, PerplexityBot, Perplexity-User,
Bytespider, CCBot, Amazonbot, meta-externalagent, MistralAI-User …; Google-Extended ist nur ein robots.txt-Token, kein
eigener Crawler).
Gezählt wird je Tag, Crawler und Pfad in einer eigenen Tabelle – **keine IP, keine Cookies, nichts
Personenbezogenes**. WP-Cron schickt die Summen täglich an RankSphere und löscht alte Zeilen. Grenzen (in der
Oberfläche erklärt): Seiten aus einem Seiten-Cache/CDN erreichen PHP nicht; User-Agents lassen sich fälschen
(Prüfung über veröffentlichte IP-Bereiche als spätere Ausbaustufe).

## Abilities

Ab WordPress 6.9 meldet das Plugin eigene Abilities an (`ranksphere/get-seo-fields`, `ranksphere/update-seo-fields`,
`ranksphere/create-draft`, `ranksphere/get-overview`) – mit denselben Rechten wie die REST-Routen. Über den MCP
Adapter können KI-Agenten (Claude, Cursor …) sie dann direkt auf der Website nutzen. Darunter läuft derselbe Code
wie bei REST (eine Domänenschicht, zwei Zugänge).

## Updates

Bis zum Eintrag bei WordPress.org (und für Test-Kanäle danach) aktualisiert sich das Plugin über WordPress' eigenen
Weg für fremd gehostete Plugins: Header `Update URI` + Filter `update_plugins_ranksphere.cloud`. Das Update erscheint
unter „Plugins“ wie jedes andere, Auto-Updates eingeschlossen. RankSphere liest die GitHub-Releases dieses Repos und
liefert je Kanal (Alpha, Beta, RC, Stable) Update-Info und Zip aus. Der WordPress.org-Build enthält den Updater nicht.

## Qualität

- PHP 8.1+, WordPress 6.6+, `strict_types`, PSR-4, PHPCS (WordPress Coding Standards + PHPCompatibilityWP),
  PHPStan Level max mit WordPress-Stubs, PHPUnit (Unit ohne WordPress, Integration mit `wp-env`).
- CI: statische Prüfung, Unit-Tests PHP 8.1–8.4, Integration WordPress 6.6 + aktuell × jedes SEO-Plugin,
  Plugin Check wie bei WordPress.org, Release-Zip als Artefakt.
- Offizielle WordPress-Agent-Skills in `.claude/skills` sind Arbeitsgrundlage.
- Release: Tag → Zip → WordPress.org-SVN (Deploy-Action, sobald der Slug freigegeben ist); Playground-Blueprint
  für die Live-Vorschau im Verzeichnis.

## Meilensteine

| | Inhalt | Gegenstück in RankSphere |
|---|---|---|
| M0 ✅ | Grundgerüst, CI, Plan | – |
| M1 ✅ | Verbinden/Trennen, Status, Widerruf erkennen | WordPress-Verbindung je Projekt, Freigabe-Ablauf |
| M2 ✅ | SEO-Adapter lesen/schreiben, Änderungsprotokoll + Rückgängig | „Titel/Beschreibung übernehmen“ in Aufgaben und Seiten |
| M3 ✅ | Entwürfe + Platzhalter-Sperre | „Als Entwurf nach WordPress“ auf der Seite „Texte“, Markdown→Blöcke |
| M4 ✅ | Übersicht im Admin, Box im Editor, Dashboard-Widget, Deutsch | API für Übersicht/Seiten-Daten (Site-Token) |
| M5 | KI-Crawler-Zählung | Empfang + Auswertung in der KI-Sichtbarkeit |
| M6 | Abilities, Übersetzung de_DE, Screenshots, Einreichung bei WordPress.org | – |

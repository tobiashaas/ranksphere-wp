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
  Admin/                  Seite „RankSphere“ (Übersicht), Dashboard-Widget, Editor-Panel
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

Eigene Seite „RankSphere“ (React mit `@wordpress/components`, gebaut mit `@wordpress/scripts`):

- **Überblick:** Klicks/Impressionen, KI-Sichtbarkeit, Datenstand – wie die Startseite in RankSphere, kompakt.
- **Aufgaben:** die wichtigsten offenen Aufgaben mit „In RankSphere öffnen“; SEO-Aufgaben direkt „Übernehmen“.
- **Texte:** Entwürfe aus RankSphere mit Status, Prüfpunkten, Link zum WordPress-Entwurf.
- **Tonalität:** Anrede, So schreiben wir / So nicht, Tabu-Wörter – für alle, die in WordPress schreiben.
- **Editor-Panel** (Block-Editor-Seitenleiste): RankSphere-Daten zur aktuellen Seite (Suchanfragen, Position,
  Vorschlag für Titel/Beschreibung mit Vorher → Nachher, „Übernehmen“).
- **Dashboard-Widget:** drei Kennzahlen + nächste Aufgabe, schließbar (Guideline 11).

Die Daten holt **der Server** von RankSphere (Site-Token, signiert) und hält sie 10 Minuten im Transient; der
Browser spricht nur mit der eigenen WordPress-REST-API (`ranksphere/v1/overview`, Recht `edit_posts`).

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
| M2 | SEO-Adapter lesen/schreiben, Änderungsprotokoll + Rückgängig | „Titel/Beschreibung übernehmen“ in Aufgaben und Seiten |
| M3 | Entwürfe + Platzhalter-Sperre | „Als Entwurf nach WordPress“ auf der Seite „Texte“, Markdown→Blöcke |
| M4 | Übersicht im Admin, Editor-Panel, Dashboard-Widget | API für Übersicht/Seiten-Daten (Site-Token) |
| M5 | KI-Crawler-Zählung | Empfang + Auswertung in der KI-Sichtbarkeit |
| M6 | Abilities, Übersetzung de_DE, Screenshots, Einreichung bei WordPress.org | – |

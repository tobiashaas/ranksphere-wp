# SEO-Plugins: wo die Felder liegen und wie wir sie schreiben

Stand: Oktober 2026, aus dem Quellcode der freien Versionen (Yoast SEO 28.6, Rank Math 1.0.279, SEOPress 10.3,
All in One SEO 5.0.2.1, The SEO Framework 5.1.4, Slim SEO 4.11.0) und – nur gelesen, nicht im Repo – der
Pro-Versionen (Yoast Premium 28.6, Rank Math Pro 3.0.122, SEOPress Pro 10.3, AIOSEO Pro 5.0.2, Slim SEO Pro 1.10.1).
Bei neuen Hauptversionen erneut prüfen; die Integrationstests laufen gegen die jeweils aktuelle freie Version.

## Grundsatz

Je Plugin ein Adapter mit derselben Schnittstelle (lesen roh + gerendert, schreiben Titel, Beschreibung,
Fokus-Keyword(s), Canonical, noindex). **Wo das Plugin selbst eine Ability zum Schreiben anbietet, nehmen wir die**
(`wp_get_ability( $name )->execute( $input )`, WordPress 6.9+) – das Plugin hält dann seine Caches und Tabellen
selbst konsistent. Sonst schreiben wir in seinen Speicher und stoßen danach Index/Cache an.

Leere Werte heißen überall „Vorlage des Plugins verwenden“ – leer schreiben = löschen, nie einen gerenderten
Wert (mit aufgelösten Variablen) zurückschreiben.

## Übersicht

| Plugin | Erkennen | Titel | Beschreibung | Fokus-Keyword | Canonical | noindex | Schreiben | Lesen (gerendert) |
|---|---|---|---|---|---|---|---|---|
| Yoast SEO | `WPSEO_VERSION` | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` | `_yoast_wpseo_focuskw`; Premium weitere: `_yoast_wpseo_focuskeywords` (JSON `[{"keyword","score"}]`) | `_yoast_wpseo_canonical` | `_yoast_wpseo_meta-robots-noindex` (`0` Standard, `1` noindex, `2` index) | `WPSEO_Meta::set_value()` und danach Indexable neu bauen (`Indexable_Builder::build_for_id_and_type`), sonst erst bei Request-Ende; Canonical/Robots auch über Ability `yoast-seo/update-post-seo-data` | `YoastSEO()->meta->for_post( $id )` |
| Rank Math | `RANK_MATH_VERSION` | `rank_math_title` | `rank_math_description` | `rank_math_focus_keyword` (kommagetrennt, erstes = Haupt) | `rank_math_canonical_url` | `rank_math_robots` (serialisiertes Array, z. B. `['noindex']`) | `update_post_meta`, danach `\RankMath\Sitemap\Cache_Watcher::invalidate_post()`; **keine** Schreib-Ability (auch nicht in Pro) | `( new \RankMath\Frontend\Paper\Singular() )->get_seo_meta( $id )` |
| SEOPress | `SEOPRESS_VERSION` | `_seopress_titles_title` | `_seopress_titles_desc` | `_seopress_analysis_target_kw` (kommagetrennt) | `_seopress_robots_canonical` | `_seopress_robots_index` = `yes` | **Abilities** `seopress/update-post-title-description`, `seopress/update-post-robots-settings`; Keywords: Pro `seopress/update-target-keywords`, frei `update_post_meta` | `seopress_get_service( 'TitleMeta' )->getValue( $ctx )` |
| All in One SEO | `function_exists( 'aioseo' )` | Tabelle `aioseo_posts.title` | `.description` | `.focus_keyword` + `keyphrases` (JSON) | `.canonical_url` | `.robots_noindex` (wirkt nur mit `robots_default = 0`) | **Ability** `aioseo-posts/seo-data-update` (alles inkl. Keyphrases); fehlt die Zeile → `not_found`, dann `Models\Post::savePost()`. Post-Meta `_aioseo_*` ist nur eine Kopie und wirkungslos | `aioseo()->meta->title->getPostTitle( $id )` (Cache je Request) |
| The SEO Framework | `THE_SEO_FRAMEWORK_VERSION` | `_genesis_title` | `_genesis_description` | – (nur Bezahl-Erweiterung) | `_genesis_canonical_uri` | `_genesis_noindex` (`-1`/`0`/`1`) | `tsf()->data()->plugin()->post()->update_single_meta_item()` – **nicht** `save_meta()` (löscht nicht übergebene Felder) | `tsf()->title()->get_title( [ 'id' => $id ] )` |
| Slim SEO | `SLIM_SEO_VER` | Meta `slim_seo['title']` | `['description']` | – (Pro: `slim_seo_pro['content_analysis']['main_keyword']`, `keywords` mit `;`) | `['canonical']` | `['noindex']` (0/1) | **Ability** `slim-seo/update-post-meta-tags` (Teil-Update) | Ability `slim-seo/get-post-meta-tags` (`raw` + `rendered`) |
| keins | – | eigene Meta `_ranksphere_title` | `_ranksphere_description` | `_ranksphere_focus_keyword` | `_ranksphere_canonical` | `_ranksphere_noindex` | eigene Meta, Ausgabe über `pre_get_document_title`/`wp_head` | eigene Werte |

## Fallen

- **Yoast:** REST-Meta (`register_meta`) nur für den Beitragstyp „post“, nicht für Seiten – deshalb schreiben wir in
  PHP, nicht über `/wp/v2`. Terme liegen in einer Option `wpseo_taxonomy_meta`, nicht in Term-Meta.
- **Rank Math:** Pro speichert Keywords im selben Feld (bis 100 statt 5). Sitemap-Cache sonst erst beim nächsten `save_post` frisch.
- **SEOPress 10.3:** eigener MCP-Server (`seopress/mcp`) und Abilities; REST/MCP-Freigabe ist standardmäßig aus –
  in PHP aufrufbar bleiben sie (in WordPress-Core noch zu bestätigen, Integrationstest).
- **AIOSEO:** Abilities setzen die Berechtigung `aioseo_page_general_settings` voraus – der verbundene Benutzer
  braucht sie. Term-SEO nur in Pro (`aioseo-terms/seo-data-update`).
- **TSF:** Startseiten-Optionen haben Vorrang vor der Seite, die als Startseite eingestellt ist.
- **Slim SEO:** `meta.slim_seo` in der REST-API liefert gerenderte Werte – nie zurückschreiben.
- `execute()` prüft die Rechte des **aktuellen** Benutzers: Schreibzugriffe laufen als der Benutzer, der die
  Verbindung freigegeben hat (Application Password).

## Offen / unbestätigt

Format von Yoast Premiums `score`, Rank Maths `getHead` ohne Headless-Modul, SEOPress-Sitemap-Cache, Kodierung von
`aioseo_options`, Meta-Schlüssel der TSF-Fokus-Erweiterung.

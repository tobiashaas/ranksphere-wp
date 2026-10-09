=== RankSphere ===
Contributors: ranksphere
Tags: seo, content, ai visibility, drafts, search console
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0-alpha.8
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drafts from RankSphere arrive as WordPress drafts; SEO titles and descriptions stay in sync with your SEO plugin.

== Description ==

RankSphere analyses how a website is found in Google and in AI assistants such as ChatGPT, Gemini and Perplexity, and writes texts in the company's own voice. This plugin connects a WordPress site to a RankSphere project:

* Texts written in RankSphere arrive as **drafts** – nothing is ever published automatically.
* SEO title, meta description and focus keyword are written to the SEO plugin you use (Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework, Slim SEO) – or by the plugin itself when you use none.
* The RankSphere overview of the project appears in the WordPress admin, and every published page shows its Google figures and searches on the edit screen.
* Authors start texts right in WordPress, answer RankSphere's questions and save the result as a draft.

= External service =

This plugin is an interface to RankSphere (https://ranksphere.cloud), a service operated by the plugin's author. It sends nothing until an administrator connects the site under "RankSphere" in the admin and approves the connection. Once connected, RankSphere sends drafts and SEO fields to the site. To show the overview, the dashboard widget and the box on the edit screen, the site asks RankSphere for the project's figures – with the language of the logged-in user and, for the box, the address of the page being edited; answers are kept for ten minutes. When someone clicks "Create suggestion" in the box, the site sends RankSphere the page's address, its current SEO title and description and its text; when a suggestion is applied, the site tells RankSphere what changed and the name of the user who applied it. On RankSphere → Texts, the site sends what the user enters for a new text (post type, kind of text, topic, facts), the user's display name and language, answers to RankSphere's questions and notes on a text; for a rewrite also the chosen post's title, address and text, and with every new text the titles and addresses of the site's published pages (so links point only to pages that exist). It fetches the texts and ideas and, when someone saves one as a draft, tells RankSphere the draft's ID and edit address. When the connection ends in WordPress (you disconnect, the application password is revoked or the approving user is deleted), the site tells RankSphere so it stops using it; that message contains only the reason.

Builds downloaded from ranksphere.cloud (not from WordPress.org) check ranksphere.cloud for updates, at most every six hours. The request contains the installed plugin version and the chosen release channel – not the site's address.

* Terms of use: https://ranksphere.cloud/nutzungsbedingungen
* Privacy policy: https://ranksphere.cloud/datenschutz

= Credits =

Icons from Lucide (https://lucide.dev), ISC licence.

== Installation ==

1. Install and activate the plugin.
2. Open "RankSphere" in the admin menu and connect the site to your RankSphere project.

== Frequently Asked Questions ==

= How does RankSphere get access? =

Through an application password, a WordPress feature since 5.6. You approve it on WordPress' own screen and can revoke it at any time under Users → Profile; the plugin then ends the connection immediately. In addition, every request from RankSphere is signed with a secret only RankSphere and your site know, and each signature is accepted once.

= Does the plugin publish anything? =

No. Texts arrive as drafts. Publishing is always done by a person in WordPress.

= Which SEO plugins are supported? =

Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework and Slim SEO. Without an SEO plugin, RankSphere outputs title and description itself.

== Changelog ==

= 1.0.0-alpha.8 =
* Safer rewrites: a rewrite draft always stays a draft (published it would be a second page), it can only be taken over once its placeholders are filled in, and a page that is already live is never taken offline by the placeholder lock.
* Nothing is overwritten without a way back: a draft whose post type keeps no revisions gets the rewrite as a draft next to it, and taking over warns when WordPress cannot keep the text before.
* Taking over works for private and scheduled posts too, waits for the SEO plugin's fields to be saved and says so when recording fails.
* Drafts someone else saved are no longer overwritten by people who may not edit them; drafts of post types hidden from search are found again.
* Answers to questions with brackets or quotes arrive intact; authors see their own posts in the list; titles with "&" stay as they are; the page no longer reloads while you type.
* RankSphere's messages (busy, limits) come in your language; each request signature counts once, also under load.

= 1.0.0-alpha.7 =
* Texts: choose the post type and write something new or rewrite an existing post – picked from a searchable list, no address to type. RankSphere builds on the post's text and links only to pages that exist on your site.
* A published page is never changed: the rewrite becomes a draft next to it. "Take over into the original" in its editor fills the text in – it goes live only when you click "Update"; the version before stays as WordPress revision.
* History in the RankSphere box: texts, rewrites and SEO changes, with a link to compare or restore the version before.
* "What to write about": ideas from RankSphere's open tasks fill the form with one click; "Rewrite with RankSphere" in the editor box.
* "Refine" a finished text with a note (e.g. "shorter"), and every version of a text can be viewed and restored.

= 1.0.0-alpha.6 =
* New: RankSphere → Texts. Start a text in WordPress (kind of text, topic, facts), follow it, answer RankSphere's open questions and save it as a draft with yourself as author – without opening RankSphere. Nothing is published.
* RankSphere's look in WordPress: overview, dashboard widget and the box on the edit screen use the same tiles, colours and notes as the RankSphere dashboard, with the change against the period before on every figure.

= 1.0.0-alpha.5 =
* New: "Create suggestion" in the RankSphere box – an SEO title and meta description from the searches the page is found with, its text and your company's voice, shown next to the current values. "Apply" writes one field through your SEO plugin, with history; RankSphere logs it and can undo it.
* Texts from RankSphere can become any post type you may create, custom ones included (e.g. "Services") – RankSphere asks on the first send.

= 1.0.0-alpha.4 =
* New: RankSphere → Overview for everyone who writes – figures from Google and AI answers, the most important next steps, the texts from RankSphere (with a link to their drafts) and how your company writes.
* New: a RankSphere box on the edit screen of every published page – clicks, impressions, position, the searches it is found with, findings of the website check and dead backlinks. Works in the block editor, the classic editor and with page builders.
* New: dashboard widget with the figures and the next step.
* Connection and update channel moved to RankSphere → Settings.
* German translation included.

= 1.0.0-alpha.3 =
* Fixed: switching to a build without the updater (the WordPress.org variant) ended the update with a critical error.
* "Check again" under Dashboard → Updates now asks RankSphere right away instead of using the answer cached for six hours.
* Releases only offer the build that updates itself from RankSphere.

= 1.0.0-alpha.2 =
* SEO title, meta description, focus keywords, canonical and noindex from RankSphere – written into Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework or Slim SEO the way each of them stores it; without an SEO plugin RankSphere outputs them itself.
* Every change is recorded per post and can be undone from RankSphere, as long as nobody changed the field again since.
* Texts from RankSphere arrive as drafts (never published) with their SEO fields; a draft that is already published is never touched again.
* Drafts with open placeholders like [[…]] for missing facts cannot be published until they are filled in.

= 1.0.0-alpha.1 =
* First test version.
* Updates from RankSphere with release channels (alpha, beta, release candidate, stable) – builds downloaded from ranksphere.cloud only.
* Connect and disconnect: approval through application passwords, signed requests from RankSphere, status route, connection ends when the application password is revoked or the user deleted.

= 0.1.0 =
* Plugin skeleton: connection status page, lifecycle, upgrade routine.

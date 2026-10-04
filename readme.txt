=== RankSphere ===
Contributors: ranksphere
Tags: seo, content, ai visibility, drafts, search console
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0-alpha.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drafts from RankSphere arrive as WordPress drafts; SEO titles and descriptions stay in sync with your SEO plugin.

== Description ==

RankSphere analyses how a website is found in Google and in AI assistants such as ChatGPT, Gemini and Perplexity, and writes texts in the company's own voice. This plugin connects a WordPress site to a RankSphere project:

* Texts written in RankSphere arrive as **drafts** – nothing is ever published automatically.
* SEO title, meta description and focus keyword are written to the SEO plugin you use (Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework, Slim SEO) – or by the plugin itself when you use none.
* The RankSphere overview of the project appears in the WordPress admin.

= External service =

This plugin is an interface to RankSphere (https://ranksphere.cloud), a service operated by the plugin's author. It sends nothing until an administrator connects the site under "RankSphere" in the admin and approves the connection. Once connected, RankSphere sends drafts and SEO fields to the site, and the site sends the data needed for the overview back to RankSphere. When the connection ends in WordPress (you disconnect, the application password is revoked or the approving user is deleted), the site tells RankSphere so it stops using it; that message contains only the reason.

Builds downloaded from ranksphere.cloud (not from WordPress.org) check ranksphere.cloud for updates, at most every six hours. The request contains the installed plugin version and the chosen release channel – not the site's address.

* Terms of use: https://ranksphere.cloud/nutzungsbedingungen
* Privacy policy: https://ranksphere.cloud/datenschutz

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

= 1.0.0-alpha.1 =
* First test version.
* Updates from RankSphere with release channels (alpha, beta, release candidate, stable) – builds downloaded from ranksphere.cloud only.
* Connect and disconnect: approval through application passwords, signed requests from RankSphere, status route, connection ends when the application password is revoked or the user deleted.

= 0.1.0 =
* Plugin skeleton: connection status page, lifecycle, upgrade routine.

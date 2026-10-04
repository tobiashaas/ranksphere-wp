=== RankSphere ===
Contributors: ranksphere
Tags: seo, content, ai visibility, drafts, search console
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects your site to RankSphere: drafts written in RankSphere arrive as WordPress drafts, and SEO titles and descriptions stay in sync with your SEO plugin.

== Description ==

RankSphere analyses how a website is found in Google and in AI assistants such as ChatGPT, Gemini and Perplexity, and writes texts in the company's own voice. This plugin connects a WordPress site to a RankSphere project:

* Texts written in RankSphere arrive as **drafts** – nothing is ever published automatically.
* SEO title, meta description and focus keyword are written to the SEO plugin you use (Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework, Slim SEO) – or by the plugin itself when you use none.
* The RankSphere overview of the project appears in the WordPress admin.

= External service =

This plugin is an interface to RankSphere (https://ranksphere.cloud), a service operated by the plugin's author. It sends nothing until an administrator connects the site under "RankSphere" in the admin and approves the connection. Once connected, RankSphere sends drafts and SEO fields to the site, and the site sends the data needed for the overview back to RankSphere.

* Terms of use: https://ranksphere.cloud/nutzungsbedingungen
* Privacy policy: https://ranksphere.cloud/datenschutz

== Installation ==

1. Install and activate the plugin.
2. Open "RankSphere" in the admin menu and connect the site to your RankSphere project.

== Frequently Asked Questions ==

= Does the plugin publish anything? =

No. Texts arrive as drafts. Publishing is always done by a person in WordPress.

= Which SEO plugins are supported? =

Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework and Slim SEO. Without an SEO plugin, RankSphere outputs title and description itself.

== Changelog ==

= 0.1.0 =
* Plugin skeleton: connection status page, lifecycle, upgrade routine.

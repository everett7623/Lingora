=== Lingora ===
Contributors: everettlabs
Tags: translation, multilingual, ai, language, i18n
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-native multilingual engine for WordPress. Translate the live site without duplicating posts.

== Description ==

Lingora turns a single WordPress site into a multilingual one.

It follows the TranslatePress model: one set of posts, language prefixes in the URL (`https://example.com/de/`), and a visual editor on the live page. The AI connection layer is aligned with WAMofa — OpenAI, Claude, DeepSeek, Gemini, Grok (xAI), Dots AI, Sub2API, and other OpenAI-compatible gateways.

= What it does =

* Directory URLs like `/de/` and `/fr/about/` (default language stays unprefixed)
* Translates rendered HTML text and common attributes
* Stores strings and translations in custom tables
* Auto-translates missing copy with your AI key
* Header language switcher with flags and ISO codes (EN / DE)
* Floating switcher, shortcode `[lingora_switcher]`, widget, and menu injection
* hreflang tags, canonical URLs, and multilingual sitemaps
* Visual editor from the admin bar
* Browser language detection and a language cookie
* Optional slug translation, gettext, translated search, WooCommerce URLs

= Shortcode =

`[lingora_switcher]`

== Installation ==

1. Copy the `Lingora` plugin folder into `wp-content/plugins/` (WordPress.org slug would be `lingora`)
2. Activate Lingora
3. Open Lingora → AI Connection and add a provider key
4. Add target languages
5. Visit the site and use "Translate this page" in the admin bar

== Changelog ==

= 0.0.1 =
* Initial private build: directory URLs (`/de/`), AI providers, visual editor, flag switcher, gettext, slugs, sitemap, search, WooCommerce URLs

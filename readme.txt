=== Lingora ===
Contributors: everettlabs
Tags: translation, multilingual, ai, language, i18n
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-assisted multilingual engine for WordPress. Translate public site content without duplicating posts.

== Description ==

Lingora keeps one set of WordPress posts and pages, adds directory or query language URLs, and stores translations in its own dictionary tables. It supports rendered HTML text, selected attributes, gettext strings, public slugs, language switchers, hreflang, translated search, optional WooCommerce links, a visual editor, a resumable scanner, and a bounded retry queue.

This release supports single-site WordPress installations. Network activation is rejected until Multisite data isolation is implemented.

= What it does =

* Directory URLs such as `/de/` and `/fr/about/`, or query URLs such as `?lang=de`
* Rendered HTML text and selected public presentation attributes
* Source strings and translations in custom database tables
* Optional automatic, on-the-fly, manual, and background translation
* Public post/page slug translation with normalized hierarchical URLs
* Header and floating language switchers, shortcode `[lingora_switcher]`, widget, and menu injection
* hreflang, canonical URLs, multilingual sitemaps, and translated search
* Administrator visual editor with exact stored string IDs
* Resumable public-content scanning and queue status in the dashboard

Draft, private, password-protected, authenticated, admin, REST, AJAX, preview, feed, search, account, cart, and checkout output is excluded from shared collection. Dynamic blocks and shortcodes are not executed by the scanner.

== External services ==

Lingora contacts an AI provider only after an administrator explicitly checks the consent box on the AI Connection screen. When enabled, the selected provider receives public page text, selected attributes, optional public post slugs, the target language, the requested model, and translation instructions. Requests can happen when an administrator tests the connection, reads model names, requests a manual translation, fills missing strings, or enables automatic/background translation. Lingora does not intentionally send drafts, private/password-protected content, administrator pages, authenticated output, or account/cart/checkout pages.

AI providers may charge separately and may process or retain submitted content under their own terms. Review the provider's current terms and privacy policy before enabling it. Dots AI, Sub2API, and custom gateways are administrator-selected services without project-verified legal URLs in this release; confirm their policies before use.

* OpenAI: [terms](https://openai.com/policies/terms-of-use/) and [privacy](https://openai.com/policies/privacy-policy/)
* Anthropic: [terms](https://www.anthropic.com/legal/commercial-terms) and [privacy](https://www.anthropic.com/legal/privacy)
* DeepSeek: [terms](https://cdn.deepseek.com/policies/zh-CN/deepseek-terms-of-use.html) and [privacy](https://cdn.deepseek.com/policies/zh-CN/deepseek-privacy-policy.html)
* Google Gemini API: [terms](https://ai.google.dev/gemini-api/terms) and [privacy](https://policies.google.com/privacy)
* xAI: [terms](https://x.ai/legal/terms-of-service-enterprise) and [privacy](https://x.ai/legal/privacy-policy)

Translation data, source strings, queue state, and provider settings are stored in WordPress. API keys are masked in REST responses. The optional `lingora_lang` cookie stores the selected language for 30 days. Uninstalling removes Lingora tables, options, credentials, and scheduled jobs; deactivation keeps them.

== Installation ==

1. Copy the `Lingora` folder into `wp-content/plugins/`.
2. Activate Lingora.
3. Open Lingora → AI 接口, select a provider, review the service links, and explicitly check the consent box before testing or sending content.
4. Add at least one enabled non-default language.
5. Scan public content or visit a target-language URL, then use the visual editor from the admin bar.

== Frequently asked questions ==

= Does Lingora create translated posts? =
No. It keeps one set of public posts and stores translations in dictionary tables.

= Does adding an API key send my content? =
No. AI network requests require explicit consent. When enabled, selected public content is sent to the configured provider.

= Why is a page not translated? =
Check that the language is enabled, the page is public, the URL is not excluded, consent and credentials are valid, and the queue has not failed. The dashboard reports pending, delayed, claimed, and failed work.

= Does Lingora support Multisite? =
No. This release is for single-site installations.

== Changelog ==

= 0.0.8 =
* Fix language prefix stripping on root (non-subdirectory) installs; language pages no longer 404.
* Restore translated URLs for all public custom post types, not only posts, pages, and products.
* Skip withdrawn or private sources when reverse-mapping translated slugs and make collisions deterministic.
* Show provider error messages without double-encoded entities.

= 0.0.7 =
* Protect human translations from automatic queue writes and add token-guarded retries with visible failures.
* Add resumable public-content scanning, privacy boundaries, explicit AI consent, and async job status.
* Harden URL routing, language validation, slug normalization, REST input validation, and provider endpoint safety.
* Add isolated WordPress regression tooling, external-service documentation, and reproducible release manifests.

= 0.0.6 =
* Language URL examples follow the site's default and first target language.
* Chinese admin navigation and strings status filters.

= 0.0.1 =
* Initial multilingual URL, provider, editor, switcher, gettext, slug, sitemap, search, and WooCommerce implementation.

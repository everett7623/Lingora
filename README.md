# Lingora

Lingora is an AI-assisted multilingual plugin for WordPress. It keeps one set of posts and pages, adds language-aware URLs, and stores translations in its own dictionary tables.

- WordPress 6.4+
- PHP 8.1+
- Single-site installations
- GPL-2.0-or-later

## Installation

1. Copy this folder to `wp-content/plugins/lingora`.
2. Activate **Lingora** from **Plugins**.
3. Open **Lingora → AI 接口**, select a provider, review its service links, and explicitly check the consent box before testing or sending content.
4. Add at least one enabled non-default language.
5. Scan public content or visit a target-language URL, then use the visual editor from the admin bar.

Use a staging site and backups before enabling automatic translation on production.

## What it does

Lingora translates one public WordPress site without duplicating posts. It supports directory or query language URLs, rendered HTML text, selected attributes, gettext strings, public post/page slugs, language switchers, hreflang, translated search, optional WooCommerce links, a visual editor, a resumable public-content scanner, and a bounded retry queue.

Draft, private, password-protected, authenticated, admin, REST, AJAX, preview, feed, search, account, cart, and checkout output is excluded from shared content collection. Dynamic blocks and shortcodes are not executed by the scanner.

## AI services and privacy

AI requests are disabled until an administrator explicitly checks the consent box. With consent, the selected provider can receive public text, selected attributes, public slugs, target language, model, and translation instructions. Requests occur during explicit tests, model discovery, manual translation, missing-string filling, and enabled background/on-the-fly translation. The provider may charge separately and may process submitted content under its own terms.

The plugin stores source strings, translations, queue state, and provider settings in the WordPress database. API keys are stored in options and masked in REST responses. The optional `lingora_lang` cookie stores a language code for 30 days. Uninstalling removes Lingora tables, options, and scheduled jobs; deactivation keeps them.

Built-in provider legal links are shown when verified. Dots AI, Sub2API, and custom gateways are site-admin-selected services; verify their current terms and privacy policy before use.

- [OpenAI Terms](https://openai.com/policies/terms-of-use/) · [Privacy](https://openai.com/policies/privacy-policy/)
- [Anthropic Commercial Terms](https://www.anthropic.com/legal/commercial-terms) · [Privacy](https://www.anthropic.com/legal/privacy)
- [DeepSeek Terms](https://cdn.deepseek.com/policies/zh-CN/deepseek-terms-of-use.html) · [Privacy](https://cdn.deepseek.com/policies/zh-CN/deepseek-privacy-policy.html)
- [Google Gemini API Terms](https://ai.google.dev/gemini-api/terms) · [Privacy](https://policies.google.com/privacy)
- [xAI Terms](https://x.ai/legal/terms-of-service-enterprise) · [Privacy](https://x.ai/legal/privacy-policy)

## Development and release checks

There is no production build step. The repository includes isolated WordPress Playground tooling and regression suites outside the runtime ZIP:

```sh
npm run sandbox:setup
node tools/playground.mjs --wp=6.4 --php=8.1 --php-file=tests/frontend-regression.php --php-file=tests/url-regression.php --php-file=tests/api-regression.php --php-file=tests/queue-regression.php
node tools/playground.mjs --wp=6.4 --php=8.1 --plugin-check
python tools/release.py --check
```

The sandbox uses PHP-WASM, SQLite, an in-memory filesystem, deterministic HTTP fixtures, and no real credentials. Native `php -l` and `node --check` are syntax checks only. See [`docs/development.md`](docs/development.md) and [`docs/release-checklist.md`](docs/release-checklist.md).

Passing a local check does not guarantee WordPress.org approval. Keep `Tested up to` tied to versions actually verified in the release record.

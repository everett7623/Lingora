# CLAUDE.md

## Project

Lingora is an AI-assisted WordPress multilingual plugin for WordPress 6.4+ and PHP 8.1+. It is a single-site plugin: network activation is rejected until a separate Multisite data-isolation design exists.

Production has no asset build and no runtime dependency manager. PHP, CSS, and JavaScript are loaded from the plugin source. Development-only Node tooling, tests, and documentation are excluded from the release ZIP.

## Source layout

- `lingora.php` is the plugin entry point.
- `includes/class-plugin.php` manually requires and constructs every service.
- `includes/class-text.php` provides UTF-8 length/substr fallbacks when `mbstring` is unavailable.
- `includes/class-content.php` is the shared public-content boundary.
- `includes/class-db.php` owns the three custom tables and idempotent schema migrations.
- `includes/class-translator.php` owns dictionary lookup, on-the-fly limits, queue claims, retries, and AI consent checks.
- `includes/class-scanner.php` owns the resumable public-post scanner.
- `includes/class-rewrite.php` owns language detection and URL conversion.
- `admin/` contains the five-page admin shell; `public/` contains the editor, switcher, and frontend styles.

The plugin does not duplicate posts. `{prefix}lingora_strings` stores original text keyed by `hash + context`; `{prefix}lingora_translations` stores per-language `auto` or `human` rows; `{prefix}lingora_queue` stores token-claimed background work. Do not change the hash algorithm without a migration.

## Security and privacy invariants

- AI network requests require both explicit `lingora_settings.ai_consent === true` and valid credentials.
- HTTP uses WordPress safe HTTP functions, HTTPS endpoints, no credentials/query/fragment in the configured authority, zero redirects, bounded response size, and redacted errors.
- Human translations cannot be overwritten by automatic writes, including an in-flight AI response. Queue claims are token and lease fenced.
- Shared collection accepts public published unprotected content only. It excludes admin/REST/AJAX/cron/preview/search/feed/commerce/authenticated requests and dynamic shortcode output.
- When a source becomes private or is deleted, pending work is removed while existing dictionary rows stay available for controlled data retention; source visibility blocks further lookup, AI, and public output.
- API keys never appear in frontend data and are masked in bootstrap responses.

## Lifecycle

`plugins_loaded` calls `Lingora_Plugin::boot()`. Rewrite detection is registered immediately so URL prefixes are removed before WordPress parses the request. Remaining services register at `init` priority 0 after options/database migration and textdomain loading. The boot and initialize methods are idempotent.

Queue statuses are `pending`, `delayed`, `claimed`, and terminal `failed`; manual work is counted separately. `GET /lingora/v1/jobs` returns `queue`, `scan`, and `stats`. `/scan`, `/queue`, and `/missing` return HTTP 202 and do not claim that translation has completed.

Scanner state is persisted in `lingora_scan_state`, bounded to 20 posts per cron step, guarded by a stale lease, and idempotent on repeated triggers. Slug link generation never calls AI synchronously; it reads stored normalized slugs and queues public missing slugs.

## Verification

Native checks:

```sh
php -l includes/class-xxx.php
node --check admin/js/admin.js
node --check public/js/editor.js
node --check public/js/switcher.js
python tools/release.py --check
```

Isolated runtime checks use official `@wp-playground/cli` 3.1.55 in OS temp storage, WASM PHP, SQLite, copied plugin files, and deterministic offline HTTP fixtures:

```sh
npm run sandbox:setup
node tools/playground.mjs --wp=6.4 --php=8.1 --php-file=tests/frontend-regression.php --php-file=tests/url-regression.php --php-file=tests/api-regression.php --php-file=tests/queue-regression.php
node tools/playground.mjs --wp=6.4 --php=8.1 --plugin-check
python tools/release.py --output dist/lingora-0.0.8.zip
```

The runner has no host filesystem mount, no real API keys, and no real AI calls. Plugin Check runs in the sandbox and produces a report; its static findings still require maintainer review and do not guarantee WordPress.org approval. See `docs/development.md`, `docs/release-checklist.md`, and `docs/external-services.md`.

## Coding rules

Use WordPress naming, ABSPATH guards, late escaping, prepared values, static table helpers, nonce/capability checks, Chinese source strings in the `lingora` text domain, and `wp.i18n` for JavaScript. Never add remote scripts or fonts. Do not add network activation, telemetry, promotional links, or arbitrary remote code.

# Development guide

## Architecture

Lingora is a single-content WordPress plugin. It does not duplicate posts for target languages. The `Lingora_Plugin` singleton constructs services, then registers them through WordPress hooks. Source strings and translations are joined by the existing `Lingora_Db::hash()` result and a context such as `html` or `slug`.

Do not change the hash algorithm without a migration. Existing dictionaries, HTML editor markers, and translation lookups depend on it.

Production PHP, JavaScript, and CSS are source files loaded directly by WordPress. There is no production asset build. Development tooling must stay outside the distributable ZIP.

## Public-content boundary

`Lingora_Content` is the shared boundary for page collection:

- Only public, published, unprotected posts are eligible.
- Admin, REST, AJAX, cron, previews, feeds, robots, search, embeds, and non-read requests are excluded.
- WooCommerce account, cart, and checkout pages are excluded.
- Authenticated output is never inserted into the shared dictionary; the editor reads already discovered public strings.

Do not bypass this boundary in a scanner, renderer, gettext callback, or new AI feature. Source text is user data even when a page is public. Sending it to an external service requires the configured consent and credential.

## Storage

| Table | Purpose | Important uniqueness |
| --- | --- | --- |
| `{prefix}lingora_strings` | Original text, context, source | `(hash, context)` |
| `{prefix}lingora_translations` | Per-language automatic/human text | `(string_id, language)` |
| `{prefix}lingora_queue` | Pending and retryable background work | `(string_id, language)` |

Schema changes belong in `Lingora_Db::install()` with an incremented `DB_VERSION`. Migration must be idempotent and preserve existing translations. Automatic writes must never overwrite an existing human row, including when a human edit arrives while a provider request is in flight.

## REST API

The namespace is `lingora/v1`. Routes require `manage_options`; cookie-authenticated browser calls send the WordPress REST nonce in `X-WP-Nonce` and use same-origin credentials. Input errors use an appropriate HTTP error status and a `message` string. Never leak credentials in errors or bootstrap data.

- `GET /bootstrap`: masked settings, languages, provider metadata, and statistics.
- `POST /settings`: validates the complete patch before persistence. `ai_consent` is a top-level boolean.
- `POST /languages`: validates unique language codes and one enabled default.
- `GET /strings`: filtered, paginated dictionary rows.
- `GET /strings/{id}?language={code}`: exact stored row and target-language translation.
- `POST /strings/{id}`: saves a human translation for the explicit target language.
- `POST /translate`: uses the stored original/context when `string_id` is supplied. `{ok, text, saved}` distinguishes a saved automatic translation from a protected human-row preview.
- `POST /scan`, `POST /queue`, `POST /missing`: enqueue bounded background work and return HTTP 202.
- `GET /jobs`: returns `{queue, scan, stats}` for polling. Queuing is not the same as completion.

The front end must bind responses to the selected row and language. A response for an older selection must not replace the current editor state.

## External requests

All requests go through WordPress HTTP functions. Use a safe HTTPS endpoint, bounded timeouts and response sizes, no secret-bearing redirects, and strict response validation. Keep the chosen model ID verbatim rather than silently mapping it to an assumed future model. A batch result must be an equally sized list of strings.

Do not use real keys or production text in automated tests. `tests/integration/offline-http.php` supplies deterministic provider responses and denies other WordPress HTTP traffic in the isolated sandbox.

## Isolated WordPress sandbox

The test runner uses official WordPress Playground PHP-WASM tooling with an in-memory filesystem and SQLite database. It copies an allowlist of plugin files; it does not mount the repository into PHP or connect to a real site. Tool downloads and evidence stay in the operating system's temporary directory.

Requirements: Node.js 24.18 or newer, npm, and network access for official tooling and WordPress archives. PHP code runs inside the WASM sandbox. Native PHP can be used for syntax-only linting.

```sh
npm run sandbox:setup
npm run test:integration
npm run test:matrix
npm run check:plugin
node tools/playground.mjs --wp=6.4 --php=8.1 --php-file=tests/frontend-regression.php
node tools/playground.mjs --wp=6.4 --php=8.1 --without-mbstring
node tools/playground.mjs --serve --port=9400
```

Use `--php-file` repeatedly to run multiple focused suites in the same isolated site. A PHP suite can load `/wordpress/wp-load.php`; copied test files are under `/wordpress/wp-content/plugins/lingora/tests/`.

The server option binds only to `127.0.0.1` and is for temporary local browser checks. The runner prints the temporary login and the path to `summary.json`. Closing it discards the site. Never reuse the test login outside the sandbox.

## Style and verification

Use WordPress naming and escaping conventions, `Lingora_*` classes, ABSPATH guards, prepared SQL values, and Chinese source text wrapped in the `lingora` text domain. JavaScript uses `wp.i18n` and the `wp-i18n` script dependency.

Each fix needs a focused regression that exercises the externally observable behavior, not a test that duplicates its implementation. Useful cases include human/automatic write races, queue claims and retries, public/private content transitions, explicit consent, invalid REST types, subdirectory URL routing, preserved search parameters, non-Latin slugs, and editor response races.

Record exact WordPress/PHP versions and test outcomes. A passing static Plugin Check is useful evidence, but it does not replace browser/runtime checks or guarantee directory acceptance.

# Verification record — 0.0.8

Date: 2026-09-24

## Passed

- PHP 8.4 native syntax lint: 39 production PHP files, 0 failures.
- Node syntax checks: admin/editor/switcher scripts, sandbox runner, and both edited suites, 0 failures.
- `git diff --check`: passed.
- Candidate ZIP install: root `lingora/`, 50 runtime files, ZIP integrity passed, versions all `0.0.8`.
- Candidate ZIP SHA-256: `a8c5bf46f80bdb50eb1f4357fc0903a0c4e67f27dc80814fe983c95b925e3412`.
- Isolated WordPress Playground 3.1.55, PHP-WASM, SQLite, deterministic HTTP fixtures, no host filesystem mount, no real AI keys. Full matrix on the 0.0.8 source, each combo running activation + smoke + all four suites with zero failed assertions:
  - WordPress 6.4 / PHP 8.1.34: frontend 19, url 38, api 44, queue 40 — all passed;
  - WordPress 6.4 / PHP 8.5.10: identical result, all passed;
  - WordPress 7.1.3 / PHP 8.1.34: identical result, all passed;
  - WordPress 7.1.3 / PHP 8.5.10: identical result, all passed.
  - New 0.0.8 regression coverage included in the suites: root-install prefix stripping (three vectors), custom post type slug routing, slug collision determinism, withdrawn-source reverse-mapping filter.
- Deactivation/uninstall lifecycle suite passed on the candidate ZIP in WordPress 7.1.3 / PHP 8.5: deactivation kept settings and human translations and cleared queue/scanner hooks; uninstall dropped the three tables and all Lingora options/state.
- Plugin Check 2.1.0 against the 0.0.8 code in WASM (WordPress 7.1.2 / PHP 8.5): **0 blocking errors, 200 warnings**. Reported ERROR findings are exclusively `WordPress.Security.EscapeOutput.ExceptionNotEscaped` (59), a reviewed false positive — exception messages are not output; REST JSON-encodes them and the admin/editor UI renders via `textContent`. The release gate blocks only on other error codes. Remaining warnings (direct custom-table queries, static prepared-SQL recognition, unslashed server variables, view-variable naming, `load_plugin_textdomain` guidance, WordPress 7.0+ AI Client guidance) are recorded for maintainer review.
- Official provider page spot checks on 2026-09-24: DeepSeek terms/privacy CDN pages, Google Gemini terms/privacy, and Anthropic commercial terms returned HTTP 200. xAI terms returned HTTP 200; xAI privacy and OpenAI legal pages were documented but blocked automated fetch in this environment.

## 0.0.8 review fixes included

- P1: language prefix stripping on root (non-subdirectory) installs — double-slash pattern never matched, language pages 404ed (`includes/class-rewrite.php`, `from_request()`).
- P1: translated slugs for public custom post types were generated but never routed back (`includes/class-slugs.php`, query-variable list now derived from registered public post types).
- P2: `original_for_translation()` is now deterministic (`ORDER BY s.id ASC`) and skips withdrawn/private sources (`includes/class-db.php`).
- P2: exception messages no longer pre-encoded with `esc_html`, so provider errors containing quotes render readably (display layer uses `textContent`/JSON encoding).

## Candidate artifact

- ZIP: `dist/lingora-0.0.8.zip`
- Stable-name alias: `dist/lingora.zip` (byte-identical; wp.org/GitHub latest-download convention)
- Manifest: `dist/lingora-0.0.8.manifest.json`
- Runtime files: 50, all under `lingora/`.
- SHA-256: `a8c5bf46f80bdb50eb1f4357fc0903a0c4e67f27dc80814fe983c95b925e3412` (both names)

## Remaining submission gates

- Run a native MySQL concurrency test. SQLite-WASM validates behavior and schema compatibility, but is not a substitute for MySQL isolation/lock testing.
- Complete deeper browser interaction for all five admin pages, queue polling, target-language editor save/preview, switcher keyboard controls, and a public target-language page from the final ZIP. Dashboard, AI connection, and job-status pages were smoke-checked with synthetic content only.
- Review the 200 Plugin Check warnings and the accepted `ExceptionNotEscaped` classification before submission.
- Confirm the WordPress.org slug, contributor account, screenshots, icon/banner assets, and final legal/service links before uploading.
- WordPress.org manual review remains required after upload; local verification cannot guarantee approval.

The 0.0.8 candidate is functionally verified across WordPress 6.4 and 7.1.3 with PHP 8.1 and 8.5 in the isolated runtime, including a fresh install from the candidate ZIP.

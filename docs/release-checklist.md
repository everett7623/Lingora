# Release checklist

Candidate: 0.0.8

> 0.0.7 review outcomes fixed in 0.0.8: root-install prefix stripping (P1), custom post type slug routing (P1), reverse-mapping determinism/withdrawn-source filter (P2), exception message double encoding (P2). Accepted static finding: `WordPress.Security.EscapeOutput.ExceptionNotEscaped` on throw-statements is a reviewed false positive (exception messages are not output; REST JSON-encodes them and the UI renders via textContent); the Plugin Check gate blocks only on other error codes.

## Source and metadata

- [x] `lingora.php` header version, `LINGORA_VERSION`, and `readme.txt` stable tag match.
- [x] Requires WordPress 6.4 and PHP 8.1 are declared.
- [x] The complete GPLv2 text is included in `LICENSE`.
- [x] `readme.txt` includes installation, privacy, external services, FAQ, and changelog sections.
- [ ] Confirm the final WordPress.org plugin slug and contributor account before upload.
- [ ] Provide final plugin icon/banner assets if the directory listing requires them.

## Static checks

- [x] PHP syntax check across all 39 production PHP files.
- [x] JavaScript syntax check for admin, editor, switcher, and sandbox runner.
- [x] `git diff --check` passes.
- [x] Release manifest checks the version identity, runtime allowlist, GPL size, ZIP paths, and SHA-256.
- [ ] Review remaining Plugin Check warnings. The isolated Plugin Check 2.1.0 run completed and produced a report, but it still reports WordPress naming/escaping and direct-database-query findings that need maintainer review before submission.

## Isolated runtime checks

Runner: `@wp-playground/cli` 3.1.55, WASM PHP 8.1.34, WordPress 6.4, SQLite in WASM memory, no host filesystem mount, external HTTP denied except deterministic fixtures, no real AI credentials.

- [x] Activation, plugin boot, custom tables, DOM/Unicode, HTTP deny/fixture checks.
- [x] `tests/frontend-regression.php`: 20 privacy, HTML, editor, nonce, DOM and UTF-8 checks.
- [x] `tests/url-regression.php`: 36 URL, query, subdirectory, host, cookie, SEO/search vectors.
- [x] `tests/api-regression.php`: authorization, consent, masking, endpoint safety, REST validation, human protection, language validation, batch response and async schema.
- [x] `tests/queue-regression.php`: human/automatic races, token leases, retries, failures, manual revival, privacy transition, 45+ post cursor scan, dynamic/shortcode exclusion, hierarchy and Unicode slugs.
- [x] WordPress 6.4/PHP 8.1.34, WordPress 6.4/PHP 8.5.10, WordPress 7.1.2/PHP 8.1.34, and WordPress 7.1.2/PHP 8.5.10 matrix runs.
- [x] Candidate ZIP install and no-mbstring fallback run.
- [x] Deactivation/uninstall lifecycle run in WordPress 7.1.2/PHP 8.5 sandbox.
- [ ] Native MySQL concurrency run. SQLite-WASM verifies schema and behavior in the sandbox, but is not a substitute for a real MySQL lock/isolation test.
- [ ] Complete the deeper browser pass for all five admin pages, queue polling, target-language editor save/preview, switcher keyboard interaction, and a public target-language page. Dashboard and AI connection pages were smoke-checked.

## Packaging

```sh
python tools/release.py --check
python tools/release.py --output dist/lingora-0.0.7.zip
```

The packer refuses to overwrite the versioned artifact, includes only runtime directories plus the root plugin files, writes `dist/lingora-0.0.7.manifest.json`, and records a SHA-256 hash. It also writes a byte-identical stable-name alias `dist/lingora.zip` (the wp.org/GitHub latest-download convention); the alias is regenerable and may be overwritten on rebuild. Install the generated ZIP into a clean single-site WordPress sandbox and repeat activation plus the smoke suite before upload.

## Submission gate

Do not submit while any item marked `[ ]` affects security, external-service disclosure, data privacy, activation, or the install ZIP. WordPress.org manual review remains required after Plugin Check.

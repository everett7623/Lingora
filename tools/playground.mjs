import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { createServer } from 'node:http';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, readFile, readdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { parseArgs } from 'node:util';

// Dependencies and downloaded tools stay outside the plugin/repository.
const PLAYGROUND_VERSION = '3.1.55';
const repository = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const cache = resolve(process.env.LINGORA_SANDBOX_CACHE || join(tmpdir(), `lingora-playground-${PLAYGROUND_VERSION}`));
const { values } = parseArgs({ options: {
  setup: { type: 'boolean' },
  matrix: { type: 'boolean' },
  wp: { type: 'string', default: 'latest' },
  php: { type: 'string', default: '8.5' },
  'php-file': { type: 'string', multiple: true },
  'wp-cli': { type: 'string', multiple: true },
  'plugin-check': { type: 'boolean' },
  zip: { type: 'string' },
  'without-mbstring': { type: 'boolean' },
  serve: { type: 'boolean' },
  port: { type: 'string', default: '9400' },
  help: { type: 'boolean' },
} });

if (values.help) {
  console.log(`Lingora isolated WordPress sandbox (Node >=24.18; Playground ${PLAYGROUND_VERSION})
  node tools/playground.mjs --setup            Install pinned tooling in OS temp; npm --ignore-scripts
  node tools/playground.mjs                    WordPress latest stable / PHP 8.5 smoke test
  node tools/playground.mjs --matrix           WordPress latest + 6.4, each on PHP 8.1 + 8.5
  node tools/playground.mjs --wp=6.4 --php=8.1  Pick explicit versions
  node tools/playground.mjs --php-file=tests/example.php  Run PHP inside the installed WordPress sandbox
  node tools/playground.mjs --wp-cli="wp core version"   Run WP-CLI inside WASM (repeatable)
  node tools/playground.mjs --plugin-check     Download official Plugin Check and run its CLI checks
  node tools/playground.mjs --zip=dist/lingora-0.0.7.zip  Install the candidate ZIP rather than source files
  node tools/playground.mjs --without-mbstring Disable mb_* functions to test fallback paths
  node tools/playground.mjs --serve --port=9400 Serve the in-memory site on 127.0.0.1 only

Each run copies allowlisted plugin files into WASM memory (no host mounts).
SQLite, WordPress, test data, fake credentials and filesystem writes stay in WASM.
PHP network/subprocess calls are disabled; WordPress HTTP is intercepted by offline fixtures.
Only the Node setup process downloads official WordPress/tool artifacts.
Results and downloads: ${cache}
Override the temporary cache with LINGORA_SANDBOX_CACHE. No global install or config changes.`);
  process.exit(0);
}

if (values.setup) {
  await mkdir(cache, { recursive: true });
  const npmCli = process.env.npm_execpath || join(dirname(process.execPath), 'node_modules/npm/bin/npm-cli.js');
  const args = ['install', '--prefix', cache, '--cache', join(cache, 'npm-cache'), '--ignore-scripts', '--no-audit', '--no-fund', '--save-exact', `@wp-playground/cli@${PLAYGROUND_VERSION}`];
  const result = existsSync(npmCli)
    ? spawnSync(process.execPath, [npmCli, ...args], { stdio: 'inherit', cwd: cache })
    : spawnSync('npm', args, { stdio: 'inherit', cwd: cache });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status || 1);
  console.log(`Playground installed in ${cache}`);
  process.exit(0);
}

const moduleRoot = join(cache, 'node_modules');
if (!existsSync(join(moduleRoot, '@wp-playground/cli/package.json'))) {
  throw new Error('Run npm run sandbox:setup first. Dependencies are installed only in OS temporary storage.');
}
const installed = JSON.parse(await readFile(join(moduleRoot, '@wp-playground/cli/package.json'), 'utf8'));
if (installed.version !== PLAYGROUND_VERSION) throw new Error(`Expected Playground ${PLAYGROUND_VERSION}; run --setup.`);
const [{ getPHPLoaderModule }, universal, wordpress] = await Promise.all([
  import(pathToFileURL(join(moduleRoot, '@php-wasm/node/index.js')).href),
  import(pathToFileURL(join(moduleRoot, '@php-wasm/universal/index.js')).href),
  import(pathToFileURL(join(moduleRoot, '@wp-playground/wordpress/index.js')).href),
]);
const { loadPHPRuntime, setPhpIniEntries } = universal;
const downloads = join(cache, 'downloads');
const resultsRoot = join(cache, 'results');
await mkdir(downloads, { recursive: true });
await mkdir(resultsRoot, { recursive: true });
const runDirectory = await mkdtemp(join(resultsRoot, 'run-'));
const artifacts = [];

async function download(url, filename) {
  const target = join(downloads, filename);
  if (!existsSync(target)) {
    console.log(`Downloading ${url}`);
    const response = await fetch(url, { signal: AbortSignal.timeout(120000) });
    if (!response.ok) throw new Error(`Download failed: HTTP ${response.status} ${url}`);
    await writeFile(target, new Uint8Array(await response.arrayBuffer()));
  }
  const bytes = await readFile(target);
  artifacts.push({ url, path: target, bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') });
  return bytes;
}

async function latestWordPress() {
  const url = 'https://api.wordpress.org/core/version-check/1.7/?version=6.4';
  const response = await fetch(url, { signal: AbortSignal.timeout(30000) });
  if (!response.ok) throw new Error(`Version lookup failed: ${response.status}`);
  const data = await response.json();
  await writeFile(join(runDirectory, 'wordpress-release.json'), JSON.stringify(data, null, 2));
  const version = data.offers?.find((offer) => offer.response === 'upgrade')?.current;
  if (!version || !/^\d+\.\d+(?:\.\d+)?$/.test(version)) throw new Error('Official WordPress API did not return a stable release.');
  return version;
}

const latest = values.wp === 'latest' || values.matrix ? await latestWordPress() : values.wp;
const combinations = values.matrix
  ? [{ wp: '6.4', php: '8.1' }, { wp: '6.4', php: '8.5' }, { wp: latest, php: '8.1' }, { wp: latest, php: '8.5' }]
  : [{ wp: values.wp === 'latest' ? latest : values.wp, php: values.php }];
if (values.matrix && values.serve) throw new Error('--serve and --matrix cannot be combined.');
const fixtureSource = await readFile(join(repository, 'tests/integration/offline-http.php'), 'utf8');
const sqliteZip = await readFile(join(moduleRoot, '@wp-playground/cli/sqlite-database-integration.zip'));
artifacts.push({ source: `@wp-playground/cli@${PLAYGROUND_VERSION}/sqlite-database-integration.zip`, sha256: createHash('sha256').update(sqliteZip).digest('hex') });

async function copyIntoWasm(php, source, destination) {
  for (const entry of await readdir(source, { withFileTypes: true })) {
    if (entry.isSymbolicLink()) throw new Error(`Refusing to expose symlink to WASM: ${join(source, entry.name)}`);
    const target = `${destination}/${entry.name}`;
    if (entry.isDirectory()) {
      php.mkdirTree(target);
      await copyIntoWasm(php, join(source, entry.name), target);
    } else if (entry.isFile()) {
      php.writeFile(target, await readFile(join(source, entry.name)));
    }
  }
}

async function copyPlugin(php) {
  if (values.zip) {
    const zipPath = resolve(repository, values.zip);
    const zipBytes = await readFile(zipPath);
    artifacts.push({ source: zipPath, bytes: zipBytes.length, sha256: createHash('sha256').update(zipBytes).digest('hex') });
    php.writeFile('/tmp/lingora-candidate.zip', zipBytes);
    const installed = await php.run({ code: `<?php
      $archive = new ZipArchive();
      if (true !== $archive->open('/tmp/lingora-candidate.zip')) { exit(1); }
      for ($i = 0; $i < $archive->numFiles; ++$i) {
        $name = $archive->getNameIndex($i);
        if (!str_starts_with($name, 'lingora/') || str_contains($name, '..') || str_contains($name, chr(92))) { exit(2); }
      }
      if (!$archive->extractTo('/wordpress/wp-content/plugins')) { exit(3); }
      $archive->close();
      echo 'Candidate ZIP installed in WASM memory.';
    ` });
    console.log(installed.text);
    return;
  }
  const destination = '/wordpress/wp-content/plugins/lingora';
  php.mkdirTree(destination);
  for (const file of ['lingora.php', 'uninstall.php', 'readme.txt', 'LICENSE']) {
    php.writeFile(`${destination}/${file}`, await readFile(join(repository, file)));
  }
  for (const folder of ['admin', 'assets', 'includes', 'languages', 'public']) {
    if (!existsSync(join(repository, folder))) continue;
    php.mkdirTree(`${destination}/${folder}`);
    await copyIntoWasm(php, join(repository, folder), `${destination}/${folder}`);
  }
}

const summary = { playground: PLAYGROUND_VERSION, node: process.version, cache, runDirectory, isolation: {
  filesystem: 'WASM memory; explicit copies only; no host filesystem mounts',
  database: 'SQLite in WASM memory; discarded on exit',
  phpNetworking: 'No Node networking proxy; WebSocket connections refused; WordPress pre_http_request deny/fixtures',
  phpSubprocesses: 'Disabled; no host command execution',
  fixtures: 'No real API keys or AI requests',
}, artifacts, runs: [] };
console.log(`Results: ${runDirectory}`);

for (const combination of combinations) {
  if (!/^\d+\.\d+(?:\.\d+)?$/.test(combination.wp)) throw new Error('Use a stable numeric --wp version or latest.');
  if (!universal.SupportedPHPVersions.includes(combination.php)) throw new Error(`Unsupported PHP version: ${combination.php}`);
  const label = `wp-${combination.wp}-php-${combination.php}${values['without-mbstring'] ? '-no-mbstring' : ''}`;
  const run = { ...combination, label, outputs: [], success: true };
  summary.runs.push(run);
  let handler;
  try {
    const zip = await download(`https://downloads.wordpress.org/release/wordpress-${combination.wp}.zip`, `wordpress-${combination.wp}.zip`);
    console.log(`Booting ${label}`);
    handler = await wordpress.bootWordPressAndRequestHandler({
      createPhpRuntime: async () => loadPHPRuntime(await getPHPLoaderModule(combination.php), {
        websocket: { url: () => { throw new Error('WASM network access is disabled.'); } },
      }),
      phpVersion: combination.php,
      maxPhpInstances: 1,
      siteUrl: `http://127.0.0.1:${values.port}`,
      sapiName: 'cli',
      spawnHandler: () => universal.sandboxedSpawnHandlerFactory(),
      wordPressZip: new File([zip], 'wordpress.zip'),
      sqliteIntegrationPluginZip: new File([sqliteZip], 'sqlite.zip'),
      constants: {
        WP_DEBUG: true, WP_DEBUG_LOG: true, WP_DEBUG_DISPLAY: false,
        WP_ENVIRONMENT_TYPE: 'local', DISABLE_WP_CRON: true,
        WP_HTTP_BLOCK_EXTERNAL: true, AUTOMATIC_UPDATER_DISABLED: true,
      },
      phpIniEntries: { allow_url_fopen: '0', memory_limit: '512M', display_errors: '0' },
      onPHPInstanceCreated: async (php) => {
        if (values['without-mbstring']) {
          const result = await php.run({ code: '<?php echo json_encode(array_values(array_filter(get_defined_functions()["internal"], static fn($name) => str_starts_with($name, "mb_"))));' });
          await setPhpIniEntries(php, { disable_functions: JSON.parse(result.text).join(',') });
        }
      },
      hooks: { beforeDatabaseSetup: async (php) => {
        php.mkdirTree('/wordpress/wp-content/mu-plugins');
        php.writeFile('/wordpress/wp-content/mu-plugins/000-lingora-offline.php', fixtureSource);
      } },
    });
    const php = await handler.getPrimaryPhp();
    const versionSource = php.readFileAsText('/wordpress/wp-includes/version.php');
    run.archiveWordPressVersion = versionSource.match(/\$wp_version\s*=\s*['"]([^'"]+)['"]/)?.[1];
    if (run.archiveWordPressVersion !== combination.wp) {
      throw new Error(`WordPress archive version mismatch: requested ${combination.wp}, extracted ${run.archiveWordPressVersion}`);
    }
    console.log(`Verified wp-includes/version.php: ${run.archiveWordPressVersion}`);
    await copyPlugin(php);
    if (existsSync(join(repository, 'tests'))) {
      php.mkdirTree('/wordpress/wp-content/plugins/lingora/tests');
      await copyIntoWasm(php, join(repository, 'tests'), '/wordpress/wp-content/plugins/lingora/tests');
    }
    async function record(name, response) {
      const prefix = join(runDirectory, `${label}-${name}`);
      await writeFile(`${prefix}.stdout.txt`, response.text);
      await writeFile(`${prefix}.stderr.txt`, response.errors || '');
      const details = { name, exitCode: response.exitCode, httpStatusCode: response.httpStatusCode, stdout: `${prefix}.stdout.txt`, stderr: `${prefix}.stderr.txt` };
      run.outputs.push(details);
      console.log(`[${label}] ${name}: exit ${response.exitCode}`);
      if (response.text) console.log(response.text.trim());
      if (response.errors) console.error(response.errors.trim());
      if (response.exitCode !== 0) run.success = false;
      if (name.startsWith('wp-cli-') && values['plugin-check']) {
        const findings = response.text.split(/\r?\n/).filter((line) => line.startsWith('[{')).flatMap((line) => {
          try { return JSON.parse(line); } catch { return []; }
        });
        run.pluginCheck = { errors: findings.filter((item) => item.type === 'ERROR').length, warnings: findings.filter((item) => item.type === 'WARNING').length, findings };
        // Reviewed false positive: exception messages are not output. REST JSON-encodes
        // them and the admin/editor UI renders via textContent, so no escaping is needed.
        const blocking = findings.filter((item) => item.type === 'ERROR' && item.code !== 'WordPress.Security.EscapeOutput.ExceptionNotEscaped');
        run.pluginCheck.blocking = blocking.length;
        if (blocking.length > 0) run.success = false;
      }
      return response;
    }
    const activated = await record('activation', await php.run({ code: `<?php
      require '/wordpress/wp-load.php';
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
      $result = activate_plugin('lingora/lingora.php');
      if (is_wp_error($result)) { fwrite(STDERR, $result->get_error_message()); exit(1); }
      echo 'Lingora activated in isolated WordPress. PHP ' . PHP_VERSION . "\\n";
    ` }));
    if (activated.exitCode !== 0) throw new Error('Plugin activation failed. See saved output.');
    await record('smoke', await php.run({ scriptPath: '/wordpress/wp-content/plugins/lingora/tests/integration/smoke.php' }));
    for (const [index, filename] of (values['php-file'] || []).entries()) {
      const absolute = resolve(repository, filename);
      const source = await readFile(absolute);
      let target;
      if (absolute.startsWith(join(repository, 'tests') + (process.platform === 'win32' ? '\\' : '/'))) {
        target = '/wordpress/wp-content/plugins/lingora/' + absolute.slice(repository.length + 1).replaceAll('\\', '/');
      } else {
        target = `/wordpress/lingora-test-${index}.php`;
        php.writeFile(target, source);
      }
      await record(`test-${index}`, await php.run({ scriptPath: target }));
    }
    if (values['wp-cli']?.length || values['plugin-check']) {
      const wpcli = await download('https://playground.wordpress.net/wp-cli.phar', 'wp-cli.phar');
      php.writeFile('/tmp/wp-cli.phar', wpcli);
      const { wpCLI } = await import(pathToFileURL(join(moduleRoot, '@wp-playground/blueprints/index.js')).href);
      const commands = [...(values['wp-cli'] || [])];
      if (values['plugin-check']) {
        const infoUrl = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=plugin-check';
        const info = await (await fetch(infoUrl, { signal: AbortSignal.timeout(30000) })).json();
        if (!info.download_link?.startsWith('https://downloads.wordpress.org/plugin/plugin-check.')) throw new Error('Unexpected Plugin Check download URL.');
        run.pluginCheckVersion = info.version;
        const pluginZip = await download(info.download_link, `plugin-check-${info.version}.zip`);
        php.writeFile('/tmp/plugin-check.zip', pluginZip);
        await record('install-plugin-check', await php.run({ code: `<?php
          $zip = new ZipArchive();
          if (true !== $zip->open('/tmp/plugin-check.zip')) { exit(1); }
          $zip->extractTo('/wordpress/wp-content/plugins'); $zip->close();
          require '/wordpress/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php';
          $result = activate_plugin('plugin-check/plugin.php');
          if (is_wp_error($result)) { echo $result->get_error_message(); exit(1); }
          echo 'Plugin Check activated.';
        ` }));
        commands.push('wp plugin check lingora --format=json');
      }
      for (const [index, command] of commands.entries()) {
        if (command === 'wp plugin check lingora --format=json') {
          // PHPCS has no interactive input in this SAPI; leave STDIN undefined.
          await record(`wp-cli-${index}`, await php.run({ code: `<?php
            if (is_dir('/wordpress/wp-content/plugins/lingora/tests')) {
              rename('/wordpress/wp-content/plugins/lingora/tests', '/tmp/lingora-regression-tests');
            }
            putenv('SHELL_PIPE=0');
            define('STDOUT', fopen('php://stdout', 'wb'));
            define('STDERR', fopen('php://stderr', 'wb'));
            $GLOBALS['argv'] = array('/tmp/wp-cli.phar', '--path=/wordpress', '--allow-root', 'plugin', 'check', 'lingora', '--format=json');
            require '/tmp/wp-cli.phar';
          ` }));
        } else {
          await record(`wp-cli-${index}`, await wpCLI(php, { command }));
        }
      }
    }
    if (php.fileExists('/wordpress/wp-content/debug.log')) {
      const debugLog = join(runDirectory, `${label}-wordpress-debug.log`);
      await writeFile(debugLog, php.readFileAsText('/wordpress/wp-content/debug.log'));
      run.debugLog = debugLog;
    }
    if (values.serve) {
      const port = Number(values.port);
      if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('Invalid --port.');
      let pending = Promise.resolve();
      const server = createServer((request, response) => {
        pending = pending.then(async () => {
          const chunks = [];
          for await (const chunk of request) chunks.push(chunk);
          const result = await handler.request({ url: request.url, method: request.method, headers: request.headers, body: chunks.length ? Buffer.concat(chunks) : undefined });
          response.writeHead(result.httpStatusCode, result.headers);
          response.end(Buffer.from(result.bytes));
        }).catch((error) => { response.writeHead(500); response.end(String(error)); });
      });
      await new Promise((resolveListen, reject) => { server.once('error', reject); server.listen(port, '127.0.0.1', resolveListen); });
      console.log(`Temporary sandbox: http://127.0.0.1:${port}/wp-admin/ (local test login admin / password). Ctrl+C discards site.`);
      await new Promise((resolveStop) => { process.once('SIGINT', resolveStop); process.once('SIGTERM', resolveStop); });
      await new Promise((resolveClose) => server.close(resolveClose));
    }
  } catch (error) {
    run.success = false;
    run.error = error.stack || String(error);
    if (error.response) {
      await writeFile(join(runDirectory, `${label}-failure.stdout.txt`), error.response.text || '');
      await writeFile(join(runDirectory, `${label}-failure.stderr.txt`), error.response.errors || '');
    }
    console.error(run.error);
  } finally {
    if (handler) {
      const debugPhp = await handler.getPrimaryPhp();
      if (debugPhp.fileExists('/wordpress/wp-content/debug.log')) {
        run.debugLog = join(runDirectory, `${label}-wordpress-debug.log`);
        await writeFile(run.debugLog, debugPhp.readFileAsText('/wordpress/wp-content/debug.log'));
      }
      await handler[Symbol.asyncDispose]();
    }
    await writeFile(join(runDirectory, 'summary.json'), JSON.stringify(summary, null, 2));
  }
}
console.log(`Evidence: ${join(runDirectory, 'summary.json')}`);
if (summary.runs.some((run) => !run.success)) process.exitCode = 1;

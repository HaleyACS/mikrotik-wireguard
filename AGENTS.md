# AGENTS.md — MikroTik WireGuard Peer Manager

## User Preferences

- **GitHub issue responses** — when asked for text to post on GitHub, always provide it as a markdown code block (triple backticks with `markdown`).
- **Commit format** — use `v1.x.x — title with changes` (e.g. `v1.7.0 — session expiry check, subnet from config, formatBytes dedup, expanded tests`).
- **Commit language** — commit messages must always be in English.
- **Changelog before release** — always update `CHANGELOG.md` with the new version's changes before committing/tagging any release.

## Architecture

Two independent subsystems in one repo:

| Subsystem | Language | Entrypoint | Purpose |
|-----------|----------|------------|---------|
| Web Dashboard | PHP+JS | `index.php` | CRUD WireGuard peers on MikroTik CHR |
| Migration Suite | PHP+Python | `temp/migration/scripts/` (gitignored, local only) | SSTP→WG migration of ~337 client routers |

Neither uses a framework, Composer, or any package manager. No build/lint/typecheck tooling exists.

## Web Dashboard

**Entrypoints:**
- `login.php` — login form (shown when session expired or not authenticated)
- `setup.php` — first-run password setup (writes `.admin-hash`)
- `config.php` — thin wrapper: `require __DIR__ . '/src/ConfigManager.php'; return ConfigManager::resolveConfig();`
- `index.php` — view: renders the dashboard UI with **server selector dropdown** in header, tests connection on page load via `getPeers()` (same call for both API modes); validates config via `ConfigValidator` on startup (HTML error page on invalid config). Shows API mode badge (rest/native + port) and logout button in header. Passes `AppConfig.serverKey` and `AppConfig.servers` to JS. **No inline JS handlers** — all interactivity uses `data-action` attributes + event delegation in `app.js` (the CSP blocks inline handlers).
- `src/api.php` — AJAX API: handles 9 actions (`get_peers`, `get_interface_status`, `check_session`, `add_peer`, `regenerate_key`, `update_peer`, `toggle_peer`, `delete_peer`, `export_vpn_ips`), returns JSON; validates config first (JSON error on invalid config); requires valid session + CSRF token for write operations. `get_peers`, `get_interface_status` and `check_session` require valid session but not CSRF. Uses `ClientFactory::create()` to instantiate the correct client. JS in `app.js` calls `src/api.php?action=...&server=key`
- `src/export-vpn-ips.php` — CLI helper (tracked): exports WireGuard + SSTP/PPTP peer IPs to a text file using the configured client.

**Key classes:**
- `src/auth.php` — session management: `login()`, `logout()`, `isLoggedIn()`, `requireAuth()`, `getCsrfToken()`, `validateCsrfToken()`, `requireCsrf()`. Reads admin hash from `.admin-hash` file only.
- `src/ClientInterface.php` — common interface: `request()`, `getPeers()`, `getAllPeers()`, `getServerPublicKey()`, `getInterface()`, `getInterfaceStatus()`, `addPeer()`, `updatePeer()`, `togglePeer()`, `deletePeer()`, `getPppSecrets()`, `getPppActive()`
- `src/ClientFactory.php` — factory: creates `MikrotikRestClient` or `MikrotikApiClient` based on `api_mode` config
- `src/MikrotikRestClient.php` — REST mode: HTTP client via `file_get_contents` + stream context (not cURL), talks RouterOS 7 REST API at `{host}/rest/...`. Implements `ClientInterface`.
- `src/MikrotikApiClient.php` — Native mode: wraps Python bridge (`get_peer_data.py`) via `proc_open`. Implements `ClientInterface`. Fully independent from REST API — no calls to port 443.
- `src/get_peer_data.py` — Python bridge: uses `librouteros` to query RouterOS native API (port 8728/8729). Communicates via stdin/stdout JSON with 15s timeout.
- `src/WireGuardManager.php` — business logic: key gen (X25519 via `sodium_crypto_scalarmult_base`), IP allocation, config generation, IP collision detection in `addPeer()`, conditional RouterOS client export metadata, `togglePeer()` for enable/disable; static `maxPeers()` for CIDR size calculation, `extractUniqueIpv4Addresses()` for deduplicating peer IPs. All methods throw exceptions on failure. Type-hints `ClientInterface` instead of concrete client. `formatHandshake()` adds spaces between time units ("20h44m42s" → "20h 44m 42s").
- `src/ConfigManager.php` — multi-server config resolution: `getAvailableServers()`, `getActiveServerKey()`, `resolveConfig()`, `persistServerKey()`. Resolution priority: `$_GET['server']` → `$_SESSION['active_server']` → first config file alphabetically.
- `src/ConfigValidator.php` — validates config on startup: subnet CIDR, server IP in range, endpoint format, `client_allowed_ips` CIDRs, DNAT port overflow, `export_mode`, required fields

**Runtime:** connects directly to real MikroTik. Config resolved via `ConfigManager::resolveConfig()` in `config.php`.

**Config (`configs/` directory):**
- Each file in `configs/*.php` is a server config (gitignored). Template at `configs/config.php.dist` (tracked).
- `config.php` is a thin wrapper calling `ConfigManager::resolveConfig()`.
- Resolution: `$_GET['server']` — first; `$_SESSION['active_server']` — second; first file alphabetically — fallback.
- Each config file contains:
```
api_mode / host / username / password / ssl_verify / native_api[type,port,tls,python_script] / interface / subnet / server_ip / endpoint / client_export_metadata / client_dns / client_allowed_ips / dnat_base / dnat_multiplier / show_dnat_column / show_traffic_column / export_mode / comment / lang / refresh_interval / handshake_timeout / page_size
```
- `api_mode`: `'rest'` (default, uses HTTPS port 443) or `'native'` (uses native API port 8728/8729)
- `ssl_verify` is `false` by default — HTTPS but no cert validation.
- `native_api` section falls back to main `host`/`username`/`password` if not overridden
- `native_api.python_script` defaults to `src/get_peer_data.py` (relative to config file: `__DIR__ . '/../src/get_peer_data.py'`)
- `show_dnat_column`: boolean (default false) — shows a sortable DNAT Port column in the peers table (right of IP) with one-click port copy
- `show_traffic_column`: boolean (default true) — shows a Traffic column with RX/TX data for each peer
- `comment`: string — default comment used for generated peers and client `.rsc` scripts (falls back to `interface`)
- `refresh_interval`: seconds — dashboard auto-refresh interval
- `handshake_timeout`: minutes (default 5) — peer handshakes older than this show "never" in UI
- `page_size`: peers per page (default 50); `0` disables pagination

**Server selector:** a `<select>` dropdown in the dashboard header shows all available servers. JS in `app.js` provides `apiUrl(action)` helper that appends `&server=${AppConfig.serverKey}` to every fetch, and `switchServer(key)` that redirects to `?server=key`.

**Security:**
- `.htaccess` is gitignored; template at `.htaccess.example` with commented `Require ip` directives
- `setup.php` + `login.php` for password-protected dashboard access (mandatory)
- Admin password hash stored in `.admin-hash` (gitignored)
- Session timeout after 30 min inactivity
- CSRF token on all write API operations (validated server-side with `hash_equals()`)
- Security headers: CSP, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`. CSP uses `script-src 'self'` + a per-request nonce (no `'unsafe-inline'` for scripts; the `AppConfig` inline block is nonce-tagged). `style-src` keeps `'unsafe-inline'` because inline styles are not yet migrated to classes.
- `display_errors` disabled in production
- Input sanitization: `htmlspecialchars()` in PHP, `escapeHtml()` in JS. `escapeJs()` was removed — peer data flows through `data-*` attributes, where HTML escaping is the correct encoding.

## Tests

Custom mini-test-runner, **not PHPUnit**. Auto-discovers `*Test.php` classes extending `TestCase` in `tests/`.

```bash
php tests/run_tests.php          # run all tests (exit 1 on failure)
```

Tests use `MockMikrotikRestClient` (injectable via constructor) with sequential response queue (`array_shift`). Tests: key generation, IP allocation, config formatting, get peers, add peer (incl. collision detection), regenerate key, update peer, delete peer, config validation, URL construction, auth, brute-force lockout, IP extraction, multi-server config resolution, export mode validation, `AuthTestCase` base class for auth test fixtures. `MockMikrotikRestClient` lives in its own file under `tests/`.

Requires `ext-sodium` (PHP 8+). No database, no filesystem, no network — fully self-contained.

## Known Technical Debt

### Config generation logic duplicated (PHP + JS)
`WireGuardManager::generateConfig()`/`generateRscScript()` in PHP and `updateExportConfig()` in `app.js` generate identical `.conf`/`.rsc` strings. The API's `regenerate_key` action now returns pre-generated `config` and `script` so JS just displays them. The JS-side generators are kept as dead code for now.

### JS fetch/CSRF boilerplate
Six actions (`submitAddPeer`, `submitEditPeer`, `submitDeletePeer`, `togglePeer`, `regenerateKey`, `submitExportVpnIps`) had identical fetch/CSRF/JSON patterns. Extracted to `apiPost()` wrapper in `app.js`.

### Test fixture duplication
`authTest` and `authEdgeCasesTest` shared identical `setUp`/`tearDown`/`createHashFile`/`removeHashFile`. Extracted to `tests/AuthTestCaseBase.php` base class. `MockMikrotikRestClient` extracted from `WireGuardManagerTest.php` to its own `tests/MockMikrotikRestClient.php`.

## Migration Suite

Multi-step pipeline (not needed for dashboard work):

```
generate.php → migration.csv + chr-peers.rsc + clients/*.rsc
  → deploy.py (deploys to client routers)
  → disable_sstp.py (disables old SSTP)
  → update_addresses.py (Winbox address book)
  → scan_clienti.py + update_clienti.php (parameter docs)
```

Python scripts use `librouteros` for MikroTik API access. Common CLI patterns: `--dry-run`, `--limit=N`, `--resume`, `--user`/`--password`.

Invocation examples:

```bash
sudo -u rollopack php temp/migration/scripts/update_clienti.php --plan=migration_plan.json
sudo -u rollopack php temp/migration/scripts/update_clienti.php --plan=migration_plan.json --dry-run
python3 temp/migration/scripts/deploy.py --user=admin --password=xxxxx --dry-run --limit=10
python3 temp/migration/scripts/disable_sstp.py --user=admin --password=xxxxx --dry-run --remove --chr --limit=10
php temp/migration/scripts/generate_nat.php > temp/migration/nat-winbox.rsc
```

## DNAT port formula

Used for Winbox access behind WG: `{dnat_base} + third_octet * {dnat_multiplier} + fourth_octet`

Config values `dnat_base` (default `30000`) and `dnat_multiplier` (default `1000`) are set in the server's config file under `configs/`.
See `configs/config.php.dist` for documentation.

Example with defaults: `3.0.1.100` → port `30000 + 1 * 1000 + 100 = 31100`.

**Formula validity:** `dnat_base + third_octet_max * dnat_multiplier + 255 ≤ 65535` where `third_octet_max = (1 << (24 - cidr)) - 1`.

**Quick reference (defaults 30000/1000):**

| Subnet | Third octet range | Max port | Valid? |
|--------|-------------------|----------|--------|
| `/24` | fixed (0) | 30255 | ✅ |
| `/21` | 0–7 | 37255 | ✅ |
| `/20` | 0–15 | 45255 | ✅ |
| `/19` | 0–31 | 61255 | ✅ |
| `/18` | 0–63 | 93255 | ❌ |

For subnets wider than `/19`, reduce `dnat_base` and/or `dnat_multiplier` in the server's config file.

## Gotchas

- `WireGuardManager` takes `$client` and `$config` via constructor.
- Key generation uses `random_bytes(32)` + `sodium_crypto_scalarmult_base()` — standard X25519.
- IP allocation scans from `network+1` to `network_end`, skipping server IP and allocated IPs.
- `addPeer()` calls `getAllPeers()` once for IP allocation, then `getPeers()` (via `resolvePeerId()`) only if the PUT response lacks `.id`. The collision retry loop re-fetches `getAllPeers()` per attempt.
- `MockMikrotikRestClient` uses `array_shift` from a response queue for sequential calls to the same endpoint.
- JS auto-refreshes on the configured `refresh_interval` (default 30s), paused while a modal is open. Client search/sort/hide-offline are client-side. Sortable columns: name, ip, handshake, and DNAT (when `show_dnat_column` is enabled).
- `.rsc` files are MikroTik RouterOS scripts, not shell scripts.
- Migration artifacts live in `temp/migration/` (gitignored).
- `getServerPublicKey()` and `getPeers()` in `MikrotikApiClient` talk only to the Python bridge (port 8728/8729) — zero REST API calls.
- Python bridge communicates via `proc_open` with stdin JSON (credentials secure) and stdout JSON (result), with 15s timeout.
- In native mode, `$config['host']` should be a bare IP/hostname (no scheme), as it's passed to librouteros `connect()` directly.
- Querying librouteros uses `api.path('/...').select(Key('a')).where(Key('b') == 'c')` — passing `query=` as kwarg to `api()` is NOT supported and causes "unhashable type: 'list'".
- Auth functions no longer accept `$config` param: `isAuthEnabled()`, `getAdminHash()`, `isLoggedIn()`, `login()`, `requireAuth()` are parameterless — they read directly from `.admin-hash` or session state.
- CSRF token must be sent as `X-CSRF-Token` header on all POST requests to `api.php` (except read actions `get_peers`, `get_interface_status`, `check_session`). The JS sends it automatically via `AppConfig.csrfToken`.
- Config files in `configs/` use `__DIR__` relative to the config file itself (e.g. `__DIR__ . '/../src/get_peer_data.py'`), NOT relative to project root — they live one level deeper.
- `configs/*.php` are gitignored; `configs/config.php.dist` is tracked (`.dist` extension avoids the glob).
- `config.php` resolves the active config via `ConfigManager::resolveConfig()` — it does NOT start a session (checks `session_status()` first to avoid PHP 8.4 CLI warnings).
- Server resolution priority: `$_GET['server']` > `$_SESSION['active_server']` > first file alphabetically.
- `ConfigManager::persistServerKey()` writes the active server key to `$_SESSION['active_server']` — called from `index.php` when a valid `?server=` param is present.
- `ConfigManager` uses `ReflectionClass` to reset static state between requests in tests (see `setUp()` in `ConfigManagerTest.php`).
- `export_mode` config key accepts `'conf'` or `'rsc'`. JS normalizes `'rsc'` → `'script'` internally for `switchAddTab()` / `switchExportTab()` which expect `'conf'` or `'script'`.
- `updateExportConfig()` and `displayAddResult()` copy the content of the active tab to clipboard automatically after generating configs, using the raw variable (not DOM) to preserve newlines.
- All interactive elements use `data-action` + `data-*` attributes (e.g. `data-action="close-modal" data-modal="<backdropId>"`). Two delegated listeners in `app.js` dispatch everything: `handleDelegateClick` (click) and `handleDelegateKeydown` (Escape closes the active modal, Enter/Space sorts the `[data-action="sort"]` headers). NEVER add inline `onclick=`/`onsubmit=` etc. — the CSP (`script-src 'self'` + nonce) blocks them.
- Modals are managed via generic `openModal(id)`/`closeModal(id)` in `app.js` (adds `.active`, toggles `aria-hidden`, runs `trapFocus()`, restores focus to the trigger). Per-modal open/close wrappers (`openAddModal`, `closeDeleteModal`, …) add specific setup/teardown. `.modal-backdrop:not(.active)` uses `visibility: hidden` so closed modals stay out of the tab order.
- DNAT port is computed in JS with the `dnatPort(ip)` helper in `app.js`, reused by the DNAT column, the DNAT sort, `copyDnatPort()` and the export modal.

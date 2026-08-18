# Changelog

All notable changes to this project will be documented in this file.

## [2.3.0] - 2026-08-18
- **Added**: Public API endpoint `src/public-api.php` for machine-to-machine integration — creates a WireGuard peer via `POST ?action=create_peer` and returns IP, key pair, `.conf` and `.rsc`
- **Added**: `check_peer` (GET) + `regenerate_peer` (POST) actions — name-existence check and key regeneration for an existing peer (same name/IP, new key pair) to support the "name already exists → ask to overwrite" flow
- **Added**: `delete_peer` (POST) and `toggle_peer` (POST, `{name, disabled}`) actions — client removal and reversible suspension (enable/disable without touching keys)
- **Added**: `WireGuardManager::findPeerByName()`, `regeneratePeer()`, `deletePeerByName()`, `togglePeerByName()` (case-insensitive lookup, same response shape as `addPeer()`)
- **Security**: unknown `server` query param now returns HTTP `400` instead of silently falling back to the default config (`ConfigManager::serverExists()`)
- **Added**: Bearer token authentication (`.api-token`, gitignored) with `getApiToken()`/`isApiTokenValid()`/`requireApiToken()` in `src/auth.php`
- **Security**: `.htaccess.example` now includes a `<FilesMatch "^\.">` block to prevent direct download of `.api-token`/`.admin-hash`
- **Docs**: "Public API" section in `README.md` (setup, actions table, request/response contract, curl examples for the create/overwrite flow)
- **Tests**: `PublicApiAuthTest` (token loading/validation) + `findPeerByName`/`regeneratePeer`/`deletePeerByName`/`togglePeerByName`/`serverExists` coverage (134 → 157 tests)

## [2.2.2] - 2026-07-31
- **Security**: CSP `script-src` now uses a per-request nonce — `'unsafe-inline'` removed; `AppConfig` inline block is nonce-tagged
- **Changed**: all inline JS handlers removed — interactivity via `data-action` + event delegation
- **Added**: DNAT Port column is now sortable; `dnatPort(ip)` helper deduplicates the port formula
- **Added**: contextual empty states (no peers / no search results) with new i18n keys (EN/IT)
- **Accessibility**: `role="dialog"`/`aria-modal`/`aria-labelledby` on all modals, `role="alert"` banner, `role="status"` toast, closed modals out of tab order, global `:focus-visible`, `prefers-reduced-motion`, `aria-label` on copy/icon buttons
- **Refactor**: generic `openModal`/`closeModal` helpers (focus trap + restore, Escape handling); removed `cloneNode` hack in confirm/delete modals
- **Cleanup**: removed dead connection-test if/else and 10 unused i18n keys; dropped `.table-wrapper` wrapper and footer CSS

## [2.2.1] - 2026-07-30
- **Fixed**: setup.php CSRF validation — token now persisted in session (no more "invalid token" error)
- **Fixed**: bare `$this->config[...]` accesses now use safe `??` fallbacks
- **Added**: duplicate peer name detection with translated error messages (EN/IT)
- **Added**: `normalizeBool()` helper to deduplicate boolean normalization across clients
- **Changed**: `addPeer()` split into focused private methods (`resolvePeerId`, `handleCollision`)
- **Changed**: better HTTP error parsing in REST client (supports `error` key)
- **Changed**: toast duration increased from 3s to 5s
- **Changed**: test runner skips abstract classes; auth test base renamed to `AuthTestCaseBase.php`

## [2.2.0] - 2026-07-28
- Configurable DNAT and Traffic columns
- Compact traffic layout

## [2.1.0] - 2026-07-23
- `export_mode` config option (`conf`/`rsc`)
- Auto-copy config on peer creation/regeneration
- Tab normalization

## [2.0.0] - 2026-07-23
- Multi-server support: `ConfigManager`, `configs/` directory, server selector dropdown

## [1.9.0] - 2026-07-15
- Security fixes: XSS, CSP, CSRF
- IP collision retry
- Pagination
- Interface status panel
- Handshake timeout
- GPL-3.0 headers + LICENSE file
- Updated screenshots

## [1.8.6] - 2026-07-14
- Click-to-copy peer name

## [1.8.5] - 2026-07-10
- "Hide offline" also hides disabled peers

## [1.8.4] - 2026-07-10
- Toggle peer enable/disable button

## [1.8.3] - 2026-07-06
- Accessibility fixes (aria, focus trap, semantic HTML)
- Custom confirm modal
- Removed inline styles
- Added missing translations

## [1.8.2] - 2026-07-06
- Brute-force protection
- Secure session cookie flags
- Collision detection in `addPeer`
- `extractUniqueIpv4Addresses` helper
- Dropped deprecated `setAccessible(true)`
- Auth functions no longer take `$config` param

## [1.8.1] - 2026-07-02
- Performance: removed redundant `getServerPublicKey` call
- Native mode compatibility via `getPppSecrets`
- Trimmed `formatHandshake` trailing space
- Dropped dead `MikrotikApiClient` mode param
- 47 new tests (client factory, api client, auth edges, i18n)

## [1.8.0] - 2026-07-02
- Native API mode fully decoupled from REST — all operations via Python bridge

## [1.7.0] - 2026-07-02
- Session expiry check
- Subnet from config
- `formatBytes` deduplication
- Configurable peer comment and management IP
- Configurable dashboard refresh interval
- Search filter persisted across page reloads
- IP collision fix when peers lose interface reference
- Login CSS moved to external file
- Expanded tests

## [1.6.1] - 2026-06-17
- Unified auth storage: `.admin-hash` only, removed config key
- Cleaned up `.gitignore`
- Updated Quick Start with setup flow

## [1.6.0] - 2026-06-17
- Logout icon
- CSRF protection
- Security headers

## [1.5.1] - 2026-06-17
- Authentication mandatory (no more auth-free access)

## [1.5.0] - 2026-06-17
- PHP session login with web setup

## [1.4.5] - 2026-06-17
- Fixed `.htaccess` syntax: removed `<RequireAll>`, direct `Require ip`

## [1.4.4] - 2026-06-17
- Moved `.htaccess` to `.gitignore`, added `.htaccess.example` template

## [1.4.3] - 2026-06-17
- Fixed translations, button state, empty state icon
- Added VPN IPs export button with SSTP/PPTP secret options
- Updated Dashboard screenshot

## [1.4.1] - 2026-06-16
- Bugfix release

## [1.4.0] - 2026-06-16
- Dual API mode (rest + native) with factory pattern

## [1.3.2] - 2026-06-15
- Removed test command from Quick Start (docs)

## [1.3.1] - 2026-06-15
- Removed remaining demo mode references
- Fixed GitHub URL in README, synced AGENTS.md

## [1.3.0] - 2026-06-15
- i18n support and major UI overhaul
- Configurable DNAT port formula, disclaimer, security warnings
- Config validation, consistent error handling, peer mutation tests
- README cleanup

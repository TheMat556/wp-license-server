# AGENTS.md — WP License Server

This document is the authoritative reference for AI agents and contributors working in this repository.

---

## Architecture Overview

### PHP Backend (`app/`)

The backend follows a **Service + Repository + Domain** pattern with HMAC authentication.

```
app/
├── Bootstrap/         WordPress hooks wiring (plugin init)
├── CLI/               WP-CLI commands
├── Contracts/         Interfaces for repositories and services
├── Database/          Schema migrations (Schema.php, Migrator.php)
├── Domain/            Pure domain objects (LicenseState, LicenseTransitions)
├── Models/            Value objects hydrated from DB rows (License, Activation)
├── Repositories/      Data access layer (LicenseRepository, ActivationRepository)
│                      → Only repositories touch $wpdb
├── Rest/
│   ├── Controllers/   One class per route; thin — delegate to services
│   ├── Dto/           Request DTOs; constructor accepts WP_REST_Request
│   ├── Middleware/    RateLimiter, FeatureGate, HmacAuthMiddleware
│   └── Services/      REST-layer services (LicenseSettingsService)
└── Services/          Business logic (LicenseService, EncryptionService, etc.)
```

**Key invariants:**
- Controllers never call `$wpdb` directly — always through a repository.
- Services never import controllers or Dto classes.
- `EncryptionService` requires `WPLICENSE_ENCRYPTION_KEY` to be defined — there is no runtime fallback.
- All REST error codes are centralised in `app/ErrorCodes.php` (PHP 8.1 enum).
- Every `WP_Error` must use `ErrorCodes::CASE->value` as its code string.

### React/TypeScript Frontend (`src/`)

The frontend uses **Feature-Sliced Design (FSD)** with Zustand stores and TanStack Query.

```
src/
├── admin/             Entry point, AppLayout, AppRouter
│   ├── AdminApp.tsx   Root composition
│   ├── hooks/         Admin-level hooks (useLicenseServerSettings)
│   └── main.tsx       React DOM mount
├── features/          One directory per feature slice
│   ├── branding/      Branding settings (store, actions, components)
│   ├── chat/          Chat feature (store, API client, hooks)
│   ├── license/       License management (store, context, hooks)
│   ├── navigation/    Routing store and embed message handling
│   └── shell/         Shell preferences
├── platform/          Platform bindings (WordPress, embed bridge)
├── shared/
│   ├── events/        Cross-store event bus (storeEvents.ts)
│   ├── navigation/    NavigationAdapter interface and registry
│   ├── pluginRestClient.ts  Single HTTP client — all fetch() calls go here
│   └── ui/            Shared UI components (FeatureErrorBoundary, etc.)
└── types/             Global TypeScript types
```

**Key invariants:**
- **Stores must not import each other.** Cross-store communication uses `src/shared/events/storeEvents.ts`.
- All HTTP calls go through `pluginRestClient` — no raw `fetch()` elsewhere.
- React Context is **not** used to wrap Zustand stores. Consumers call `useStore(store, selector)` directly.
- Each feature route is wrapped in a `<FeatureErrorBoundary>` so crashes are isolated.
- Data fetching uses TanStack Query (`useQuery` / `useMutation`).
- Forms use `react-hook-form` with `zod` schemas via `zodResolver`.

---

## Coding Conventions

### PHP

| Convention | Detail |
|---|---|
| `declare(strict_types=1)` | Required in every PHP file |
| Error codes | Always use `ErrorCodes::CASE->value` in `new WP_Error()` |
| DTOs | `readonly` constructor properties; accept `WP_REST_Request`; no logic |
| Repositories | `find_*` methods return `Model\|WP_Error\|null`; arrays for collections |
| Encryption | `WPLICENSE_ENCRYPTION_KEY` constant must be set; throws `RuntimeException` if missing |
| Exception handling | Catch `\Throwable` in repository rows; log with `error_log()` |
| DB queries | Always use `$wpdb->prepare()` for user-supplied values |
| Namespacing | PSR-4, root `WpLicenseServer\` → `app/` |

### TypeScript / React

| Convention | Detail |
|---|---|
| No raw `fetch()` | Always use `pluginRestClient.get()` / `.post()` |
| No inter-store imports | Use `storeEvents` for cross-store side-effects |
| Error boundaries | Every feature route must be wrapped in `<FeatureErrorBoundary>` |
| Forms | `useForm()` + `zodResolver(schema)` — no `useState` for form values |
| Polling | TanStack Query `refetchInterval` — no `setInterval` in hooks |
| Strict types | No `any`, no `// @ts-ignore` |
| Formatting | Prettier (`.prettierrc`) + ESLint; run `npm run format` before committing |

---

## Running Tests, Linting, and Static Analysis

### PHP

```bash
# Install dependencies
composer install

# Run PHPUnit tests
composer run test

# PHPStan static analysis (level 6)
composer run analyse

# PHPUnit with code coverage (requires Xdebug or PCOV)
composer run test -- --coverage-clover coverage.xml
```

### JavaScript / TypeScript

```bash
# Install dependencies
npm ci

# Run Vitest tests
npm run test

# Watch mode
npm run test:watch

# ESLint
npm run lint

# TypeScript type-check (no emit)
npx tsc --noEmit

# Prettier format
npm run format
```

### Full build

```bash
# Build the React admin app
npm run build
# Output: dist/license-server-admin-app.js + dist/license-server-admin-app.css
```

---

## Generated Files — Do Not Edit Manually

The following files and directories are **generated** and must not be edited by hand:

| Path | Generator | How to regenerate |
|---|---|---|
| `dist/` | Vite | `npm run build` |
| `vendor/` | Composer | `composer install` |
| `node_modules/` | npm | `npm ci` |
| `coverage.xml` | PHPUnit | `composer run test -- --coverage-clover coverage.xml` |
| `coverage-html/` | PHPUnit | same as above |
| `test-results.xml` | PHPUnit | `composer run test` |
| `.phpunit.cache/` | PHPUnit | auto-created on test run |

---

## Internationalization (i18n)

The plugin is fully translatable using WordPress's i18n toolchain.

### Text Domain
- Text domain: `wp-license-server`
- Loaded in `Plugin::init()` via `load_plugin_textdomain()`
- Language files stored in `languages/` directory

### PHP Translations
- All user-facing PHP strings use `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `_n()`, `_x()` with the `wp-license-server` text domain
- Exception messages in `RuntimeException` also use translatable strings (textdomain loaded before service instantiation)

### JavaScript / React Translations
- React code uses a custom i18n helper at `src/utils/i18n.ts` that wraps `window.wp.i18n.__()`
- The `__()` helper defaults `domain` to `'wp-license-server'`, so callers like `__('Monthly')` inject the domain automatically without a second argument
- The `wp-i18n` script is enqueued as a dependency of the admin app
- `wp_set_script_translations()` is called in `AdminPage::enqueue_assets()` to load JS translations
- When `wp.i18n` is not available, the helper falls back to the English string

### Building Translation Files
```bash
# Regenerate the .pot template
wp i18n make-pot . languages/wp-license-server.pot --include="app/,src/" --allow-root

# Update a .po file from the .pot (existing translations preserved)
msgmerge --update languages/wp-license-server-de_DE.po languages/wp-license-server.pot

# Compile .mo from .po
msgfmt -o languages/wp-license-server-de_DE.mo languages/wp-license-server-de_DE.po
```

### Testing Translations

1. Set your locale in wp-config.php: `define('WPLANG', 'de_DE');`
2. Or install the WordPress Language Pack for German
3. Visit the License Server admin page — all translated strings should appear in German
4. For JS translations, verify the JSON translation file loaded:
   - Open browser DevTools → Network tab → filter for `wp-license-server`
   - Confirm `wp-license-server-de_DE-*.json` was loaded from `wp-content/languages/plugins/`
5. Run `npm run build` after updating translations to include the latest .mo in the build
6. Verify no empty strings appear in the .po file: `grep -n '^msgstr ""$' languages/wp-license-server-de_DE.po | head -20`

### Adding a New Language
1. Copy `languages/wp-license-server.pot` to `languages/wp-license-server-{locale}.po`
2. Translate all `msgstr` entries
3. Compile with `msgfmt -o languages/wp-license-server-{locale}.mo languages/wp-license-server-{locale}.po`
4. For JS translations, use `wp i18n make-json languages/wp-license-server-{locale}.po --no-purge`

---

## Security Notes

- `WPLICENSE_ENCRYPTION_KEY` **must** be set in `wp-config.php` before activation. The plugin will throw a `RuntimeException` at boot if the constant is missing.
- The encryption key must be a base64-encoded 32-byte value. Generate one with: `php -r "echo base64_encode(random_bytes(32));"`
- HMAC signatures are verified on every public REST endpoint via `HmacVerifier`. Replay attacks are blocked using nonce tracking.
- License keys are encrypted at rest with `sodium_crypto_secretbox` (XSalsa20-Poly1305).
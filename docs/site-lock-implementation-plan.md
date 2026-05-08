# Site Lock Feature — Implementation Plan

## Overview

Add a **"Lock Site"** button to the license server admin interface that remotely locks a customer's website (both admin area and frontend) by sending a webhook to the client plugin. This is designed as the final enforcement step when a customer does not pay.

### Repositories Involved

| Repo | Role |
|------|------|
| `wp-license-server` (this repo) | Adds Lock/Unlock buttons in admin UI, sends `license.locked` / `license.unlocked` webhook events |
| `wp-custom-dashboard` (client) | Receives the webhook, sets a persistent lockout option, blocks all non-essential requests with a branded lock screen |

---

## Part 1: wp-license-server (Server)

### 1.1 Database Migration

**File:** `app/Database/Schema.php`

Add a `locked_at` column to the `license_keys` table:

```sql
locked_at DATETIME NULL,
```

Add an index:

```sql
KEY locked_at (locked_at),
```

**File:** `app/Database/Migrator.php`

Add migration version `2` that runs `ALTER TABLE {$prefix}license_keys ADD COLUMN locked_at DATETIME NULL AFTER updated_at;`

Update the `DB_VERSION` constant (currently `1` → `2`).

### 1.2 Error Codes

**File:** `app/ErrorCodes.php`

Add two new cases to the enum:

```php
case SITE_LOCK_FAILED   = 'site_lock_failed';
case SITE_UNLOCK_FAILED = 'site_unlock_failed';
```

### 1.3 License Model

**File:** `app/Models/License.php`

Add `locked_at` property:

```php
public readonly ?string $locked_at,
```

Update `from_row()`:

```php
locked_at: $row->locked_at ?? null,
```

### 1.4 License Repository

**File:** `app/Repositories/LicenseRepository.php`

Add two methods:

```php
public function set_locked_at(int $license_id): bool;
public function clear_locked_at(int $license_id): bool;
```

Implementation:

- `set_locked_at`: `UPDATE {$wpdb->prefix}license_keys SET locked_at = NOW() WHERE id = %d`
- `clear_locked_at`: `UPDATE {$wpdb->prefix}license_keys SET locked_at = NULL WHERE id = %d`

Both return `(bool) $wpdb->result`.

### 1.5 AdminLicensesController

**File:** `app/Rest/Controllers/AdminLicensesController.php`

Inject `WebhookService` (add to constructor if not already available — check current constructor params).

Add two new methods:

#### `lock_site(WP_REST_Request $request)`

```php
public function lock_site(WP_REST_Request $request): WP_REST_Response|\WP_Error {
    $id = absint($request->get_param('id'));
    if ($id <= 0) {
        return new WP_Error(ErrorCodes::INVALID_LICENSE_ID->value, ..., ['status' => 400]);
    }

    $license = $this->license_repo->find_by_id($id);
    if (is_wp_error($license) || !$license) {
        return new WP_Error(ErrorCodes::LICENSE_NOT_FOUND->value, ..., ['status' => 404]);
    }

    if (!$this->license_repo->set_locked_at($id)) {
        return new WP_Error(ErrorCodes::SITE_LOCK_FAILED->value, ..., ['status' => 500]);
    }

    // Queue webhook to all active activations
    $this->webhook_service->queue_event($id, 'license.locked', [
        'type'   => 'full_site_lock',
        'reason' => sanitize_text_field($request->get_param('reason') ?? 'Administrative lock.'),
    ]);

    // Log activity
    $this->activity_repo->insert([
        'license_id' => $id,
        'action'     => 'site_locked',
        'actor'      => $this->current_actor(),
        'details'    => ['locked_at' => current_time('mysql', true)],
    ]);

    return rest_ensure_response(['locked' => true, 'lockedAt' => current_time('mysql', true)]);
}
```

#### `unlock_site(WP_REST_Request $request)`

```php
public function unlock_site(WP_REST_Request $request): WP_REST_Response|\WP_Error {
    $id = absint($request->get_param('id'));
    // ... validation same as lock_site ...

    if (!$this->license_repo->clear_locked_at($id)) {
        return new WP_Error(ErrorCodes::SITE_UNLOCK_FAILED->value, ..., ['status' => 500]);
    }

    $this->webhook_service->queue_event($id, 'license.unlocked', [
        'type' => 'full_site_unlock',
    ]);

    $this->activity_repo->insert([
        'license_id' => $id,
        'action'     => 'site_unlocked',
        'actor'      => $this->current_actor(),
        'details'    => [],
    ]);

    return rest_ensure_response(['unlocked' => true]);
}
```

Note: If `webhook_service` is not yet in the constructor, add it. Check the current constructor in `AdminLicensesController` — it takes `LicenseRepositoryInterface`, `ActivationRepositoryInterface`, `LicenseService`. Add `WebhookService` as a 4th optional parameter:

```php
public function __construct(
    private readonly LicenseRepositoryInterface $license_repo,
    private readonly ActivationRepositoryInterface $activation_repo,
    private readonly LicenseService $license_service,
    private readonly ?WebhookService $webhook_service = null,
) {}
```

#### Update `map_license()`

Add `lockedAt` to the returned array:

```php
'lockedAt' => $license->locked_at,
```

#### Add `current_actor()` helper

If not already present, add:

```php
private function current_actor(): string {
    $actor = 'system';
    $user = wp_get_current_user();
    if ($user->exists()) {
        $actor = 'admin:' . $user->user_login;
    }
    return $actor;
}
```

### 1.6 REST API Routes

**File:** `app/Rest/RestApi.php`

Add two new routes alongside the existing `/admin/licenses/{id}/deactivate-all` route:

```php
register_rest_route(self::NAMESPACE, '/admin/licenses/(?P<id>\d+)/lock-site', [
    'methods'             => 'POST',
    'callback'            => [$admin, 'lock_site'],
    'permission_callback' => [$admin, 'can_manage_options'],
]);

register_rest_route(self::NAMESPACE, '/admin/licenses/(?P<id>\d+)/unlock-site', [
    'methods'             => 'POST',
    'callback'            => [$admin, 'unlock_site'],
    'permission_callback' => [$admin, 'can_manage_options'],
]);
```

### 1.7 Frontend — Types

**File:** `src/admin/types.ts`

Add `lockedAt` to the `License` interface:

```typescript
export interface License {
  // ... existing fields ...
  lockedAt: string | null;
}
```

### 1.8 Frontend — LicensesPage.tsx

**File:** `src/admin/pages/LicensesPage.tsx`

#### Add import:
```typescript
import { LockOutlined, UnlockOutlined } from '@ant-design/icons';
```

#### Add new column "Locked" between Status and Activations:

```typescript
{
  title: __('Locked', 'wp-license-server'),
  key: 'locked',
  render: (_: unknown, r: License) =>
    r.lockedAt ? (
      <Tag color="error" icon={<LockOutlined />}>
        {__('Locked', 'wp-license-server')}
      </Tag>
    ) : (
      <Tag>{__('No', 'wp-license-server')}</Tag>
    ),
},
```

#### Add "Lock Site" / "Unlock Site" buttons in the Actions column:

Between the "Deactivate All" button and "Delete" button, add:

```typescript
{r.lockedAt ? (
  <Tooltip title={__('Unlock site', 'wp-license-server')}>
    <Button
      size="middle"
      icon={<UnlockOutlined />}
      onClick={() => onUnlockSite(r.id)}
    >
      {__('Unlock', 'wp-license-server')}
    </Button>
  </Tooltip>
) : (
  <Tooltip title={__('Lock site — blocks admin & frontend', 'wp-license-server')}>
    <Button
      size="middle"
      danger
      icon={<LockOutlined />}
      onClick={() => onLockSite(r.id)}
    >
      {__('Lock', 'wp-license-server')}
    </Button>
  </Tooltip>
)}
```

#### Add handler props to `LicensesTable`:

```typescript
interface LicenseTableProps {
  // ... existing props ...
  onLockSite: (id: number) => void;
  onUnlockSite: (id: number) => void;
}
```

#### Add handlers in `LicensesPage`:

```typescript
const handleLockSite = useCallback(
  (id: number) => {
    setConfirmOverlayCount(count => count + 1);
    modal.confirm({
      title: __('Lock this site?', 'wp-license-server'),
      content: __(
        'The customer\'s site will be completely locked — admin area and frontend. This action sends a webhook to all active domains.',
        'wp-license-server',
      ),
      okText: __('Lock Site', 'wp-license-server'),
      okButtonProps: { danger: true },
      getContainer: getOverlayContainer,
      afterClose: markConfirmOverlayClosed,
      onOk: async () => {
        try {
          await apiFetch(`/licenses/${id}/lock-site`, { method: 'POST' });
          showSuccessNotification(notification, {
            message: __('Lock webhook dispatched', 'wp-license-server'),
          });
          void fetchLicenses();
        } catch (err) {
          showErrorNotification(notification, {
            message: __('Could not lock site', 'wp-license-server'),
            description: err instanceof Error ? err.message : undefined,
          });
          throw err;
        }
      },
    });
  },
  [modal, notification, fetchLicenses, markConfirmOverlayClosed],
);

const handleUnlockSite = useCallback(
  (id: number) => {
    setConfirmOverlayCount(count => count + 1);
    modal.confirm({
      title: __('Unlock this site?', 'wp-license-server'),
      content: __(
        'The customer\'s site will be restored — admin area and frontend will work again.',
        'wp-license-server',
      ),
      okText: __('Unlock Site', 'wp-license-server'),
      getContainer: getOverlayContainer,
      afterClose: markConfirmOverlayClosed,
      onOk: async () => {
        try {
          await apiFetch(`/licenses/${id}/unlock-site`, { method: 'POST' });
          showSuccessNotification(notification, {
            message: __('Unlock webhook dispatched', 'wp-license-server'),
          });
          void fetchLicenses();
        } catch (err) {
          showErrorNotification(notification, {
            message: __('Could not unlock site', 'wp-license-server'),
            description: err instanceof Error ? err.message : undefined,
          });
          throw err;
        }
      },
    });
  },
  [modal, notification, fetchLicenses, markConfirmOverlayClosed],
);
```

Wire them into the `LicensesTable` component:

```tsx
<LicensesTable
  // ... existing props ...
  onLockSite={handleLockSite}
  onUnlockSite={handleUnlockSite}
/>
```

---

## Part 2: wp-custom-dashboard (Client)

### 2.1 New File: `app/License/SiteLockout.php`

Create a new class responsible for the lock check and branded lock screen.

```php
<?php
declare(strict_types=1);

namespace WpReactUi\License;

defined('ABSPATH') || exit;

final class SiteLockout {

    public const LOCKOUT_OPTION = 'wp_react_ui_site_lockout';

    /**
     * Hooked into 'init' at priority 1.
     * Checks if the site is locked and blocks access if so.
     */
    public function check_lockout(): void {
        if (!$this->is_locked()) {
            return;
        }

        if ($this->should_bypass()) {
            return;
        }

        $lock_screen = $this->render_lock_screen();
        wp_die(
            $lock_screen,
            __('Site Locked', 'wp-react-ui'),
            ['response' => 503]
        );
    }

    private function is_locked(): bool {
        return (bool) get_option(self::LOCKOUT_OPTION, false);
    }

    /**
     * Determine if the current request should bypass the lock.
     */
    private function should_bypass(): bool {
        // Never lock the login page — admin must be able to log in
        if ($this->is_login_request()) {
            return true;
        }

        // Never lock REST API — webhook receiver needs to work
        if ($this->is_rest_api_request()) {
            return true;
        }

        // Never lock admin-ajax.php
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }

        // Never lock WP-Cron
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        // Never lock WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return false;
    }

    private function is_login_request(): bool {
        // Check $GLOBALS['pagenow'] or parse the request URI
        if (isset($GLOBALS['pagenow']) && 'wp-login.php' === $GLOBALS['pagenow']) {
            return true;
        }

        $script = sanitize_text_field($_SERVER['SCRIPT_NAME'] ?? '');
        return str_contains($script, 'wp-login.php');
    }

    private function is_rest_api_request(): bool {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        $path = parse_url(sanitize_text_field($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '';
        return str_contains($path, '/wp-json/')
            || str_contains($path, '/rest_route=');
    }

    /**
     * Render the branded lock screen HTML.
     */
    private function render_lock_screen(): string {
        $site_name  = get_bloginfo('name');
        $site_url   = get_bloginfo('url');
        $icon_url   = get_site_icon_url(128);
        $support    = get_option('wp_react_ui_lockout_support_email', '');
        $support    = $support ?: __('your support team', 'wp-react-ui');

        $logo_html = $icon_url
            ? sprintf('<img src="%s" alt="%s" class="wls-lock-icon" width="80" height="80">', esc_url($icon_url), esc_attr($site_name))
            : '<div class="wls-lock-icon wls-lock-icon--fallback">🔒</div>';

        return sprintf(
            '<html><head><title>%s</title><style>
                body {
                    margin: 0; padding: 0;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #f0f2f5;
                    display: flex; align-items: center; justify-content: center;
                    min-height: 100vh; color: #1d2327;
                }
                .wls-container {
                    text-align: center;
                    background: #fff;
                    border-radius: 12px;
                    padding: 60px 40px;
                    max-width: 520px;
                    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
                }
                .wls-lock-icon { margin-bottom: 24px; border-radius: 50%; }
                .wls-lock-icon--fallback { font-size: 64px; }
                .wls-title { font-size: 28px; font-weight: 700; margin: 0 0 12px 0; }
                .wls-message { font-size: 15px; line-height: 1.6; color: #50575e; margin: 0 0 32px 0; }
                .wls-support { font-size: 14px; color: #787c82; }
                .wls-support a { color: #2271b1; text-decoration: none; font-weight: 600; }
                .wls-footer { margin-top: 32px; font-size: 12px; color: #a7aaad; border-top: 1px solid #f0f0f1; padding-top: 24px; }
            </style></head><body>
            <div class="wls-container">
                %s
                <h1 class="wls-title">%s</h1>
                <p class="wls-message">%s</p>
                <p class="wls-support">%s</p>
                <div class="wls-footer">&copy; %s %s</div>
            </div></body></html>',
            esc_html__('Site Locked', 'wp-react-ui'),
            $logo_html,
            esc_html__('This Site Has Been Locked', 'wp-react-ui'),
            esc_html__('This website has been locked due to a license issue. Please contact support to restore access.', 'wp-react-ui'),
            sprintf(
                /* translators: %s: support contact info */
                esc_html__('Please contact %s.', 'wp-react-ui'),
                sprintf('<a href="mailto:%1$s">%1$s</a>', esc_html($support))
            ),
            esc_html(gmdate('Y')),
            esc_html($site_name)
        );
    }
}
```

### 2.2 Handle Webhook Events in LicenseManager

**File:** `app/License/LicenseManager.php`

In `apply_webhook_event()`, add handling for the two new events. Insert before the `unsupported_webhook_event` error return:

```php
if ('license.locked' === $normalized_event) {
    update_option(SiteLockout::LOCKOUT_OPTION, 1);
    $disabled_state = $this->transitioner->transition_to_disabled();
    $this->emit_debug('webhook_locked', [
        'event'     => $normalized_event,
        'keyPrefix' => $disabled_state['keyPrefix'],
    ]);
    return $this->build_public_payload($disabled_state);
}

if ('license.unlocked' === $normalized_event) {
    delete_option(SiteLockout::LOCKOUT_OPTION);
    $disabled_state = $this->transitioner->transition_to_disabled();
    $this->emit_debug('webhook_unlocked', [
        'event'     => $normalized_event,
        'keyPrefix' => $disabled_state['keyPrefix'],
    ]);
    return $this->build_public_payload($disabled_state);
}
```

Add import at top of file (if using namespace):

```php
use WpReactUi\License\SiteLockout;
```

**Important design note:** When `license.unlocked` is received, the license status on the client side stays at `disabled` until the next heartbeat/validate request comes through and re-activates it. This is correct behavior — the webhook just opens the door; the license validation flow determines the working state. Alternatively, if the `license.unlocked` is sent alongside a status change to `active`, the client validation will pick up the new status.

### 2.3 Register Lockout Hook in Bootstrap

**File:** `app/Bootstrap/PluginBootstrap.php`

In the `legacy_init_sequence()` method (or wherever WordPress hooks are registered), add:

```php
$site_lockout = new SiteLockout();
add_action('init', [$site_lockout, 'check_lockout'], 1);
```

This must be added **before** any other init hooks so the lock check runs first.

### 2.4 Verify Webhook Route is Not Blocked

**File:** `app/WordPress/Rest/RestApi.php` (or wherever the webhook receiver route is registered)

The webhook endpoint is registered at `wp-react-ui/v1/license-webhook` (POST). Since `SiteLockout` bypasses REST API requests (`REST_REQUEST` constant or `/wp-json/` in URL), the webhook receiver will still work when the site is locked.

Verify that the webhook listener route is under `/wp-json/` namespace. If it uses `?rest_route=` parameter, the `str_contains($path, '/rest_route=')` check in `should_bypass()` will catch it.

---

## Bypass Rules Summary

When the site is locked, the following are **still accessible**:

| Request Type | Why |
|---|---|
| `wp-login.php` | Admin must log in to contact support / see the lock message |
| `wp-json/*` | Webhook receiver needs to work for unlock webhook |
| `admin-ajax.php` | Background AJAX may be needed |
| WP-Cron | Scheduled tasks still need to run |
| WP-CLI | CLI operations by the site owner |
| REST API (`rest_route=`) | Alternative webhook transport path |

---

## Testing

### Server-side (wp-license-server)

1. Unit test `AdminLicensesController::lock_site()` and `unlock_site()`
2. Test that `license.locked` and `license.unlocked` webhooks are queued
3. Test that `locked_at` is set/cleared in the database
4. Test that non-admin users cannot call these endpoints
5. Test that invalid license IDs return proper errors

### Client-side (wp-custom-dashboard)

1. Unit test `LicenseManager::apply_webhook_event()` with `license.locked` and `license.unlocked`
2. Unit test `SiteLockout::check_lockout()`:
   - Lock enabled → `wp_die()` is called
   - Lock enabled but on login page → bypass
   - Lock enabled but REST API → bypass
   - Lock disabled → no action
3. Integration test: simulate webhook delivery → verify lockout option is set → verify next request is blocked
4. Integration test: simulate unlock webhook → verify lockout option is cleared → verify access is restored

---

## Migration Note

The `locked_at` column is optional (nullable). Existing licenses will have `locked_at = NULL`, meaning they are not locked. No data migration is needed — just a schema change.

If the `Migrator` class already supported versioned migrations, increment the version and add the ALTER TABLE. If it doesn't, the Schema class should be updated to include the new column in the CREATE TABLE statement, and a one-time ALTER TABLE migration should be added.

---

## Lock Screen Customization

The lock screen support contact can be configured via the `wp_react_ui_lockout_support_email` option. If not set, it falls back to "your support team".

For additional customization (e.g., company name, custom CSS), developers can filter the lock screen output via a WordPress filter (to be added if needed):

```php
apply_filters('wp_react_ui_lockout_screen_html', $html, $context);
```

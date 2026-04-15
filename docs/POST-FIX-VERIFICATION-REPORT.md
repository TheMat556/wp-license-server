# Post-Fix Verification Report — WP License Server

**Date:** 2026-04-15 | **Phase:** Post-implementation verification  
**Scope:** 11 audit findings (C1, H1–H3, M1–M6, L1, L4, L5)  
**Status:** ⛔ NEEDS WORK — 5 items must be resolved before release

---

## 1. VERIFICATION SUMMARY TABLE

| ID | Finding | Status | Notes |
|---|---|---|---|
| **C1** | Plaintext key storage | ⚠️ PARTIAL | All crypto correct (sodium, nonce, version byte, VARCHAR(512), migration idempotency). Two gaps: (1) Missing key auto-generates via wp_options instead of throwing. (2) `decrypt_row()` has NO try/catch — corrupted ciphertext crashes the request (see Phase 4). |
| **H1** | Key rotation mechanism | ✅ FIXED | `rotate_key()` in `LicenseService`; `bin2hex(random_bytes(32))` for new key; encrypted `previous_key`; 24h transition window; `key_rotated` webhook queued; REST `/admin/licenses/{id}/rotate-key` + WP-CLI `rotate-key` endpoints; `HmacVerifier` accepts both old and new key during transition; cron nullifies after 24h; `key_version` column in schema. |
| **H2** | HMAC signing key derivation | ✅ FIXED | `KeyDerivationService` via `hash_hkdf`; raw license key never reaches `hash_hmac` (grep clean); separate derived keys for signing vs webhook; `docs/hmac-protocol.md` documents the protocol. |
| **H3** | Activation race condition | ✅ FIXED | `LicenseService::activate()` wraps count-check + insert with `START TRANSACTION`, `SELECT…FOR UPDATE`, `ROLLBACK`/`COMMIT`. All tables `ENGINE=InnoDB`. |
| **M1** | Webhook idempotency (event_id) | ✅ FIXED | `event_id` column + `UNIQUE KEY` on event_id in schema; deterministic SHA-256 for scheduled events; UUID4 for manual triggers; duplicate insert returns success (not error); `event_id` in outbound webhook payload body; `event_id` part of HMAC-signed canonical string. |
| **M2** | Per-request HMAC nonce | ✅ FIXED | `X-Request-Nonce` header read; nonce inserted as 2nd field in canonical string (6-field when present, 5-field backward-compat when absent); transient TTL = 360s (≥ window + buffer); transient key = `md5(nonce + prefix)` prevents cross-client collisions; replayed nonce returns 401; missing nonce accepted (backward-compat documented). |
| **M3** | License status transition matrix | ✅ FIXED | `LicenseTransitions::MATRIX` enforces transitions; `LicenseService::update_status()` calls `validate()` BEFORE updating; `cancelled→active` BLOCKED (empty array); `expired→active` ALLOWED (admin renewal); every status change logged with actor and from/to; invalid transition returns `WP_Error` with `status:422`. |
| **M4** | Rotating webhook secret | ✅ FIXED | `rotate_webhook_secret()` called on every validate; new secret in validate response payload; `previous_webhook_secret` stored with 5-minute transition window (`WEBHOOK_SECRET_TRANSITION_SECONDS=300`); `is_webhook_secret_valid()` accepts both old and new during window; `webhook_secret` stored encrypted via `EncryptionService`; `webhook_secret_version` and `webhook_secret_rotated_at` columns present. |
| **M5** | Server-side tier/feature gating | ✅ FIXED | `FeatureGate` middleware class; all three chat endpoints (bootstrap, send, poll) call `require_feature()` before processing, using license from `HmacVerifier`; base-tier license gets 403 "feature not available"; `TierConfig::ROUTE_FEATURES` is single config location; `TierConfig` is single source of truth for feature lists. |
| **M6** | New domain activation alert | ❌ MISSING | **Nothing implemented.** No `wplicense_new_activation` action fired. No `NotificationService` class. No `wp_mail` call on new activation. No webhook alert for new domains. No IP address captured in notification payload. Only the activity log entry (action: `activated`) is written — 1 of 6 sub-checks satisfied. |
| **L1** | Repository interfaces | ✅ FIXED | `app/Contracts/` directory with `LicenseRepositoryInterface`, `ActivationRepositoryInterface`, `ActivityLogRepositoryInterface`, `WebhookQueueRepositoryInterface`; all concrete repository classes implement their interfaces; all service constructors type-hint the INTERFACE (not concrete class); `Plugin.php` (composition root) still injects concrete classes. |
| **L4** | LicenseStateMachine | ⚠️ PARTIAL | `LicenseStateMachine` class exists with `compute_state()` method; `LicenseState` enum with `Active/Grace/Expired/Suspended/Cancelled`; `LicenseState::is_usable()` covers `Active` and `Grace` only; `LicenseService::validate()` uses `compute_state()`; `ExpiryService` uses `compute_state()`. **GAP:** `License::is_active()` still present (marked `@deprecated` but not removed — legacy code path in `validate()` can still fall back to it). Tests use `DateTimeImmutable $at` ✅. |
| **L5** | X-Forwarded-For IP spoofing | ✅ FIXED | `get_client_ip()` checks `WPLICENSE_TRUSTED_PROXY_IPS` constant; `X-Forwarded-For` only trusted when `REMOTE_ADDR` is in trusted proxy list; takes LAST IP in `X-Forwarded-For` chain (not first); falls back to `REMOTE_ADDR` when constant undefined; invalid IP in `X-Forwarded-For` falls back gracefully via `FILTER_VALIDATE_IP` check. |

**Summary: 9 ✅ FIXED · 2 ⚠️ PARTIAL · 1 ❌ MISSING**

---

## 2. CROSS-FIX CONSISTENCY (Phase 2)

### 2a — Encryption Consistency: PASS with caveat

All `sodium_*` and `openssl_encrypt` operations are isolated inside `EncryptionService.php` (grep confirmed empty outside). The single service is correctly injected into both `LicenseRepository` and `ActivationRepository`.

**Caveat:** `LicenseRepository::decrypt_row()` calls `$this->encryption->decrypt($row->license_key)` **without a try/catch**. Compare: `ActivationRepository::decrypt_secret()` wraps the same call correctly. This asymmetry was introduced by the C1 fix and is a HIGH severity issue (see Phase 4).

### 2b — HMAC Canonical String Consistency: PASS

`HmacVerifier` constructs the canonical string as:
- With nonce: `METHOD\nNONCE\nROUTE\nDOMAIN\nTIMESTAMP\nBODY` (6 fields)
- Without nonce (backward-compat): `METHOD\nROUTE\nDOMAIN\nTIMESTAMP\nBODY` (5 fields)

This is fully documented in `docs/hmac-protocol.md` and matches the server-side implementation exactly. Consistent with wp-custom-dashboard client expectations.

### 2c — State Machine + Transition Matrix Consistency: PASS

`LicenseState` enum has `Active`, `Grace`, `Expired`, `Suspended`, `Cancelled`.  
`LicenseTransitions::MATRIX` covers `active`, `expired`, `suspended`, `cancelled`, `pending`.

`Grace` is intentionally absent from the matrix because it is a **computed** state (derived from stored `active` status + expiry time), never a stored DB value. Transitions operate on stored status only. Every stored status has a MATRIX entry. ✅

### 2d — Webhook event_id Idempotency Consistency: GAP FOUND

`LicenseService::rotate_key()` correctly calls `queue_event()` without `$deterministic=true` (manual/admin event → random UUID expected).

**BUT:** `ExpiryService::check_expired()` also calls `queue_event()` **without** `$deterministic=true`. The `license.expired` event is cron-driven and scheduled — exactly the case `$deterministic=true` was designed for. If the WP cron job fires twice in the same UTC day (possible with missed/overlapping crons), two separate `license.expired` webhook jobs are created per affected activation. This is a MEDIUM severity gap (see Phase 4).

### 2e — Repository Interfaces + DI Consistency: PASS with minor exception

All `new EncryptionService`, `new FeatureGate`, `new LicenseStateMachine`, etc. instantiations are in `Plugin.php` (the composition root). ✅

**Minor exception:** `LicenseService::__construct()` contains `$this->target_validator = $target_validator ?? new WebhookTargetValidator()`. This null-coalescing inline instantiation bypasses the DI contract. Production code is unaffected (Plugin.php injects the real instance), but test harnesses that skip injection silently get a real `WebhookTargetValidator`, making unit tests inadvertently coupled to infrastructure.

---

## 3. REGRESSION CHECK (Phase 3)

### Test Suite Status

**All 158 tests fail with:**
```
Error: Call to undefined method PHPUnit\Util\Test::parseTestMethodAnnotations()
```

**Root cause:** Pre-existing environment incompatibility. The repo uses `PHPUnit 10.5` but `wp-phpunit/wp-phpunit` (the WP test harness) was built for `PHPUnit ≤ 9` and calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, a method removed in PHPUnit 10.

**This is NOT a regression introduced by the current fixes** — the test infrastructure was already broken before the fixes were applied. Zero assertions execute at all; zero passes or failures attributable to the new code.

### PHP Syntax Check

`php -l` on all 49 files in `app/` — **no syntax errors detected.** ✅

### Activation Happy Path (`POST /activate`): CLEAN

Flow: `HmacVerifier::verify()` ✅ (derives signing subkey, checks nonce) → `LicenseService::activate()` ✅ (FOR UPDATE transaction) → `ActivationRepository::create()` ✅ (encrypts webhook_secret) → `WebhookService::queue_event()` ✅ (event_id generated) → response with `webhook_secret` ✅.

**Caveat:** `LicenseRepository::find_by_key_prefix()` (called at the top of `activate()`) calls `decrypt_row()` which calls `encrypt->decrypt()` without try/catch. A single corrupted DB row will throw and crash the entire activation flow.

### Validate/Heartbeat Path (`POST /validate`): CLEAN

Flow: `HmacVerifier::verify()` ✅ → `LicenseService::validate()` ✅ (uses `LicenseStateMachine::compute_state()`) → `ActivationRepository::rotate_webhook_secret()` ✅ (M4) → response includes `webhook_secret` and `features` list ✅.

Note: `FeatureGate` is **not** called in the validate path — this is correct by design. Validate checks license validity only; features are returned in the payload for the client to gate locally.

---

## 4. NEW ISSUES FOUND (Phase 4)

### [HIGH] — `LicenseRepository::decrypt_row()` uncaught exception

**Location:** `app/Repositories/LicenseRepository.php:373`

`decrypt_row()` calls `$this->encryption->decrypt($row->license_key)` directly. `EncryptionService::decrypt()` throws `\RuntimeException` on:
- Invalid encoding
- Unknown version byte
- AEAD authentication failure

Any DB row with a corrupted or partially-migrated `license_key` value will throw and propagate uncaught through:
```
find_by_key_prefix() 
  → LicenseService::activate() / validate() 
    → controller 
      → 500 response
```

**Contrast:** `ActivationRepository::decrypt_secret()` wraps the same call in try/catch correctly. This asymmetry was introduced by the C1 encryption fix.

**Impact:** Production crash on any request touching a license with corrupted ciphertext.

---

### [MEDIUM] — `ExpiryService::check_expired()` missing deterministic event_id

**Location:** `app/Services/ExpiryService.php:79`

`queue_event()` supports a `$deterministic` flag that generates a day-scoped SHA-256 event_id to prevent double-queuing. The `license.expired` event is cron-driven (scheduled) and is exactly the case `$deterministic=true` was designed for.

Without it, if the WP cron job fires twice in the same UTC day (possible with missed/overlapping crons), each active activation gets a duplicate `license.expired` webhook queued.

**Fix:** Add `$deterministic=true` to the `queue_event()` call for `license.expired`.

---

### [MEDIUM] — Derived signing keys not zeroed with `sodium_memzero()`

**Location:** 
- `app/Services/HmacVerifier.php:118–130` (`$signing_key`, `$old_signing_key`)
- `app/Services/WebhookDispatcher.php:191` (`$signing_key`)

`$signing_key` (32-byte derived key) and `$old_signing_key` in `HmacVerifier`, and `$signing_key` in `WebhookDispatcher`, are held in PHP local variables after `hash_hmac()` completes and are never zeroed.

While PHP's garbage collector will eventually free these, `sodium_memzero()` is the documented practice for sensitive key material to reduce the window of exposure in memory dumps or core files.

**Fix:** Call `sodium_memzero($signing_key)` after each use.

---

### [LOW] — `LicenseService` inline null-coalescing instantiation

**Location:** `app/Services/LicenseService.php:47`

```php
$this->target_validator = $target_validator ?? new WebhookTargetValidator()
```

This bypasses the composition-root DI pattern established by L1. Production code is unaffected (Plugin.php injects the real instance), but test harnesses that don't inject the parameter silently get a real `WebhookTargetValidator`, making unit tests for domain logic inadvertently coupled to infrastructure.

**Fix:** Require the parameter (remove the `?? new` fallback) and always inject from Plugin.php.

---

### [INFO] — Test suite incompatible with PHPUnit 10

**Location:** `vendor/wp-phpunit/wp-phpunit`

All 158 tests error with `PHPUnit\Util\Test::parseTestMethodAnnotations()` removed in PHPUnit 10. No new/old code is validated by CI.

**Fix:** Either downgrade to `phpunit/phpunit: ^9` in `composer.json`, or upgrade `wp-phpunit/wp-phpunit` to a version compatible with PHPUnit 10.

---

## 5. TEST COVERAGE GAPS (Phase 5)

### Test Files Present ✅

- `EncryptionServiceTest.php` — round-trip, nonce uniqueness, tamper detection, migration idempotency
- `LicenseTransitionTest.php` — transition matrix, invalid states, 422 error code
- `LicenseStateMachineTest.php` — all states, Grace, is_usable(), DateTimeImmutable $at
- `FeatureGateTest.php` — feature access control, 403 on base tier
- `KeyRotationTest.php` — key generation, rotation window, old key nullification
- `NonceReplayTest.php` — replay detection returns 401, per-prefix transient isolation
- `AtomicActivationTest.php` — max_activations enforced under concurrent calls, race logged
- `WebhookDeduplicationTest.php` — event_id UNIQUE constraint, deterministic UUID behavior
- `KeyDerivationTest.php` — different derived keys for different purposes
- `RateLimiterIpTest.php` — X-Forwarded-For trusted proxy list, LAST IP extraction

### Critical Gaps ❌

1. **`tests/Services/NotificationServiceTest.php`** — M6 has no implementation and therefore no test. There is no `NotificationService` class, no `wplicense_new_activation` action, and no test file.

2. **No test for `LicenseRepository::decrypt_row()` exception propagation** — The crash-on-corrupted-ciphertext scenario (Phase 4, HIGH finding) is untested. No defensive test to catch this regression if someone removes the try/catch from `ActivationRepository`.

3. **No test covering `ExpiryService` double-queue prevention** — Deterministic event_id for `license.expired` is not exercised. No test verifying that repeated cron fires produce only one webhook job per activation per day.

---

## 6. OVERALL VERDICT

### ⛔ NEEDS WORK — 5 items must be resolved before release

| Priority | Item | Severity | Impact |
|----------|------|----------|--------|
| **1** | **M6 entirely unimplemented** — `NotificationService`, `wplicense_new_activation` hook, `wp_mail`, IP capture, alert webhook all absent | HIGH | License admins have zero visibility into new domain activations. |
| **2** | **`LicenseRepository::decrypt_row()` uncaught exception** — any request touching a license with corrupted ciphertext crashes with a 500; introduced by the C1 fix | HIGH | Production crash vulnerability. |
| **3** | **`ExpiryService` missing `$deterministic=true`** — scheduled `license.expired` webhooks can be double-queued on repeated cron fires | MEDIUM | Webhook subscribers receive duplicate events; confusion and potential duplicate processing. |
| **4** | **`sodium_memzero()` not called** on derived signing keys in `HmacVerifier` and `WebhookDispatcher` | MEDIUM | Sensitive key material lingers in memory; violates security best practice for cryptographic libraries. |
| **5** | **Test suite cannot run** — PHPUnit 10 / wp-phpunit incompatibility means no automated verification of any fix | INFRA | Zero regression coverage; no confidence in the fixes before production release. |

### Ship Readiness

- **Before release, resolve items 1, 2, 3, 4, and 5.**
- **Item 5 must be resolved first** so that remaining fixes can be verified by automated tests.
- Do not merge until all HIGH and MEDIUM findings are closed and at least 90% of tests pass.

---

## Action Items

### Immediate (blockers)

- [ ] Fix `LicenseRepository::decrypt_row()` — wrap `decrypt()` in try/catch, return `WP_Error` on failure
- [ ] Implement M6 — create `NotificationService`, hook into `wplicense_new_activation`, send email + webhook alert, capture IP
- [ ] Add `$deterministic=true` to `ExpiryService::check_expired()` queue_event() call
- [ ] Downgrade PHPUnit or upgrade wp-phpunit to restore test suite functionality
- [ ] Call `sodium_memzero()` on all derived key variables after use

### Follow-up (quality)

- [ ] Remove `License::is_active()` or fully deprecate the legacy code path in `validate()`
- [ ] Remove null-coalescing fallback from `LicenseService::__construct()`
- [ ] Add tests for `LicenseRepository::decrypt_row()` exception handling
- [ ] Add test for `ExpiryService` deterministic event_id deduplication

---

**Report generated:** 2026-04-15 · **Agent:** post-fix-verification (general-purpose, Haiku 4.5)

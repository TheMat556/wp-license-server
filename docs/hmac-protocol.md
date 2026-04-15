# HMAC Signing Protocol — wplicense v1

This document is the **authoritative specification** for how clients must sign
API requests and how clients must verify inbound webhooks from the license server.
Any client implementation must follow this protocol exactly to interoperate.

---

## 1. Key Derivation (NIST SP 800-57 key separation)

The raw license key is **never** used directly as an HMAC secret. A
purpose-scoped sub-key is derived via HKDF-SHA256 before each signing or
verification operation.

```
signing_key = HKDF-SHA256(
    ikm  = license_key,          # 64-char hex string, used as raw bytes
    len  = 32,
    info = "wplicense-hmac-signing-v1",
    salt = ""                     # no salt — the license key provides sufficient entropy
)

webhook_key = HKDF-SHA256(
    ikm  = license_key,
    len  = 32,
    info = "wplicense-webhook-dispatch-v1",
    salt = ""
)
```

**PHP:**
```php
$signing_key = hash_hkdf('sha256', $license_key, 32, 'wplicense-hmac-signing-v1');
$webhook_key = hash_hkdf('sha256', $license_key, 32, 'wplicense-webhook-dispatch-v1');
```

**Node.js:**
```js
const { createHmac, hkdfSync } = require('crypto');
const signingKey = hkdfSync('sha256', Buffer.from(licenseKey), '', 'wplicense-hmac-signing-v1', 32);
const webhookKey = hkdfSync('sha256', Buffer.from(licenseKey), '', 'wplicense-webhook-dispatch-v1', 32);
```

### Why key separation matters

| Scenario | Without separation | With separation |
|---|---|---|
| Signing key leaked | Attacker can sign API requests AND verify/forge webhooks | Attacker can only sign API requests |
| Webhook key leaked | Same | Attacker can only forge webhooks |

---

## 2. API Request Signing (client → server)

### Required headers

| Header | Value |
|---|---|
| `X-License-Key-Id` | First 8 characters of the raw license key (prefix for DB lookup) |
| `X-License-Domain` | Registered domain of the client WordPress site |
| `X-License-Timestamp` | Unix timestamp (seconds) as a string |
| `X-License-Signature` | HMAC-SHA256 hex string (see below) |
| `X-Request-Nonce` | 32 hex chars (128-bit random nonce — **required for new clients**) |

### Canonical string

**With nonce (current — all new clients must use this):**

```
{METHOD}\n{NONCE}\n{ROUTE_PATH}\n{DOMAIN}\n{TIMESTAMP}\n{RAW_BODY}
```

**Without nonce (legacy — accepted during migration window only):**

```
{METHOD}\n{ROUTE_PATH}\n{DOMAIN}\n{TIMESTAMP}\n{RAW_BODY}
```

- `{METHOD}` — HTTP method in uppercase, e.g. `POST`
- `{NONCE}` — Value sent in `X-Request-Nonce` (32 hex chars, freshly generated per request)
- `{ROUTE_PATH}` — Full REST route path, e.g. `/license-server/v1/validate`
- `{DOMAIN}` — Value sent in `X-License-Domain`
- `{TIMESTAMP}` — Value sent in `X-License-Timestamp`
- `{RAW_BODY}` — Raw request body bytes, empty string if no body

### Nonce requirements

- Generated fresh per request: `bin2hex(random_bytes(16))` — 32 hex chars, 128 bits entropy
- **Never reuse** a nonce. The server stores used nonces for 360 seconds (clock-skew window + 60s buffer).
- A replayed request (same nonce within TTL) is rejected with `401 replay_detected`.
- Nonce transients are keyed as `wplicense_nonce_{md5(nonce . key_prefix)}` to prevent cross-client collisions.

### Signature computation

```
nonce        = bin2hex(random_bytes(16))
canonical    = METHOD + "\n" + NONCE + "\n" + ROUTE + "\n" + DOMAIN + "\n" + TIMESTAMP + "\n" + BODY
signing_key  = hkdf_sha256(license_key, 32, "wplicense-hmac-signing-v1")
signature    = hmac_sha256(canonical, signing_key)   # hex-encoded
```

### Clock skew tolerance

The server rejects requests where `|server_time - timestamp| > 300 seconds` (5 minutes).
Client clocks must be reasonably synchronised (NTP).

### PHP example

```php
$nonce       = bin2hex(random_bytes(16));
$canonical   = implode("\n", ['POST', $nonce, $route, $domain, $timestamp, $body]);
$signing_key = hash_hkdf('sha256', $license_key, 32, 'wplicense-hmac-signing-v1');
$signature   = hash_hmac('sha256', $canonical, $signing_key);
// Headers: X-Request-Nonce: $nonce, X-License-Signature: $signature
```

---

## 3. Webhook Signing (server → client)

### Outbound payload (JSON)

```json
{
  "event":              "license.renewed",
  "event_id":           "a1b2c3d4e5f6...",
  "license_key_prefix": "abcd1234",
  "timestamp":          "1712345678",
  "data":               { ... },
  "signature":          "hex-encoded-hmac-sha256"
}
```

The `event_id` field enables idempotent delivery:
- **Deterministic events** (e.g. `license.expired`): `sha256(event_type | license_id | domain | date)` — same event on the same day always has the same ID.
- **Manual admin events** (e.g. `license.key_rotated`): `wp_generate_uuid4()` — unique per action.

Client webhook receivers should store processed `event_id` values and skip redeliveries.

### Canonical string for webhook

```
{EVENT}\n{EVENT_ID}\n{LICENSE_KEY_PREFIX}\n{TIMESTAMP}\n{DATA_JSON}
```

- `{EVENT_ID}` — value of the `event_id` field in the payload
- `{DATA_JSON}` — `wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`

**Backward-compat:** if `event_id` is absent (pre-M1 server), fall back to the 4-field canonical `{EVENT}\n{KEY_PREFIX}\n{TIMESTAMP}\n{DATA_JSON}`.

### Signature computation (server-side)

```
webhook_key = hkdf_sha256(license_key, 32, "wplicense-webhook-dispatch-v1")
canonical   = event + "\n" + event_id + "\n" + key_prefix + "\n" + timestamp + "\n" + json_encode(data)
signature   = hmac_sha256(canonical, webhook_key)   # hex-encoded
```

### Verification (client-side)

```php
$webhook_key = hash_hkdf('sha256', $stored_license_key, 32, 'wplicense-webhook-dispatch-v1');
$event_id    = $payload['event_id'] ?? '';
$fields      = $event_id !== ''
    ? [$event, $event_id, $key_prefix, $timestamp, $data_json]
    : [$event, $key_prefix, $timestamp, $data_json];  // backward-compat
$canonical   = implode("\n", $fields);
$expected    = hash_hmac('sha256', $canonical, $webhook_key);
$valid       = hash_equals($expected, $received_signature);
```

**Important:** Always use `hash_equals()`, never `===`, to prevent timing attacks.

---

## 4. Key rotation transition

When a license key is rotated, the server accepts signatures from **both** the
old and new key for a **24-hour transition window**. Clients that receive a
`license.key_rotated` webhook should update their stored key immediately.

After 24 hours the old key is invalidated and only the new key's derived signing
key is accepted.

---

## 5. Version history

| Version | Change |
|---|---|
| `v1` | Initial protocol: HKDF-SHA256 with purpose-scoped info strings |
| `v1.1` | M1: `event_id` added to webhook canonical string and payload |
| `v1.2` | M2: `X-Request-Nonce` added to API request canonical string; replay prevention via transients |
| `v1.3` | M4: `webhook_secret` rotated on every heartbeat; secrets encrypted at rest |

**Changing the `info` strings is a breaking change** — both server and all
client plugins must be deployed together.

### Migration notes

- **M1 (event_id):** Server sends `event_id`. Clients that include it in the canonical string must be
  deployed alongside a server version that includes it. Backward-compat: clients tolerate absent `event_id`.
- **M2 (nonce):** Deploy server first — it accepts requests with or without `X-Request-Nonce`.
  Then update all clients to send a nonce. Once all clients are updated, the server can enforce the nonce.
- **M4 (webhook secret rotation):** Every `validate` (heartbeat) response now includes a **new**
  `webhook_secret`. Clients MUST persist it and replace their stored value. The server keeps the
  *previous* secret for a **5-minute transition window** so in-flight webhooks dispatched just before
  the rotation can still be verified. Client receiver logic:

  ```php
  $current  = get_option('wplicense_webhook_secret');
  $previous = get_option('wplicense_previous_webhook_secret');
  $rotated_at = (int) get_option('wplicense_webhook_secret_rotated_at', 0);
  $window_end = $rotated_at + 300;

  $secret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

  $valid = ($current !== '' && hash_equals($current, $secret))
        || ($previous !== '' && time() <= $window_end && hash_equals($previous, $secret));
  ```

  After receiving a new secret from the heartbeat response, rotate local storage:
  ```php
  update_option('wplicense_previous_webhook_secret', $current);
  update_option('wplicense_previous_webhook_secret_rotated_at', time());
  update_option('wplicense_webhook_secret', $new_secret_from_heartbeat);
  ```

---

## 6. Webhook Secret Rotation Protocol (M4)

### Server-side behaviour

| Step | Action |
|------|--------|
| Heartbeat received | `LicenseService::validate()` called |
| Secret rotation | `ActivationRepository::rotate_webhook_secret($id, $new_secret)` |
| DB update | Old encrypted secret → `previous_webhook_secret`; new encrypted secret → `webhook_secret`; `webhook_secret_rotated_at` = now; `webhook_secret_version` incremented |
| Response | New **plaintext** secret returned as `webhook_secret` in validate response |

### Client-side requirements

1. On every successful heartbeat, overwrite the stored `webhook_secret` with the value from the response.
2. Keep the old secret as `previous_webhook_secret` with a timestamp.
3. Accept `X-Webhook-Secret` matching either the current or previous secret for 300 seconds after rotation.
4. After 300 seconds, reject any request signed with the previous secret.

### Security properties

- A stolen secret from a captured heartbeat response is valid for at most one heartbeat interval.
- DB compromise exposes only encrypted ciphertext; the plaintext is not recoverable without the master key (`WPLICENSE_ENCRYPTION_KEY`).
- Version counter (`webhook_secret_version`) allows detecting missed rotations for audit purposes.

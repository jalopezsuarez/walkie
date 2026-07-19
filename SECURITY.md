# Walkie — Security model

One security methodology applied consistently across the three surfaces
([API](api/README.md), [web](web/README.md), [Android](android/README.md)).
Standards-based, no bespoke crypto, and without adding friction to the
two-tap experience.

## Threat model & principles

- Two paired users exchange ephemeral voice/text. Minimise data, minimise
  lifetime, minimise trust.
- **Defence in depth**: transport, headers, auth, at-rest encryption, input
  validation, rate limiting — each independent.
- **Least data**: messages auto-expire (audio 1 h, text 24 h); only SHA-256
  hashes of secrets are stored; no analytics, no third-party scripts.

## Authentication & authorization — OAuth 2.0 (single path)

- Passwordless: email → single-use 6-digit code (5-min TTL, max 5 tries).
- Tokens issued **only** via the OAuth 2.0 token endpoint (`POST /oauth/token`,
  RFC 6749): the email-code extension grant (§4.5) and `refresh_token` (§6).
- **Access token = JWT** (RFC 7519, HS256) with `iss`, `aud`, `sub`, `iat`,
  `exp`, `scope`, `jti`; verified by signature **and** issuer/audience/expiry.
  No server-side session state.
- **Refresh tokens** are opaque, stored as SHA-256 hashes, **rotated on every
  use**, and revocable (`POST /oauth/revoke`, RFC 7009).
- Bearer usage per RFC 6750. Short access lifetime (1 h) limits exposure.

## Transport

- HTTPS everywhere. **HSTS** (`max-age=63072000; includeSubDomains; preload`)
  on API and web.
- Android: `network_security_config` with `cleartextTrafficPermitted="false"`
  and `usesCleartextTraffic="false"` — the app refuses plaintext HTTP.

## HTTP security headers

| Header | API | Web |
|---|---|---|
| `Strict-Transport-Security` | ✓ | ✓ |
| `X-Content-Type-Options: nosniff` | ✓ | ✓ |
| `X-Frame-Options: DENY` / `frame-ancestors 'none'` | ✓ | ✓ |
| `Referrer-Policy: no-referrer` | ✓ | ✓ |
| `Content-Security-Policy` | `default-src 'none'` (JSON API) | `script-src 'self'`, `object-src 'none'`, `base-uri 'none'`, `form-action 'none'`, `upgrade-insecure-requests` |
| `Permissions-Policy` | ✓ | ✓ |
| `Cross-Origin-Resource-Policy` / `X-Permitted-Cross-Domain-Policies` | ✓ | — |

- **CORS** locked to the configured web origin; no credentials/cookies →
  CSRF-safe by design.
- Token responses carry `Cache-Control: no-store` (RFC 6749 §5.1).

## Encryption at rest

- **Messages**: AES-256-GCM, with a **per-conversation key derived via HKDF**;
  the server stores only ciphertext (+ nonce/tag). A single key's blast radius
  is one conversation.
- **Secrets**: login codes, refresh tokens and FCM device tokens stored as
  SHA-256 hashes only.
- **`app.key`** (32 random bytes) signs JWTs and seeds key derivation; kept in
  server config, never in the repo.

## Push notifications (FCM) — data minimisation

- Push is sent server-side via **FCM HTTP v1** (service-account RS256 JWT →
  Google token → `messages:send`); credentials live only in server config.
- Payloads carry **only the sender's display name**, a type marker
  (new message / voice note) and the `link_id` — **never message content**.
  Message bodies remain encrypted at rest and are never placed in a push.

## Input handling & abuse control

- **SQL**: 100 % PDO prepared statements, positional parameters.
- **Validation**: strict server-side validation of every field; upload size
  caps (text 4 KB, audio 2 MB).
- **XSS**: web renders all user text via `textContent`; strict CSP blocks
  inline/injected script. Android renders via Compose `Text` (no HTML).
- **Rate limiting**: fixed-window counters per IP, per email and per user;
  global per-IP cap → `429` with `Retry-After`.
- **Constant-time** comparisons for codes/hashes (`hash_equals`).

## Client hardening

- **Web**: no third-party scripts or CDNs (no supply-chain surface); tokens in
  `localStorage` guarded by strict CSP + `textContent` rendering.
- **Android**: `allowBackup="false"` (tokens excluded from device backups);
  HTTPS-only network config; release APK is **signed**; R8/resource shrinking
  on release.

## Build & release integrity

- Android release APKs are **signed with the production keystore**, loaded from
  the repository CI secrets `ANDROID_KEYSTORE_BASE64` / `…_PASSWORD` /
  `ANDROID_KEY_ALIAS` / `ANDROID_KEY_PASSWORD`. Configured and verified — the
  signer certificate is stable, enabling in-place updates. If the secrets were
  ever missing, the workflow falls back to an ephemeral key and emits a warning;
  the "Verify APK signature" step prints the certificate SHA-256 either way.
  Keystore and passwords are never committed. See
  [android/README](android/README.md#firma-del-apk).
- **Secrets never in the repo:** `api/config/config.php`, the FCM service-account
  key (`api/config/service-account.json`, also blocked by `.htaccess`) and the
  signing keystore are all git-ignored. `android/app/google-services.json` *is*
  committed on purpose — Google classifies it as a client identifier, not a
  secret (it contains no private key; server auth uses the service account).

## Deliberate trade-offs

- **Not end-to-end encrypted.** Login is passwordless and works across devices,
  so per-conversation keys live server-side (encrypted at rest, per-conversation
  derivation). True E2E would require device-held keys and a key-exchange step,
  which conflicts with the “scan a QR, start talking” simplicity. Documented,
  not accidental.
- **Certificate pinning** (Android) is intentionally omitted: it breaks on
  certificate rotation and the HTTPS + HSTS + system-CA trust chain already
  protects transport. It can be added later if the hosting cert lifecycle is
  pinned.

## Reporting

Found something? Open a private security advisory on the repository rather than
a public issue.

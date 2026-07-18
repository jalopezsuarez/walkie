# Walkie

Minimalist web app for exchanging **voice notes and text between two people**.
Two users link **only** by scanning each other's QR code — there is no other way
to connect. Conversations are ephemeral: audio lives at most **1 hour**, text at
most **24 hours**, and any message is hard-deleted the moment the other side
reads it. Everything is encrypted at rest and served over HTTPS.

Black / white / grey, rounded, built for phones and tablets. PHP + MySQL on the
back, vanilla JS/CSS on the front. **No frameworks, no build step, no runtime
dependencies.**

```
walkie.howto.rocks/        → blank page (by design)
walkie.howto.rocks/web     → frontend  (PHP shell + vanilla JS/CSS)
walkie.howto.rocks/api     → backend   (OpenAPI-documented JSON API)
```

The two halves are **fully decoupled**: the frontend only knows the API's URL,
so either side can be replaced without touching the other.

---

## Features

- **Passwordless login** — enter your email, receive a 6-digit code (valid 5
  minutes, sent through an SMTP relay), and you're in. The session is stored in
  the browser; logging in from another browser just repeats the email-code step.
- **QR pairing only** — show your full-screen QR; the other person scans it (or
  opens the link) to link. Either side deleting the conversation unlinks **both**
  users, and re-pairing requires a fresh QR scan.
- **Push-to-talk** — hold the mic button to record, release to preview, then
  send or discard. Text mode is just type-and-send.
- **Sender-controlled deletion** — you can delete your own messages at any time;
  they vanish completely from the database.
- **Automatic retention** — audio 1 h, text 24 h, deleted-on-read, no trace.

## Architecture

Both halves follow **vertical slice architecture**: each feature is a
self-contained unit (endpoint handler + its own data access on the API; screen
+ behaviour on the web), supported by a thin shared kernel. Adding a feature
means adding a folder, not touching layers.

```
walkie/
├── index.php                     # blank root page
├── api/                          # ── Backend ──────────────────────────
│   ├── index.php                 # front controller: headers, routes → slices
│   ├── install.php               # one-shot schema installer (key-protected,
│   │                             #   delete after use)
│   ├── .htaccess                 # routing + hardening
│   ├── openapi.yaml              # API specification
│   ├── config/
│   │   ├── config.sample.php     # copy → config.php and fill in
│   │   └── config.php            # (git-ignored) real secrets
│   ├── migrations/schema.sql     # database schema
│   ├── cron/cleanup.php          # retention job (also runs opportunistically)
│   ├── src/
│   │   ├── Kernel/               # HTTP plumbing: Config, Database, Router,
│   │   │                         #   Request, Response, Validator, errors
│   │   ├── Shared/               # cross-cutting infra: Crypto, Session,
│   │   │                         #   RateLimiter, SmtpClient, Mailer, QrCode
│   │   └── Features/             # one folder per slice, one class per endpoint
│   │       ├── Health/           #   GetHealth
│   │       ├── Auth/             #   RequestLoginCode, VerifyLoginCode, Logout
│   │       ├── Profile/          #   GetProfile, UpdateProfile
│   │       ├── Pairing/          #   CreatePairingQr, ClaimPairing
│   │       ├── Contacts/         #   ListContacts, RemoveContact
│   │       └── Messages/         #   ListMessages, SendMessage, DeleteMessage
│   └── tests/                    # QR verifier + API & browser E2E
└── web/                          # ── Frontend ─────────────────────────
    ├── index.php                 # shell (injects API base, CSP)
    ├── config.php                # api_base + app name
    └── assets/
        ├── css/style.css         # the entire design system
        └── js/
            ├── api.js            # API client
            ├── core.js           # state, DOM helpers, overlay, icons
            ├── qr.js  audio.js   # device capabilities (scan, record)
            ├── features/         # one file per slice
            │   ├── auth.js  contacts.js  chat.js  pairing.js  settings.js
            └── app.js            # boot
```

## API

Documented in [`api/openapi.yaml`](api/openapi.yaml). Bearer-token auth on
everything except `/health` and the two `/auth` entry points.

| Method | Path | Purpose |
|---|---|---|
| GET | `/health` | Liveness probe |
| POST | `/auth/request-code` | Email a 6-digit login code |
| POST | `/auth/verify` | Exchange code for a session token |
| POST | `/auth/logout` | Invalidate the session |
| GET / PATCH | `/me` | Read / update profile (name, email) |
| POST | `/link/qr` | Issue a pairing token + full-screen QR (SVG) |
| POST | `/link/claim` | Consume a scanned token → link two users |
| GET | `/links` | List linked contacts with unread counts |
| DELETE | `/links/{id}` | Unlink (deletes the conversation for both) |
| GET | `/links/{id}/messages` | Fetch new messages (marks incoming read) |
| POST | `/links/{id}/messages` | Send text or audio |
| DELETE | `/links/{id}/messages/{msgId}` | Delete your own message |

## Security

| Concern | Measure |
|---|---|
| Message contents | AES-256-GCM at rest, per-conversation key derived via HKDF; server stores only ciphertext |
| Transport | HTTPS + strict CORS locked to the web origin; bearer-token auth (CSRF-safe) |
| Login codes & tokens | Only SHA-256 hashes stored; codes single-use, 5-min TTL, max 5 tries |
| Email delivery | Native SMTP client (STARTTLS + AUTH LOGIN, RFC 5321); falls back to `mail()`, then to a server-side log |
| Abuse / flooding | Fixed-window rate limits per IP, per email and per user; global per-IP API cap returns `429` with `Retry-After` |
| Injection | 100% PDO prepared statements; strict input validation |
| XSS | Frontend renders all user text via `textContent`; strict CSP (`script-src 'self'`) |
| Headers | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, CSP |
| Data minimisation | Ephemeral messages; opportunistic + cron cleanup purges expired/read rows |

> **Encryption model.** Messages are encrypted at rest and in transit, so the
> database never holds plaintext. Because login is passwordless and works across
> devices, keys are held server-side rather than end-to-end — a deliberate
> trade-off documented here. The per-conversation key derivation keeps the
> blast radius of any single key minimal.

## Requirements

- PHP 8.1+ (PDO MySQL + OpenSSL extensions — standard on shared hosting)
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` (or any server that can emulate the two
  `.htaccess` rewrites)

## Deployment

See **[DEPLOY.md](DEPLOY.md)** for step-by-step InfinityFree instructions.
Fast path:

1. Copy `api/config/config.sample.php` → `api/config/config.php` and fill in
   the DB credentials, the SMTP relay, a fresh `app.key`
   (`php -r "echo base64_encode(random_bytes(32));"`) and a random
   `app.install_key`.
2. Upload `index.php`, `api/` and `web/` into the document root.
3. Visit `https://<host>/api/install.php?key=<app.install_key>` once to create
   the schema, then **delete `api/install.php`**.
4. Check `https://<host>/api/health` and open `https://<host>/web/`.
5. (Optional) Schedule `php api/cron/cleanup.php` every few minutes — retention
   also runs opportunistically on live traffic, so cron is belt-and-braces.

## Local development

```bash
cp api/config/config.sample.php api/config/config.php   # point at a local DB,
                                                         # set debug + log_only
php api/install.php <your-install-key>                   # create the schema
php -S 127.0.0.1:8080 api/tests/router.php               # serves /api and /web
```

With `app.debug = true` the login code is returned in the request-code response
(and written to `api/storage/mail.log`), so no mailbox is needed to develop.

## Notes & troubleshooting

- **Microphone (push-to-talk):** the first time a user holds the mic button
  the browser asks for microphone permission; that first hold only grants
  access — subsequent holds record. Recording needs HTTPS (a secure context).
  Any failure now surfaces a specific on-screen message.
- **Email delivery:** login codes go out through the configured SMTP relay.
  On failure the code is logged to `api/storage/mail.log` so access is never
  lost. Make sure the `mail.from` address is a verified sender in the relay.
- **Shared hosting bot protection (e.g. InfinityFree):** some free hosts put
  a JavaScript anti-bot challenge in front of the site, so plain `curl`/CI
  requests may see a challenge page instead of the app. Real browsers pass it
  transparently.

## Tests

The QR encoder and the whole API/UI were verified against real infrastructure:

```bash
# 1. Pure-PHP QR encoder vs. the reference library (272 cases, byte-identical)
python3 api/tests/qr_verify.py

# 2. Full API flow against a real MySQL (auth, pairing, messaging, security)
php -S 127.0.0.1:8080 api/tests/router.php &
bash api/tests/integration.sh

# 3. Full frontend in a real browser (two users, QR pairing, text + voice)
node api/tests/e2e_browser.js
```

The generated pairing QR was additionally confirmed to decode correctly with an
independent reader (OpenCV `QRCodeDetector`).

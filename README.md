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
walkie.howto.rocks/api     → backend   (OpenAPI-style JSON API)
```

The two halves are **fully decoupled**: the frontend only knows the API's URL,
so either side can be replaced without touching the other.

---

## Features

- **Passwordless login** — enter your email, receive a 6-digit code (valid 5
  minutes), and you're in. The session is stored in the browser; logging in from
  another browser just repeats the email-code step.
- **QR pairing only** — show your full-screen QR; the other person scans it (or
  opens the link) to link. Either side deleting the conversation unlinks **both**
  users, and re-pairing requires a fresh QR scan.
- **Push-to-talk** — hold the mic button to record, release to preview, then
  send or discard. Text mode is just type-and-send.
- **Sender-controlled deletion** — you can delete your own messages at any time;
  they vanish completely from the database.
- **Automatic retention** — audio 1 h, text 24 h, deleted-on-read, no trace.

## Security

| Concern | Measure |
|---|---|
| Message contents | AES-256-GCM at rest, per-conversation key derived via HKDF; server stores only ciphertext |
| Transport | HTTPS + strict CORS locked to the web origin; bearer-token auth (CSRF-safe) |
| Login codes & tokens | Only SHA-256 hashes stored; codes single-use, 5-min TTL, max 5 tries |
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

---

## Project layout

Both halves follow **vertical slice architecture**: each feature is a
self-contained unit (endpoint handler + its own data access on the API;
screen + behaviour on the web), supported by a thin shared kernel.

```
walkie/
├── index.php                     # blank root page
├── api/                          # ── Backend ──────────────────────────
│   ├── index.php                 # front controller: headers, routes → slices
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

## Deployment

See **[DEPLOY.md](DEPLOY.md)** for step-by-step InfinityFree instructions.
Short version:

1. Create the MySQL database and import `api/migrations/schema.sql`.
2. Copy `api/config/config.sample.php` → `api/config/config.php` and fill in the
   DB credentials and a fresh `APP_KEY`
   (`php -r "echo base64_encode(random_bytes(32));"`).
3. Set `web/config.php`'s `api_base` to `https://<host>/api`.
4. Upload `index.php`, `api/` and `web/` into `htdocs/`.
5. (Optional) Schedule `php api/cron/cleanup.php` every few minutes.

## Tests

The QR encoder and the whole API/UI were verified locally:

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

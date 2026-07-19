# Deploying Walkie to InfinityFree

Target host: **walkie.howto.rocks** — document root `htdocs/`.

## Fast path (ZIP + installer)

1. Build (or receive) a deploy ZIP whose top folder is `htdocs/`, containing a
   filled-in `api/config/config.php` (see §2 below).
2. In the InfinityFree **file manager**, upload the ZIP into the account root
   and use **Extract**, so its contents land in `htdocs/`.
3. Visit `https://walkie.howto.rocks/api/install.php?key=<app.install_key>`
   once — it creates the schema (idempotent, key-protected).
4. **Delete `api/install.php`** from the server.
5. Check `https://walkie.howto.rocks/api/health` and open
   `https://walkie.howto.rocks/web/`.

The manual path below does the same thing step by step.

The final layout inside `htdocs/` must be:

```
htdocs/
├── index.php        # blank root page
├── api/             # backend
└── web/             # frontend
```

## 1. Database

In the InfinityFree control panel (MySQL Databases) you already have:

| Field | Value |
|---|---|
| Host | `sql312.infinityfree.com` |
| Port | `3306` |
| Database | `if0_42263887_walkie` |
| User | `if0_42263887` |
| Password | *(your MySQL password)* |

Open **phpMyAdmin** for that database and **import** `api/migrations/schema.sql`
(or run `api/install.php` once — §Fast path). The schema includes `users`,
`login_codes`, `oauth_refresh_tokens`, `pairing_tokens`, `links`, `messages`,
`devices` (FCM tokens) and `rate_limits`.

## 2. Backend config

Copy the sample and fill it in **on the server** (never commit real secrets):

```bash
cp api/config/config.sample.php api/config/config.php
```

Edit `api/config/config.php`:

- `db.pass` → your MySQL password.
- `app.key` → a fresh 32-byte base64 key. Generate one:
  ```bash
  php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
  ```
- `app.debug` → **`false`**.
- `mail.smtp.*` → your SMTP relay (e.g. Brevo: `smtp-relay.brevo.com`, port
  587, security `tls`, plus your SMTP username/password) and `mail.from` →
  the verified sender address.
- `mail.log_only` → `false` (set to `true` only while testing; codes then
  land in `api/storage/mail.log` instead of being emailed).

`app.url` / `app.web_origin` are already set to the production URLs.

### Push notifications (FCM) — optional, for the Android app

To let the API send push on new messages:

1. In Firebase, create a **service-account** key (JSON) for the project.
2. Upload it to **`api/config/service-account.json`** on the server. That folder
   is already protected by `.htaccess` (`Require all denied`); keep the file
   **out of git**.
3. In `api/config/config.php`, point `fcm.credentials` at it:
   ```php
   'fcm' => ['credentials' => __DIR__ . '/service-account.json'],
   ```
4. Make sure `api/storage/` is writable — the Google access token is cached in
   `storage/fcm_token.json`.

If `fcm.credentials` is absent, push is simply skipped (`Fcm::enabled()` is
`false`) and the app still receives messages via foreground long-poll.
InfinityFree is confirmed to allow outbound HTTPS to Google, so no external
relay is needed.

## 3. Frontend config

`web/config.php` already points `api_base` at `https://walkie.howto.rocks/api`.
Change it only if you host the API elsewhere.

## 4. Upload

Upload via FTP (host `ftpupload.net`, your account `if0_42263887`) or the online
file manager, preserving the structure above. Make sure the `.htaccess` files in
`api/` and `web/` are uploaded (they are hidden by default in some clients).

Directory permissions: `api/storage/` must be writable if `mail.log_only` is on.

## 5. Verify

- `https://walkie.howto.rocks/` → blank page (expected).
- `https://walkie.howto.rocks/api/health` → `{"status":"ok","service":"walkie"}`.
- `https://walkie.howto.rocks/web/` → the Walkie login screen.

Open `/web/` on two phones, sign in with two emails, show one QR and scan it with
the other — you should be linked and able to chat.

## 6. Retention cron (optional but recommended)

Cleanup already runs opportunistically on ~5% of API requests, so retention
holds even without cron. If your plan offers cron jobs, add:

```
*/5 * * * *  /usr/bin/php /home/vol/.../htdocs/api/cron/cleanup.php
```

(Adjust the absolute path to your account's document root.)

## Notes for InfinityFree specifically

- **Email:** codes are sent through the configured SMTP relay (native SMTP
  client, STARTTLS + AUTH LOGIN). If InfinityFree blocks outbound SMTP ports
  on your plan, the app falls back to PHP `mail()` and finally to
  `api/storage/mail.log`, so you can always recover a code while you sort
  out delivery.
- **HTTPS:** enable the free SSL certificate in the panel — the app assumes
  HTTPS (camera, microphone and the strict CSP all require a secure context).
- **No shell:** everything runs through the web; the opportunistic cleanup means
  no background worker is required.

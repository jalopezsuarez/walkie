# Walkie — API (backend)

Backend de Walkie: **PHP puro + MySQL**, sin frameworks ni dependencias, con una
**API JSON documentada en OpenAPI**. Arquitectura *vertical slice*: una carpeta
por funcionalidad, un archivo por endpoint, sobre un kernel HTTP mínimo.

> Parte de un proyecto con tres piezas. Visión general y enlaces en el
> [README raíz](../README.md). Frontend en [`../web`](../web/README.md), app
> Android en [`../android`](../android/README.md).

```
walkie.howto.rocks/api   →  este backend
```

Contrato completo y navegable: **[`openapi.yaml`](openapi.yaml)** (OpenAPI 3.x).

---

## Estructura (vertical slice)

```
api/
├── index.php               # front controller: cabeceras, CORS, rutas → slices
├── openapi.yaml            # especificación OpenAPI (contrato oficial)
├── install.php             # instalador de esquema (protegido por clave; borrar tras usar)
├── .htaccess               # rewrites + hardening
├── config/
│   ├── config.sample.php   # copiar → config.php y rellenar
│   └── config.php          # (git-ignored) secretos reales
├── migrations/schema.sql   # esquema de base de datos
├── cron/cleanup.php        # retención (también corre de forma oportunista)
├── src/
│   ├── Kernel/             # HTTP: Config, Database, Router, Request, Response,
│   │                       #   Validator, ApiException, Autoloader
│   ├── Shared/             # infra transversal: Crypto, Jwt, OAuthTokens, Session,
│   │                       #   UserAccount, LoginCode, RateLimiter, SmtpClient,
│   │                       #   Mailer, QrCode, Fcm, Cleanup
│   └── Features/           # un slice por carpeta, una clase por endpoint
│       ├── Health/         #   GetHealth
│       ├── Auth/           #   RequestLoginCode
│       ├── OAuth/          #   TokenEndpoint, RevokeEndpoint
│       ├── Profile/        #   GetProfile, UpdateProfile
│       ├── Pairing/        #   CreatePairingQr, ClaimPairing
│       ├── Contacts/       #   ListContacts, RemoveContact
│       ├── Push/           #   RegisterDevice (POST /devices)
│       └── Messages/       #   ListMessages, SendMessage, DeleteMessage,
│                           #   MessageStatuses, MarkRead, Conversation
└── tests/                  # verificador de QR + E2E de API y navegador
```

## Endpoints

Todo requiere token **Bearer** salvo `/health` y los dos `/auth` de entrada.
El contrato autoritativo está en [`openapi.yaml`](openapi.yaml).

| Método | Ruta | Propósito |
|---|---|---|
| GET | `/health` | Liveness |
| POST | `/auth/request-code` | Enviar código de 6 dígitos por email |
| POST | `/oauth/token` | **OAuth2**: canjear el código (o refresh) por access token (JWT) + refresh |
| POST | `/oauth/revoke` | **OAuth2**: revocar un refresh token (RFC 7009) |
| GET / PATCH | `/me` | Leer / actualizar perfil (nombre, email) |
| POST | `/link/qr` | Emitir token de emparejamiento + QR (SVG) |
| POST | `/link/claim` | Consumir un token escaneado → vincular dos usuarios |
| GET | `/links` | Contactos vinculados con no leídos |
| DELETE | `/links/{id}` | Desvincular (borra la conversación para ambos) |
| GET | `/links/{id}/messages` | Nuevos mensajes (`?after=`, `?wait=1` long-poll) |
| POST | `/links/{id}/messages` | Enviar texto o audio |
| DELETE | `/links/{id}/messages/{msgId}` | Borrar un mensaje propio |
| GET | `/links/{id}/statuses` | Estados de entrega/lectura |
| POST | `/links/{id}/read` | Marcar como leídos |
| POST | `/devices` | Registrar el token FCM del dispositivo (push) |

## Autenticación — OAuth 2.0 (oficial y estándar)

Login **passwordless**: email → código de 6 dígitos (TTL 5 min, un uso, máx. 5
intentos). El código se canjea por tokens en el **endpoint de token OAuth 2.0**.

- **`POST /oauth/token`** (RFC 6749 §3.2), body `application/x-www-form-urlencoded`:
  - **Grant de extensión** `urn:walkie:params:oauth:grant-type:email-code`
    (RFC 6749 §4.5) con `email` + `code` → emite tokens.
  - **`grant_type=refresh_token`** (§6) para renovar. Los refresh tokens **rotan**
    en cada uso.
  - Respuesta estándar (§5.1): `access_token`, `token_type: Bearer`,
    `expires_in`, `refresh_token`, `scope`. Errores (§5.2): `{ error,
    error_description }` (`invalid_grant`, `unsupported_grant_type`…).
- **Access token = JWT** (RFC 7519, HS256) autocontenido con `sub`, `exp`,
  `scope`, `jti`. Se envía como **Bearer** (RFC 6750).
- **`POST /oauth/revoke`** (RFC 7009) revoca un refresh token (p. ej. logout).
- El backend guarda solo **hashes SHA-256** de códigos y refresh tokens; el JWT
  se verifica por firma, sin *lookup* en BD y **sin estado de sesión** en el
  servidor. Es la **única** vía de autenticación — no hay tokens legacy.

Esquema declarado en [`openapi.yaml`](openapi.yaml) (`securitySchemes.bearerAuth`,
`bearerFormat: JWT`) con los endpoints `/oauth/token` y `/oauth/revoke`
documentados.

Flujo (grant email-code):

```bash
curl -X POST /api/auth/request-code -H 'Content-Type: application/json' \
     -d '{"email":"you@example.com"}'
curl -X POST /api/oauth/token \
     -d 'grant_type=urn:walkie:params:oauth:grant-type:email-code' \
     --data-urlencode 'email=you@example.com' --data-urlencode 'code=123456'
# → { "access_token":"<JWT>", "token_type":"Bearer", "expires_in":3600,
#     "refresh_token":"…", "scope":"walkie" }
curl /api/me -H "Authorization: Bearer <JWT>"
```

## Notificaciones push (FCM HTTP v1)

Push oficial de Google para la app Android, todo desde la propia API:

- **`POST /devices`** (`Features/Push/RegisterDevice`) guarda el token FCM del
  dispositivo (como hash) en la tabla `devices`, asociado al usuario.
- Al enviar un mensaje, `Features/Messages/SendMessage` invoca `Shared/Fcm`
  **después** de responder al cliente (`fastcgi_finish_request`), así el envío
  del push no añade latencia. `Fcm` firma un JWT RS256 de **cuenta de servicio**,
  lo canjea en `oauth2.googleapis.com` y hace `POST` a
  `fcm.googleapis.com/v1/projects/<proj>/messages:send`.
- **Privacidad:** el push transporta solo el **nombre del remitente** y el tipo
  (`💬 Nuevo mensaje` / `🎤 Nota de voz`) + `link_id`; **nunca** el contenido del
  mensaje (que además va cifrado en reposo).
- **Configuración:** `config.php` referencia la clave de cuenta de servicio en
  `fcm.credentials` (p. ej. `__DIR__ . '/service-account.json'`). Si no está
  configurada, `Fcm::enabled()` es `false` y el envío se omite silenciosamente
  (la app sigue recibiendo por long-poll en primer plano). El `service-account.json`
  vive en `config/`, protegido por `.htaccess` y **fuera de git**. Ver
  [DEPLOY.md](../DEPLOY.md).

> El token de acceso de Google se cachea en `storage/fcm_token.json` hasta que
> expira. Verificado que InfinityFree permite la salida HTTPS a Google.

## Seguridad

| Área | Medida |
|---|---|
| Contenido de mensajes | AES-256-GCM en reposo, clave por conversación derivada con HKDF; el servidor solo guarda cifrado |
| Transporte | HTTPS + CORS estricto al origen web; auth por Bearer (a prueba de CSRF) |
| Códigos y tokens | Solo hashes SHA-256; códigos de un uso, TTL 5 min, máx. 5 intentos |
| Email | Cliente SMTP nativo (STARTTLS + AUTH LOGIN, RFC 5321); *fallback* a `mail()` y a log |
| Abuso | Rate limiting de ventana fija por IP, email y usuario; `429` con `Retry-After` |
| Inyección | 100% PDO con *prepared statements*; validación estricta |
| Cabeceras | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, CSP |
| Minimización | Mensajes efímeros; limpieza oportunista + cron purga expirados/leídos |

> **Modelo de cifrado.** Los mensajes se cifran en reposo y en tránsito; la base
> de datos nunca guarda texto plano. Como el login es passwordless y funciona
> entre dispositivos, las claves viven en el servidor (no E2E) — un compromiso
> deliberado. La derivación por conversación minimiza el radio de exposición.

## Requisitos

- PHP 8.1+ (extensiones PDO MySQL + OpenSSL)
- MySQL 5.7+ / MariaDB 10.3+
- Apache con `mod_rewrite` (o equivalente para los rewrites del `.htaccess`)

## Puesta en marcha

1. `cp config/config.sample.php config/config.php` y rellena DB, SMTP, una
   `app.key` nueva (`php -r "echo base64_encode(random_bytes(32));"`) y una
   `app.install_key` aleatoria.
2. Sube `index.php`, `api/` y `web/` al *document root*.
3. Visita `https://<host>/api/install.php?key=<app.install_key>` una vez para
   crear el esquema y **borra `install.php`**.
4. Comprueba `https://<host>/api/health`.
5. (Opcional) Programa `php cron/cleanup.php` cada pocos minutos; la retención
   también corre de forma oportunista con el tráfico.

## Desarrollo local

```bash
cp config/config.sample.php config/config.php   # DB local, debug + log_only
php install.php <tu-install-key>                 # crea el esquema
php -S 127.0.0.1:8080 tests/router.php           # sirve /api y /web
```

## Tests

```bash
python3 tests/qr_verify.py         # encoder QR vs librería de referencia (byte-idéntico)
php -S 127.0.0.1:8080 tests/router.php &
bash tests/integration.sh          # flujo completo de API contra MySQL real
node tests/e2e_browser.js          # frontend en navegador real (dos usuarios)
```

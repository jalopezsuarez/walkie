# Walkie — Web (frontend)

Frontend de Walkie: **JavaScript y CSS puros**, sin frameworks, sin paso de
build y sin dependencias en tiempo de ejecución. Un shell PHP mínimo
(`index.php`) sirve el HTML e inyecta la URL de la API y la cabecera CSP; todo lo
demás es vanilla JS servido como estático.

> Parte de un proyecto con tres piezas. Visión general y enlaces en el
> [README raíz](../README.md). Backend en [`../api`](../api/README.md), app
> Android en [`../android`](../android/README.md).

```
walkie.howto.rocks/web   →  este frontend
```

El frontend **solo conoce la URL de la API** (`data-api` en el `<body>`), así que
es 100% independiente del backend y de la app Android: los tres hablan el mismo
contrato ([`../api/openapi.yaml`](../api/openapi.yaml)).

---

## Estructura (vertical slice)

```
web/
├── index.php              # shell HTML: inyecta data-api + CSP, carga los scripts
├── config.php             # api_base + nombre de app
├── manifest.webmanifest   # PWA (icono, colores, standalone)
└── assets/
    ├── css/style.css      # todo el sistema de diseño (tema claro, degradado)
    ├── icons/             # iconos PWA (192/512/180)
    └── js/
        ├── api.js         # cliente de la API (fetch + bearer token)
        ├── core.js        # estado, helpers DOM, overlay, iconos SVG
        ├── qr.js          # generar/escanear QR
        ├── audio.js       # grabación push-to-talk
        ├── notify.js      # notificaciones nativas (primer plano)
        ├── names.js       # generador de pseudónimos
        ├── features/      # una carpeta-función por slice
        │   ├── auth.js         # login por email + código
        │   ├── contacts.js     # lista de contactos + no leídos
        │   ├── conversation.js # chat: long-poll, audio, texto, checks
        │   ├── pairing.js      # QR a pantalla completa + claim
        │   └── settings.js     # pseudónimo, notificaciones, cerrar sesión
        └── app.js         # arranque + notificador global
```

Cada slice se cuelga del namespace global `window.W` (helper `W.el()` para DOM).
Añadir una función = añadir un archivo en `features/` y registrarlo en
`index.php`.

## Cómo habla con la API

- Cliente en `assets/js/api.js`; base URL desde `document.body.dataset.api`.
- Autenticación por **token Bearer** (`Authorization: Bearer …`) guardado en
  `localStorage`. Un `401` limpia la sesión.
- **Tiempo real** por *long-polling* (`GET /links/{id}/messages?wait=1`): la
  petición se mantiene abierta hasta que llega un mensaje nuevo o expira el
  tiempo — casi instantáneo, sin WebSockets.
- **Audio**: se graba en el navegador (WebM/Opus) y viaja como base64 dentro del
  mensaje; se reproduce con caché por id (sin cortes).

## Seguridad en el cliente

- **CSP estricta** (`script-src 'self'`): nada de scripts inline; la base de la
  API va como atributo `data-api`, no como `<script>`.
- Todo el texto de usuario se pinta con `textContent` (nunca `innerHTML` con
  datos), evitando XSS.
- Sin dependencias externas → sin CDNs ni cadena de suministro que auditar.

## PWA y notificaciones

- `manifest.webmanifest` permite "Añadir a inicio" (imprescindible para push en
  iOS 16.4+).
- `notify.js` usa la API de Notificaciones del navegador (avisos en primer
  plano). El push en segundo plano requiere Service Worker + Web Push (pendiente
  en backend; ver README raíz).

## Configuración

`web/config.php` define `api_base` (URL del backend) y el nombre de la app. En
producción apunta a `https://walkie.howto.rocks/api`.

## Desarrollo local

Servido junto al backend por el router de pruebas:

```bash
php -S 127.0.0.1:8080 api/tests/router.php   # sirve /api y /web
# abre http://127.0.0.1:8080/web/
```

Con `app.debug = true` en el backend, el código de login se devuelve en la
respuesta de `request-code`, así que no hace falta buzón para desarrollar.

# Walkie

App minimalista para intercambiar **notas de voz y texto entre dos personas**.
Dos usuarios se vinculan **solo** escaneando el QR del otro — no hay otra forma
de conectar. Las conversaciones son efímeras: el audio dura como mucho **1 hora**
y el texto **24 horas**. Todo cifrado en reposo y servido por HTTPS.

Diseño claro y minimalista, pensado para móvil y tablet. **Sin frameworks, sin
paso de build, sin dependencias en tiempo de ejecución.**

```
walkie.howto.rocks/        → página en blanco (a propósito)
walkie.howto.rocks/web     → frontend  (PHP shell + JS/CSS vanilla)
walkie.howto.rocks/api     → backend   (API JSON documentada en OpenAPI)
```

Las tres piezas están **totalmente desacopladas** y hablan el mismo contrato
([`api/openapi.yaml`](api/openapi.yaml)): cualquiera se puede reemplazar sin
tocar las demás.

---

## Secciones — un README por cada una

| Sección | Qué es | Documentación |
|---|---|---|
| 🔌 **API** | Backend PHP + MySQL, API JSON (OpenAPI). Vertical slice. | **[api/README.md](api/README.md)** |
| 🌐 **Web** | Frontend JS/CSS vanilla, sin build. PWA. | **[web/README.md](web/README.md)** |
| 🤖 **Android** | App nativa Kotlin (Compose), push FCM. Consume la misma API. | **[android/README.md](android/README.md)** |

Guía de despliegue paso a paso: **[DEPLOY.md](DEPLOY.md)**.

---

## Cómo encaja todo

```
                 ┌───────────────────────────┐
   web (JS/CSS) ─┤                           │
                 │   API  (PHP + MySQL)      │  ← contrato: api/openapi.yaml
 android (Kotlin)┤   OpenAPI · Bearer/OAuth2 │
                 └───────────────────────────┘
```

- La **API** es la única fuente de verdad: auth, emparejamiento, mensajes,
  estados de lectura. Cifrado en reposo (AES-256-GCM), claves por conversación.
- **Web** y **Android** son clientes intercambiables del mismo contrato. La web
  es multiplataforma (iOS, escritorio, Android); la app nativa da la mejor
  experiencia y **push oficial de Google (FCM)** en Android.
- **Tiempo real** por *long-polling* (`?wait=1`), sin WebSockets.

## Funcionalidades

- **Login sin contraseña** — email + código de 6 dígitos (5 min, un uso).
- **Emparejamiento solo por QR** — muestras tu QR, la otra persona lo escanea.
  Si cualquiera borra la conversación, se desvinculan ambos.
- **Notas de voz push-to-talk** + texto. Emojis grandes.
- **Borrado por el emisor** en cualquier momento, sin rastro.
- **Retención automática** — audio 1 h, texto 24 h.
- **Checks de entrega y lectura** (un check / doble check).

## Arquitectura

Las tres piezas siguen **vertical slice**: cada funcionalidad es una unidad
autocontenida (handler + su acceso a datos en la API; pantalla + comportamiento
en los clientes) sobre un kernel/core fino. Añadir una función = añadir una
carpeta, no tocar capas.

```
walkie/
├── index.php     # página raíz en blanco
├── api/          # backend PHP + MySQL   → api/README.md
├── web/          # frontend JS/CSS       → web/README.md
├── android/      # app nativa Kotlin     → android/README.md
├── DEPLOY.md     # despliegue en InfinityFree
└── README.md     # este índice
```

## Seguridad (resumen)

Cifrado de mensajes AES-256-GCM en reposo (clave por conversación vía HKDF),
OAuth 2.0 (JWT + refresh rotatorio), solo hashes SHA-256 de códigos y tokens,
HTTPS + HSTS, CORS estricto, rate limiting por IP/email/usuario, 100%
*prepared statements*, CSP estricta y cabeceras de seguridad, APK firmado.

Modelo de seguridad unificado (web + API + Android): **[SECURITY.md](SECURITY.md)**.

## Requisitos y tests

Requisitos (PHP 8.1+, MySQL/MariaDB, Apache `mod_rewrite`), puesta en marcha y
tests están en **[api/README.md](api/README.md)**. El encoder de QR y el flujo
completo (API + navegador) están verificados contra infraestructura real.

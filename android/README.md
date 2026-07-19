# Walkie — Android nativo (Kotlin)

Cliente Android nativo de Walkie. Consume **100 % la API existente**
(`https://walkie.howto.rocks/api`) y replica la interfaz web: emparejamiento por
QR, notas de voz + texto efímeros, checks de entrega/lectura y ajustes.
Notificaciones **oficiales de Google (FCM)**.

- **Lenguaje:** Kotlin puro
- **UI:** Jetpack Compose + Material 3 (tema claro tipo Telegram, degradado pastel)
- **Arquitectura:** *vertical slice* (una carpeta por funcionalidad)
- **Mínimo:** Android 10 (API 29) — ~cobertura alta y mantiene la grabación OGG/Opus. Compile/target: API 35
- **Dependencias:** mínimas (OkHttp, kotlinx-serialization, DataStore, ZXing, Firebase Messaging)
- **Icono de marca:** burbuja de chat de línea con tres puntos + candado (mismo
  glifo que la web), como *vector drawables* tintables (launcher, notificación,
  navbar, pantalla de login y estado vacío).

---

## Estructura (vertical slice)

```
app/src/main/java/rocks/howto/walkie/
├─ WalkieApp.kt            · Application + grafo de dependencias (DI manual)
├─ MainActivity.kt         · Host Compose + permiso de notificaciones
├─ core/
│  ├─ network/             · WalkieApi (todos los endpoints), DTOs, OkHttp,
│  │                         AntiBotInterceptor (reto JS de InfinityFree)
│  ├─ data/                · SessionStore (tokens OAuth2 + usuario en DataStore)
│  ├─ audio/               · AudioRecorder (OGG/Opus) + AudioPlayer
│  ├─ designsystem/        · Tema, colores, componentes (Avatar, Checks…)
│  └─ di/                  · AppContainer (un único grafo explícito)
├─ feature/
│  ├─ auth/                · Login por email + código
│  ├─ contacts/            · Lista de contactos (badges de no leídos)
│  ├─ conversation/        · Chat: long-poll, audio, texto, checks, borrar
│  ├─ pairing/             · QR (generar + escanear)
│  └─ settings/            · Pseudónimo, cerrar sesión
├─ messaging/              · FCM: servicio, registro de token, notificaciones
└─ nav/                    · Navegación (pila mínima, sin librería extra)
```

Cada *slice* es autónomo: su `ViewModel` (estado con Compose `State`) + su
pantalla `Composable`. La única dependencia compartida es `core/`.

---

## Cómo se comunica con la API

Todo pasa por `core/network/WalkieApi.kt`, fiel al backend PHP:

| Pantalla | Endpoints |
|---|---|
| Auth (OAuth2) | `POST /auth/request-code`, `POST /oauth/token` (JWT + refresh), `POST /oauth/revoke` |
| Ajustes | `GET /me`, `PATCH /me` |
| Pairing | `POST /link/qr`, `POST /link/claim` |
| Contactos | `GET /links`, `DELETE /links/{id}` |
| Conversación | `GET /links/{id}/messages?after=&wait=1`, `POST …/messages`, `DELETE …/messages/{id}`, `GET …/statuses`, `POST …/read` |
| Push | `POST /devices` (registra el token FCM del dispositivo) |

**Tiempo real:** long-polling (`?wait=1`), igual que la web — sin WebSockets.
Con la app abierta, los mensajes llegan casi al instante.

**Audio:** se graba en **AAC/M4A** (contenedor MP4). AAC se reproduce de forma
fiable en todos los `MediaPlayer` de Android (Opus/OGG falla en varios OEM) y en
cualquier navegador, así que la web reproduce las notas sin transcodificar. Al
reproducir, el audio recibido se cachea a archivo con su extensión real
(según el `mime`) para que el extractor elija el demuxer correcto.

**Reto anti-bot de InfinityFree.** El hosting protege el sitio con un desafío
JavaScript (AES/`__test` cookie) que bloquea a los clientes que no son
navegador (incluido OkHttp) — por eso al principio *no llegaba el código por
email* desde la app. `core/network/AntiBotInterceptor.kt` resuelve el reto
(descifra `a,b,c` con AES-128-CBC, fija la cookie `__test` y reintenta), de
forma transparente para el resto de la app. Es lo primero en la cadena de
interceptores, antes del de autenticación.

---

## Notificaciones push (FCM) — operativo

Push oficial de Google (**FCM HTTP v1**) de extremo a extremo. Cliente y
servidor completos:

**Cliente (esta app)**
- `messaging/` — servicio FCM, registro del token (`POST /devices`), canal de
  notificación e icono `ic_notification`.
- El token se registra al iniciar sesión y en cada `onNewToken`.

**Servidor (API PHP)** — ya implementado, ver [`../api`](../api/README.md):
- **`POST /devices`** (`Features/Push/RegisterDevice`) guarda el hash del token
  FCM en la tabla `devices`.
- Al enviar un mensaje, `Features/Messages/SendMessage` llama a
  `Shared/Fcm` y notifica al destinatario por FCM HTTP v1 (JWT RS256 de cuenta
  de servicio → `oauth2.googleapis.com` → `fcm.googleapis.com/v1/.../messages:send`),
  **después** de responder al cliente (`fastcgi_finish_request`).
- **Privacidad:** el push lleva solo el **nombre del remitente** y un tipo
  (`💬 Nuevo mensaje` / `🎤 Nota de voz`) + `link_id` — **nunca el contenido**.

### Configuración Firebase
1. Proyecto en [Firebase Console](https://console.firebase.google.com/) (aquí:
   `walkie-e881f`) con una app Android `applicationId = rocks.howto.walkie`.
2. `google-services.json` va en `android/app/` (se incluye en el repo: Google lo
   considera identificador de cliente, no secreto). El plugin de Google Services
   se activa solo cuando el archivo existe.
3. En el servidor, la clave de **cuenta de servicio** (`service-account.json`)
   se coloca en `api/config/` (protegida por `.htaccess`, **fuera de git**) y se
   referencia en `config.php` (`fcm.credentials`). Ver [DEPLOY.md](../DEPLOY.md).

> **Salida de InfinityFree:** verificado que el hosting **sí permite HTTPS
> saliente** a Google (intercambio de token HTTP 200 y `messages:send`
> respondiendo). No hace falta relé externo.

Con la app abierta también funciona el **long-poll en primer plano**; el push
cubre el segundo plano.

---

## Compilar

**Recomendado:** abre la carpeta `android/` en **Android Studio** (Ladybug o
superior). Sincroniza y ejecuta. Android Studio gestiona el Gradle wrapper.

Por línea de comandos (si tienes Gradle 8.11+):
```bash
cd android
gradle assembleDebug          # APK de depuración (sin Firebase hace falta)
# APK en app/build/outputs/apk/debug/app-debug.apk
```

El **workflow** `.github/workflows/android-native.yml` compila el APK **de
release firmado** en CI y lo publica en *Releases*. Se dispara al hacer push a
`main` que toque `android/**`, o manualmente desde *Actions*.

## Firma del APK

La firma de release lee un keystore desde variables de entorno (CI) o
`gradle.properties` (local): `walkie.keystore`, `walkie.keystorePassword`,
`walkie.keyAlias`, `walkie.keyPassword`.

En CI se firma con el keystore de **producción**, cargado desde los *secrets*
de repositorio `ANDROID_KEYSTORE_BASE64`, `ANDROID_KEYSTORE_PASSWORD`,
`ANDROID_KEY_ALIAS` y `ANDROID_KEY_PASSWORD`. **Configurado y verificado**: el
certificado de firma es estable (`7c3abcaa…`), lo que permite actualizaciones
*in-place* sin desinstalar. Si algún día faltaran los secrets, el workflow cae a
una clave **efímera** por build (avisa con un `::warning::` y el paso "Verify
APK signature" imprime el certificado, para detectarlo).

> Los *secrets* deben estar a nivel **Repository** (Settings → Secrets and
> variables → Actions), no como *Environment secrets*: el workflow no declara
> `environment:` y no vería estos últimos.

Crear un keystore estable una vez y cargarlo como secret:

```bash
keytool -genkeypair -v -keystore walkie.keystore -alias walkie \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -storepass '<PASS>' -keypass '<PASS>' -dname "CN=Walkie, O=Walkie, C=ES"
base64 -w0 walkie.keystore    # pega el resultado en el secret ANDROID_KEYSTORE_BASE64
```

Guarda el `.keystore` y las contraseñas de forma segura: son las que permiten
publicar actualizaciones de la app.

---

## Pros y contras (nativo Kotlin vs. PWA/TWA)

### Pros
- **Notificaciones oficiales de Google (FCM)** reales en segundo plano, sin el
  requisito de “añadir a inicio” de iOS (aquí es Android, sin esa fricción).
- **Grabación/reproducción de audio nativas**: mejor calidad, control fino de
  permisos de micrófono, sin depender del `MediaRecorder` del navegador.
- **Rendimiento y UX**: arranque instantáneo, gestos nativos, cámara para el QR
  integrada, funciona como app de primera clase.
- **Acceso a APIs del sistema**: cámara, permisos, canales de notificación,
  posibilidad futura de widgets/atajos.
- **Distribución** por APK/Play Store, icono propio, sin barra de navegador.

### Contras
- **Solo Android.** No cubre iPhone/iPad ni escritorio (la PWA sí). Para iOS
  haría falta otra app (Swift) o seguir con la PWA.
- **Mantenimiento duplicado**: dos frontends (web + Android) que evolucionan en
  paralelo cuando cambia la API o el diseño.
- **Requiere Firebase** (cuenta Google + `google-services.json` + cuenta de
  servicio en el backend). Ya integrado y funcionando; el envío corre en la
  propia API (InfinityFree permite la salida HTTPS a Google, sin relé externo).
- **Ciclo de release** más pesado que subir archivos a un hosting: compilar,
  firmar, publicar APK.
- **Tamaño**: un APK (~8–12 MB) frente a “cero instalación” de la web.

### Recomendación
Mantener la **PWA como base multiplataforma** (iOS + escritorio + Android) y
usar esta **app nativa para Android** cuando se quiera la mejor experiencia y
push nativo fiable. Comparten backend, así que no hay divergencia de datos.

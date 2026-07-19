# Walkie — Android nativo (Kotlin)

Cliente Android nativo de Walkie. Consume **100 % la API existente**
(`https://walkie.howto.rocks/api`) y replica la interfaz web: emparejamiento por
QR, notas de voz + texto efímeros, checks de entrega/lectura y ajustes.
Notificaciones **oficiales de Google (FCM)**.

- **Lenguaje:** Kotlin puro
- **UI:** Jetpack Compose + Material 3 (tema claro tipo Telegram, degradado pastel)
- **Arquitectura:** *vertical slice* (una carpeta por funcionalidad)
- **Mínimo:** Android 11 (API 30). Objetivo: API 35
- **Dependencias:** mínimas (OkHttp, kotlinx-serialization, DataStore, ZXing, Firebase Messaging)

---

## Estructura (vertical slice)

```
app/src/main/java/rocks/howto/walkie/
├─ WalkieApp.kt            · Application + grafo de dependencias (DI manual)
├─ MainActivity.kt         · Host Compose + permiso de notificaciones
├─ core/
│  ├─ network/             · WalkieApi (todos los endpoints), DTOs, OkHttp
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

**Tiempo real:** long-polling (`?wait=1`), igual que la web — sin WebSockets.
Con la app abierta, los mensajes llegan casi al instante.

**Audio:** se graba en **OGG/Opus** (el mismo códec del navegador), así la web
reproduce las notas sin transcodificar, y viceversa.

---

## Notificaciones push (FCM) — pasos

El **lado cliente está completo** (servicio, registro de token, canal,
notificación). Faltan dos cosas para que Google entregue push:

### 1. Firebase (config del proyecto)
1. Crea un proyecto en [Firebase Console](https://console.firebase.google.com/) y añade una app Android con `applicationId = rocks.howto.walkie`.
2. Descarga `google-services.json` y ponlo en `android/app/google-services.json` (hay una plantilla en `google-services.json.example`).
3. El plugin de Google Services se activa solo cuando ese archivo existe.

### 2. Backend TODO (2 endpoints en la API PHP)
Para que el servidor pueda notificar, hay que añadir a la API:

- **`POST /devices`** — guarda el token FCM del dispositivo del usuario.
  El cliente ya lo llama (`WalkieApi.registerDevice`); hoy falla en silencio.
- **Envío a FCM al recibir mensaje** — en `SendMessage`, tras guardar el
  mensaje, enviar un push (FCM HTTP v1) al token del destinatario con
  `{ title, body, link_id }`.

> ⚠️ El envío exige que el servidor haga **HTTPS saliente a `fcm.googleapis.com`**.
> Si InfinityFree bloquea la salida (lo estábamos comprobando con `probe.php`),
> el envío hay que hacerlo desde un **Cloudflare Worker** gratuito que actúe de
> relé. El registro (`POST /devices`) sí puede vivir en la API.

Mientras esto no esté, la app funciona con **long-poll en primer plano**
(avisos con la app abierta); el push en segundo plano llega cuando el backend
esté listo.

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

En CI, si defines el *secret* `ANDROID_KEYSTORE_BASE64` (+ `…_PASSWORD`,
`ANDROID_KEY_ALIAS`, `ANDROID_KEY_PASSWORD`) se firma con **tu** keystore
(actualizaciones limpias en el dispositivo). Si no, se firma con una clave
**efímera** por build (instala, pero para actualizar hay que desinstalar).

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
- **Requiere Firebase** (cuenta Google + `google-services.json`) y, para el
  envío, tocar el backend (2 endpoints) y quizá un Worker por el bloqueo de
  salida de InfinityFree.
- **Ciclo de release** más pesado que subir archivos a un hosting: compilar,
  firmar, publicar APK.
- **Tamaño**: un APK (~8–12 MB) frente a “cero instalación” de la web.

### Recomendación
Mantener la **PWA como base multiplataforma** (iOS + escritorio + Android) y
usar esta **app nativa para Android** cuando se quiera la mejor experiencia y
push nativo fiable. Comparten backend, así que no hay divergencia de datos.

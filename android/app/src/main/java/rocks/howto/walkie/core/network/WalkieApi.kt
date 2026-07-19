package rocks.howto.walkie.core.network

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * Typed wrapper over the Walkie HTTP API. Every screen talks to the backend
 * exclusively through this class — no other layer knows about HTTP.
 */
class WalkieApi(
    private val baseUrl: String,
    private val client: OkHttpClient,
    private val onUnauthorized: () -> Unit,
) {
    private val jsonMedia = "application/json; charset=utf-8".toMediaType()
    private val empty = ByteArray(0).toRequestBody(null)

    private inline fun <reified T> enc(v: T): String = WalkieJson.encodeToString(v)
    private inline fun <reified T> dec(s: String): T = WalkieJson.decodeFromString(s)

    private suspend fun exec(method: String, path: String, jsonBody: String? = null): String =
        withContext(Dispatchers.IO) {
            val body = jsonBody?.toRequestBody(jsonMedia)
            val builder = Request.Builder().url(baseUrl + path)
            when (method) {
                "GET" -> builder.get()
                "POST" -> builder.post(body ?: empty)
                "PATCH" -> builder.patch(body ?: empty)
                "DELETE" -> if (body != null) builder.delete(body) else builder.delete()
                else -> error("unsupported method $method")
            }
            client.newCall(builder.build()).execute().use { resp ->
                val text = resp.body?.string().orEmpty()
                if (resp.code == 401) onUnauthorized()
                if (!resp.isSuccessful) throw parseError(resp.code, resp.message, text)
                text
            }
        }

    private fun parseError(status: Int, fallbackMsg: String, text: String): ApiException {
        val obj = runCatching { WalkieJson.parseToJsonElement(text).jsonObject }.getOrNull()
        val code = obj?.get("error")?.jsonPrimitive?.content ?: "error"
        val msg = obj?.get("message")?.jsonPrimitive?.content ?: fallbackMsg.ifBlank { "Error" }
        return ApiException(status, code, msg)
    }

    /* ---- auth ---- */
    suspend fun requestCode(email: String) { exec("POST", "/auth/request-code", enc(EmailBody(email))) }
    suspend fun verify(email: String, code: String): AuthResponse =
        dec(exec("POST", "/auth/verify", enc(VerifyBody(email, code))))
    suspend fun logout() { runCatching { exec("POST", "/auth/logout") } }

    /* ---- profile ---- */
    suspend fun me(): User = dec<MeResponse>(exec("GET", "/me")).user
    suspend fun updateName(name: String): User =
        dec<MeResponse>(exec("PATCH", "/me", enc(ProfilePatch(name)))).user

    /* ---- pairing ---- */
    suspend fun createQr(): QrResponse = dec(exec("POST", "/link/qr"))
    suspend fun claim(token: String): ClaimResponse = dec(exec("POST", "/link/claim", enc(ClaimBody(token))))

    /* ---- contacts ---- */
    suspend fun links(): List<Contact> = dec<LinksResponse>(exec("GET", "/links")).links
    suspend fun unlink(linkId: Long) { exec("DELETE", "/links/$linkId") }

    /* ---- messages ---- */
    suspend fun messages(linkId: Long, after: Long, wait: Boolean): MessagesResponse {
        val q = buildList {
            if (after > 0) add("after=$after")
            if (wait) add("wait=1")
        }.joinToString("&")
        val suffix = if (q.isNotEmpty()) "?$q" else ""
        return dec(exec("GET", "/links/$linkId/messages$suffix"))
    }
    suspend fun sendText(linkId: Long, text: String): SendResult =
        dec(exec("POST", "/links/$linkId/messages", enc(TextBody(text = text))))
    suspend fun sendAudio(linkId: Long, base64: String, mime: String, durationMs: Long?): SendResult =
        dec(exec("POST", "/links/$linkId/messages", enc(AudioBody(audio = base64, mime = mime, durationMs = durationMs))))
    suspend fun deleteMessage(linkId: Long, msgId: Long) { exec("DELETE", "/links/$linkId/messages/$msgId") }
    suspend fun statuses(linkId: Long): List<Status> = dec<StatusesResponse>(exec("GET", "/links/$linkId/statuses")).statuses
    suspend fun markRead(linkId: Long, ids: List<Long>) { exec("POST", "/links/$linkId/read", enc(ReadBody(ids))) }

    /* ---- push device registration ----
       NOTE: requires a matching `POST /devices` endpoint on the server (see
       android/README.md → "Backend TODO for FCM"). Fails silently until then. */
    suspend fun registerDevice(fcmToken: String) {
        runCatching { exec("POST", "/devices", enc(DeviceBody(fcmToken))) }
    }
}

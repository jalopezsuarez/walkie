package rocks.howto.walkie.core.network

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.FormBody
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import rocks.howto.walkie.core.data.SessionStore

/**
 * Typed wrapper over the Walkie HTTP API. Authentication is OAuth 2.0:
 * a JWT access token is sent as a Bearer credential, and an expired token is
 * transparently refreshed once via the refresh token.
 */
class WalkieApi(
    private val baseUrl: String,
    private val client: OkHttpClient,
    private val session: SessionStore,
) {
    private val jsonMedia = "application/json; charset=utf-8".toMediaType()
    private val empty = ByteArray(0).toRequestBody(null)
    private val refreshLock = Mutex()

    private inline fun <reified T> enc(v: T): String = WalkieJson.encodeToString(v)
    private inline fun <reified T> dec(s: String): T = WalkieJson.decodeFromString(s)

    private companion object {
        const val EMAIL_CODE_GRANT = "urn:walkie:params:oauth:grant-type:email-code"
    }

    private suspend fun exec(method: String, path: String, jsonBody: String? = null, retried: Boolean = false): String =
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
                if (resp.code == 401 && !retried && session.cachedRefresh != null) {
                    if (refresh()) return@withContext exec(method, path, jsonBody, retried = true)
                }
                if (!resp.isSuccessful) throw parseError(resp.code, resp.message, text)
                text
            }
        }

    private fun parseError(status: Int, fallbackMsg: String, text: String): ApiException {
        val obj = runCatching { WalkieJson.parseToJsonElement(text).jsonObject }.getOrNull()
        val code = obj?.get("error")?.jsonPrimitive?.content ?: "error"
        val msg = obj?.get("message")?.jsonPrimitive?.content
            ?: obj?.get("error_description")?.jsonPrimitive?.content
            ?: fallbackMsg.ifBlank { "Error" }
        return ApiException(status, code, msg)
    }

    /* ---- OAuth 2.0 ---- */
    private suspend fun tokenRequest(params: Map<String, String>): TokenResponse =
        withContext(Dispatchers.IO) {
            val form = FormBody.Builder().apply { params.forEach { (k, v) -> add(k, v) } }.build()
            val req = Request.Builder().url("$baseUrl/oauth/token").post(form).build()
            client.newCall(req).execute().use { resp ->
                val text = resp.body?.string().orEmpty()
                if (!resp.isSuccessful) throw parseError(resp.code, resp.message, text)
                dec<TokenResponse>(text)
            }
        }

    suspend fun requestCode(email: String) { exec("POST", "/auth/request-code", enc(EmailBody(email))) }

    /** Exchange the emailed code for tokens, then load and store the profile. */
    suspend fun login(email: String, code: String): User {
        val t = tokenRequest(mapOf("grant_type" to EMAIL_CODE_GRANT, "email" to email, "code" to code))
        session.saveTokens(t.accessToken, t.refreshToken)
        val user = me()
        session.saveUser(user)
        return user
    }

    /** Refresh the access token (deduped across concurrent 401s). */
    private suspend fun refresh(): Boolean = refreshLock.withLock {
        val token = session.cachedRefresh ?: return false
        try {
            val t = tokenRequest(mapOf("grant_type" to "refresh_token", "refresh_token" to token))
            session.saveTokens(t.accessToken, t.refreshToken)
            true
        } catch (e: Exception) {
            session.clear()
            false
        }
    }

    suspend fun logout() {
        session.cachedRefresh?.let { token ->
            runCatching {
                withContext(Dispatchers.IO) {
                    val form = FormBody.Builder().add("token", token).build()
                    client.newCall(Request.Builder().url("$baseUrl/oauth/revoke").post(form).build()).execute().close()
                }
            }
        }
        session.clear()
    }

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

    /* ---- push device registration (requires server POST /devices) ---- */
    suspend fun registerDevice(fcmToken: String) {
        runCatching { exec("POST", "/devices", enc(DeviceBody(fcmToken))) }
    }
}

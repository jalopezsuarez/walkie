package rocks.howto.walkie.core.network

import okhttp3.Interceptor
import okhttp3.Response
import javax.crypto.Cipher
import javax.crypto.spec.IvParameterSpec
import javax.crypto.spec.SecretKeySpec

/**
 * Transparently clears the InfinityFree "JavaScript required" anti-bot check.
 *
 * The host answers the first request from a non-browser client with an HTML
 * page that decrypts a value with AES-128-CBC and sets a `__test` cookie, then
 * reloads. Browsers do this automatically; OkHttp does not — so without this
 * the API is unreachable from the app. We replicate that handshake: on
 * detecting the challenge, decrypt the token, remember the cookie, and retry.
 */
class AntiBotInterceptor : Interceptor {

    @Volatile private var testCookie: String? = null

    override fun intercept(chain: Interceptor.Chain): Response {
        val response = chain.proceed(withCookie(chain.request()))

        // The real API always returns JSON; the challenge is text/html.
        val contentType = response.header("Content-Type").orEmpty()
        if (contentType.contains("json", ignoreCase = true)) {
            return response
        }

        val html = response.peekBody(64 * 1024).string()
        val cookie = solve(html) ?: return response

        testCookie = cookie
        response.close()
        return chain.proceed(withCookie(chain.request()))
    }

    private fun withCookie(request: okhttp3.Request): okhttp3.Request {
        val cookie = testCookie ?: return request
        val existing = request.header("Cookie")
        val merged = if (existing.isNullOrBlank()) "__test=$cookie" else "$existing; __test=$cookie"
        return request.newBuilder().header("Cookie", merged).build()
    }

    /** Returns the `__test` cookie value for the challenge, or null if not one. */
    private fun solve(html: String): String? {
        if (!html.contains("slowAES")) return null
        val hex = Regex("\"([0-9a-fA-F]{32})\"").findAll(html).map { it.groupValues[1] }.toList()
        if (hex.size < 3) return null
        return runCatching {
            val key = SecretKeySpec(hex[0].hexToBytes(), "AES")
            val iv = IvParameterSpec(hex[1].hexToBytes())
            val cipher = Cipher.getInstance("AES/CBC/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, key, iv)
            cipher.doFinal(hex[2].hexToBytes()).toHex()
        }.getOrNull()
    }

    private fun String.hexToBytes(): ByteArray =
        ByteArray(length / 2) { ((this[it * 2].digitToInt(16) shl 4) or this[it * 2 + 1].digitToInt(16)).toByte() }

    private fun ByteArray.toHex(): String = joinToString("") { "%02x".format(it) }
}

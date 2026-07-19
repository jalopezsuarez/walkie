package rocks.howto.walkie.core.network

import kotlinx.serialization.json.Json
import okhttp3.OkHttpClient
import java.util.concurrent.TimeUnit

/** Thrown for any non-2xx API response. `code` is the API's machine error slug. */
class ApiException(
    val status: Int,
    val code: String,
    override val message: String,
) : Exception(message)

val WalkieJson: Json = Json {
    ignoreUnknownKeys = true
    explicitNulls = false
    encodeDefaults = true
}

/**
 * OkHttp client with a bearer-token interceptor. The token is read
 * synchronously from an in-memory cache (SessionStore keeps it primed), so the
 * interceptor never blocks on DataStore.
 */
fun buildOkHttp(tokenProvider: () -> String?): OkHttpClient =
    OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        // Long-poll (?wait=1) holds the response up to ~18s server-side.
        .readTimeout(35, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        .addInterceptor { chain ->
            val b = chain.request().newBuilder().header("Accept", "application/json")
            tokenProvider()?.let { b.header("Authorization", "Bearer $it") }
            chain.proceed(b.build())
        }
        .build()

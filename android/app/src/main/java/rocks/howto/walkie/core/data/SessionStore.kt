package rocks.howto.walkie.core.data

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.runBlocking
import rocks.howto.walkie.core.network.User

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "walkie")

/**
 * Persists the session token + user profile. Keeps the token in a volatile
 * in-memory cache so the OkHttp interceptor can read it without suspending.
 */
class SessionStore(private val context: Context) {

    private val kToken = stringPreferencesKey("token")
    private val kUid = longPreferencesKey("uid")
    private val kName = stringPreferencesKey("name")
    private val kEmail = stringPreferencesKey("email")

    @Volatile var cachedToken: String? = null
        private set

    val userFlow: Flow<User?> = context.dataStore.data.map { p ->
        val id = p[kUid] ?: return@map null
        User(id = id, email = p[kEmail], displayName = p[kName].orEmpty())
    }

    val tokenFlow: Flow<String?> = context.dataStore.data.map { it[kToken] }

    /** Load the token into the cache once at startup (blocking, tiny read). */
    fun prime() {
        cachedToken = runBlocking { context.dataStore.data.first()[kToken] }
    }

    suspend fun save(token: String, user: User) {
        cachedToken = token
        context.dataStore.edit { p ->
            p[kToken] = token
            p[kUid] = user.id
            p[kName] = user.displayName
            user.email?.let { p[kEmail] = it }
        }
    }

    suspend fun updateUser(user: User) {
        context.dataStore.edit { p ->
            p[kUid] = user.id
            p[kName] = user.displayName
            user.email?.let { p[kEmail] = it }
        }
    }

    suspend fun currentUser(): User? = userFlow.first()

    suspend fun clear() {
        cachedToken = null
        context.dataStore.edit { it.clear() }
    }

    /** Synchronous clear for the 401 interceptor path. */
    fun clearBlocking() = runBlocking { clear() }
}

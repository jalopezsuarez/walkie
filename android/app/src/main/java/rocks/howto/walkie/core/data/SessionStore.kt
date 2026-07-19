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
 * Persists the OAuth 2.0 tokens + user profile. The access/refresh tokens are
 * mirrored in volatile fields so the OkHttp interceptor can read the access
 * token without suspending.
 */
class SessionStore(private val context: Context) {

    private val kAccess = stringPreferencesKey("access")
    private val kRefresh = stringPreferencesKey("refresh")
    private val kUid = longPreferencesKey("uid")
    private val kName = stringPreferencesKey("name")
    private val kEmail = stringPreferencesKey("email")

    @Volatile var cachedAccess: String? = null
        private set
    @Volatile var cachedRefresh: String? = null
        private set

    val userFlow: Flow<User?> = context.dataStore.data.map { p ->
        val id = p[kUid] ?: return@map null
        User(id = id, email = p[kEmail], displayName = p[kName].orEmpty())
    }

    /** Load the tokens into the cache once at startup (blocking, tiny read). */
    fun prime() = runBlocking {
        val p = context.dataStore.data.first()
        cachedAccess = p[kAccess]
        cachedRefresh = p[kRefresh]
    }

    suspend fun saveTokens(access: String?, refresh: String?) {
        cachedAccess = access
        cachedRefresh = refresh
        context.dataStore.edit { p ->
            if (access != null) p[kAccess] = access else p.remove(kAccess)
            if (refresh != null) p[kRefresh] = refresh else p.remove(kRefresh)
        }
    }

    suspend fun saveUser(user: User) {
        context.dataStore.edit { p ->
            p[kUid] = user.id
            p[kName] = user.displayName
            user.email?.let { p[kEmail] = it }
        }
    }

    suspend fun currentUser(): User? = userFlow.first()

    suspend fun clear() {
        cachedAccess = null
        cachedRefresh = null
        context.dataStore.edit { it.clear() }
    }

    /** Synchronous clear for the interceptor's failed-refresh path. */
    fun clearBlocking() = runBlocking { clear() }
}

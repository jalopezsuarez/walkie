package rocks.howto.walkie.feature.settings

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import rocks.howto.walkie.core.di.AppContainer

class SettingsViewModel(private val container: AppContainer) : ViewModel() {

    var name by mutableStateOf("")
    var saving by mutableStateOf(false)
        private set
    var toast by mutableStateOf<String?>(null)

    init {
        viewModelScope.launch {
            container.session.currentUser()?.let { name = it.displayName }
        }
    }

    fun save() {
        val trimmed = name.trim()
        if (trimmed.isEmpty()) return
        saving = true
        viewModelScope.launch {
            try {
                val user = container.api.updateName(trimmed)
                container.session.saveUser(user)
                toast = "Guardado"
            } catch (e: Exception) {
                toast = "No se pudo guardar"
            } finally {
                saving = false
            }
        }
    }

    fun logout(onSignedOut: () -> Unit) {
        viewModelScope.launch {
            container.api.logout() // revokes the refresh token and clears the session
            onSignedOut()
        }
    }
}

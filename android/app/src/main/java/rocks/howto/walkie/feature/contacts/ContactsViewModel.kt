package rocks.howto.walkie.feature.contacts

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import rocks.howto.walkie.core.di.AppContainer
import rocks.howto.walkie.core.network.Contact

class ContactsViewModel(private val container: AppContainer) : ViewModel() {

    var contacts by mutableStateOf<List<Contact>>(emptyList())
        private set
    var loading by mutableStateOf(true)
        private set
    var error by mutableStateOf<String?>(null)
        private set

    init {
        viewModelScope.launch {
            while (isActive) {
                try {
                    contacts = container.api.links()
                    error = null
                } catch (e: Exception) {
                    if (contacts.isEmpty()) error = "No se pudo cargar"
                } finally {
                    loading = false
                }
                delay(8_000)
            }
        }
    }
}

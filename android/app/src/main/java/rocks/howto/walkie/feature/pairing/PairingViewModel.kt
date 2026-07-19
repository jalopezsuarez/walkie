package rocks.howto.walkie.feature.pairing

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import rocks.howto.walkie.core.di.AppContainer
import rocks.howto.walkie.core.network.QrResponse

class PairingViewModel(private val container: AppContainer) : ViewModel() {

    var qr by mutableStateOf<QrResponse?>(null)
        private set
    var loading by mutableStateOf(true)
        private set
    var error by mutableStateOf<String?>(null)
        private set

    init { refreshQr() }

    fun refreshQr() {
        loading = true; error = null
        viewModelScope.launch {
            try {
                qr = container.api.createQr()
            } catch (e: Exception) {
                error = "No se pudo generar el QR"
            } finally {
                loading = false
            }
        }
    }

    fun claim(token: String, onPaired: () -> Unit) {
        viewModelScope.launch {
            try {
                container.api.claim(token)
                onPaired()
            } catch (e: Exception) {
                error = "QR no válido o caducado"
            }
        }
    }
}

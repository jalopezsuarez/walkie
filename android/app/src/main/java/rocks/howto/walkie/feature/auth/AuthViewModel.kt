package rocks.howto.walkie.feature.auth

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.launch
import rocks.howto.walkie.core.di.AppContainer
import rocks.howto.walkie.core.network.ApiException

class AuthViewModel(private val container: AppContainer) : ViewModel() {

    enum class Step { EMAIL, CODE }

    var email by mutableStateOf("")
        private set
    var code by mutableStateOf("")
        private set
    var step by mutableStateOf(Step.EMAIL)
        private set
    var loading by mutableStateOf(false)
        private set
    var error by mutableStateOf<String?>(null)
        private set

    fun onEmail(v: String) { email = v.trim(); error = null }
    fun onCode(v: String) { code = v.filter { it.isDigit() }.take(6); error = null }
    fun back() { step = Step.EMAIL; error = null }

    fun requestCode() {
        if (!email.contains("@")) { error = "Introduce un email válido"; return }
        loading = true; error = null
        viewModelScope.launch {
            try {
                container.api.requestCode(email)
                step = Step.CODE
            } catch (e: ApiException) {
                error = mapError(e)
            } catch (e: Exception) {
                error = "Sin conexión"
            } finally {
                loading = false
            }
        }
    }

    fun verify(onAuthed: () -> Unit) {
        if (code.length < 4) { error = "Código incompleto"; return }
        loading = true; error = null
        viewModelScope.launch {
            try {
                val res = container.api.verify(email, code)
                container.session.save(res.token, res.user)
                onAuthed()
            } catch (e: ApiException) {
                error = if (e.code == "invalid_code") "Código incorrecto" else mapError(e)
            } catch (e: Exception) {
                error = "Sin conexión"
            } finally {
                loading = false
            }
        }
    }

    private fun mapError(e: ApiException): String = when (e.code) {
        "rate_limited" -> "Demasiados intentos, espera un momento."
        else -> e.message
    }
}

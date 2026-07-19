package rocks.howto.walkie.nav

import androidx.activity.compose.BackHandler
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.viewmodel.initializer
import androidx.lifecycle.viewmodel.viewModelFactory
import rocks.howto.walkie.WalkieApp
import rocks.howto.walkie.core.di.AppContainer
import rocks.howto.walkie.feature.auth.AuthScreen
import rocks.howto.walkie.feature.contacts.ContactsScreen
import rocks.howto.walkie.feature.conversation.ConversationScreen
import rocks.howto.walkie.feature.pairing.PairingScreen
import rocks.howto.walkie.feature.settings.SettingsScreen

/** Minimal navigation model — a small explicit back stack, no extra library. */
sealed interface Screen {
    data object Auth : Screen
    data object Contacts : Screen
    data object Pairing : Screen
    data object Settings : Screen
    data class Conversation(val linkId: Long, val name: String) : Screen
}

@Composable
fun appContainer(): AppContainer =
    (LocalContext.current.applicationContext as WalkieApp).container

/** Build a ViewModel wired to the app container. */
@Composable
inline fun <reified VM : ViewModel> screenViewModel(crossinline create: (AppContainer) -> VM): VM {
    val container = appContainer()
    return viewModel(factory = viewModelFactory { initializer { create(container) } })
}

@Composable
fun AppRoot(initialLinkId: Long? = null) {
    val container = appContainer()
    val signedIn by remember { mutableStateOf(container.session.cachedAccess != null) }

    val stack = remember {
        mutableStateListOf<Screen>().apply {
            add(if (signedIn) Screen.Contacts else Screen.Auth)
            if (signedIn && initialLinkId != null) add(Screen.Conversation(initialLinkId, ""))
        }
    }

    fun push(s: Screen) { stack.add(s) }
    fun replaceAll(s: Screen) { stack.clear(); stack.add(s) }
    fun pop() { if (stack.size > 1) stack.removeAt(stack.lastIndex) }

    BackHandler(enabled = stack.size > 1) { pop() }

    when (val current = stack.last()) {
        Screen.Auth -> AuthScreen(onAuthed = {
            container.deviceRegistrar.syncToken()
            replaceAll(Screen.Contacts)
        })

        Screen.Contacts -> ContactsScreen(
            onOpen = { c -> push(Screen.Conversation(c.linkId, c.displayName)) },
            onInvite = { push(Screen.Pairing) },
            onSettings = { push(Screen.Settings) },
        )

        Screen.Pairing -> PairingScreen(
            onBack = { pop() },
            onPaired = { pop() },
        )

        Screen.Settings -> SettingsScreen(
            onBack = { pop() },
            onSignedOut = { replaceAll(Screen.Auth) },
        )

        is Screen.Conversation -> ConversationScreen(
            linkId = current.linkId,
            initialName = current.name,
            onBack = { pop() },
        )
    }
}

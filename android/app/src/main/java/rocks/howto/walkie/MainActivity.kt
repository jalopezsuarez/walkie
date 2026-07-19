package rocks.howto.walkie

import android.Manifest
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.LaunchedEffect
import androidx.core.content.ContextCompat
import android.content.pm.PackageManager
import rocks.howto.walkie.core.designsystem.WalkieTheme
import rocks.howto.walkie.nav.AppRoot

class MainActivity : ComponentActivity() {

    companion object {
        const val EXTRA_LINK_ID = "link_id"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val linkId = intent?.getLongExtra(EXTRA_LINK_ID, -1L)?.takeIf { it > 0 }

        setContent {
            WalkieTheme {
                RequestNotificationPermission()
                AppRoot(initialLinkId = linkId)
            }
        }
    }

    @androidx.compose.runtime.Composable
    private fun RequestNotificationPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {}
        LaunchedEffect(Unit) {
            val granted = ContextCompat.checkSelfPermission(this@MainActivity, Manifest.permission.POST_NOTIFICATIONS) ==
                PackageManager.PERMISSION_GRANTED
            if (!granted) launcher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }
}

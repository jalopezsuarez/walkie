import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.compose.compiler)
}

// Firebase (FCM). Apply only when google-services.json is present so the project
// still builds for contributors who haven't set up Firebase yet. (Conditionals
// aren't allowed inside the plugins {} block, hence this apply() form.)
if (rootProject.file("app/google-services.json").exists()) {
    apply(plugin = "com.google.gms.google-services")
}

// Base URL of the existing Walkie API. Override in local.properties with
// `walkie.apiBase=https://walkie.howto.rocks/api` if you point elsewhere.
val apiBase: String = Properties().apply {
    val f = rootProject.file("local.properties")
    if (f.exists()) f.inputStream().use { load(it) }
}.getProperty("walkie.apiBase", "https://walkie.howto.rocks/api")

android {
    namespace = "rocks.howto.walkie"
    compileSdk = 35

    defaultConfig {
        applicationId = "rocks.howto.walkie"
        minSdk = 29            // Android 10 — ~78% device reach, keeps OGG/Opus recording
        targetSdk = 35
        versionCode = 1
        versionName = "1.0.0"
        buildConfigField("String", "API_BASE", "\"$apiBase\"")
    }

    // Release signing. Reads a keystore from env (CI) or gradle.properties
    // (local). When none is configured the release build is left unsigned.
    val keystorePath = System.getenv("WALKIE_KEYSTORE") ?: (project.findProperty("walkie.keystore") as String?)
    val hasKeystore = keystorePath != null && file(keystorePath).exists()
    signingConfigs {
        if (hasKeystore) {
            create("release") {
                storeFile = file(keystorePath!!)
                storePassword = System.getenv("WALKIE_KEYSTORE_PASSWORD") ?: project.findProperty("walkie.keystorePassword") as String?
                keyAlias = System.getenv("WALKIE_KEY_ALIAS") ?: project.findProperty("walkie.keyAlias") as String? ?: "walkie"
                keyPassword = System.getenv("WALKIE_KEY_PASSWORD") ?: project.findProperty("walkie.keyPassword") as String?
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            if (hasKeystore) signingConfig = signingConfigs.getByName("release")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }
    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.activity.compose)

    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.ui)
    implementation(libs.androidx.ui.graphics)
    implementation(libs.androidx.material3)
    implementation(libs.androidx.material.icons.extended)

    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.kotlinx.serialization.json)

    implementation(libs.okhttp)

    implementation(libs.androidx.datastore.preferences)

    implementation(libs.zxing.core)
    implementation(libs.zxing.embedded)

    // Firebase Cloud Messaging (official Google push).
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)
}

# kotlinx.serialization keeps generated serializers via @Serializable.
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keepclassmembers class rocks.howto.walkie.** {
    *** Companion;
}
-keepclasseswithmembers class rocks.howto.walkie.** {
    kotlinx.serialization.KSerializer serializer(...);
}
# OkHttp
-dontwarn okhttp3.**
-dontwarn okio.**

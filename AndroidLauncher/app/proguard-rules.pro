# kotlinx.serialization
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keepclassmembers class com.athenastudios.launcher.data.** { *; }
-keep,includedescriptorclasses class com.athenastudios.launcher.**$$serializer { *; }
-keepclassmembers class com.athenastudios.launcher.** {
    *** Companion;
}

package com.athenastudios.launcher.core

import android.content.Context
import android.content.Intent
import android.net.Uri
import com.athenastudios.launcher.AppConfig

/**
 * Minecraft Java motorunu çalıştırma katmanı.
 *
 * Android'de Java Minecraft'ı fiilen çalıştıran motor (özel JRE + GL4ES/LWJGL)
 * MojoLauncher'dan gelir; bu sınıf iki entegrasyon stratejisi sunar (bkz. MOBILE.md).
 */
object GameLauncher {

    /**
     * (A) HANDOFF: MojoLauncher kuruluysa onu başlat. Modpack zaten gameDir'e
     * senkronlandığı için MojoLauncher 1.12.2 Forge profilini açar. Kurulu değilse
     * indirme sayfasını açar. true = handoff yapıldı.
     */
    fun handoffToMojo(context: Context): Boolean {
        val launch = context.packageManager.getLaunchIntentForPackage(AppConfig.MOJO_PACKAGE)
        return if (launch != null) {
            launch.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            context.startActivity(launch)
            true
        } else {
            context.startActivity(
                Intent(Intent.ACTION_VIEW, Uri.parse(AppConfig.MOJO_SITE))
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            )
            false
        }
    }

    /**
     * (B) EMBED: Tam kilitli tek-APK için MojoLauncher fork'unun motoru
     * (forge_installer + JVMLauncher) buradan çağrılır. Bu projede TODO —
     * fork'u temel alıp bu çağrıyı bağlayın (MOBILE.md, Seçenek B).
     */
    fun launchEmbedded(context: Context): Nothing =
        throw NotImplementedError("Gömülü motor MojoLauncher fork'undan eklenecek (MOBILE.md Seçenek B).")
}

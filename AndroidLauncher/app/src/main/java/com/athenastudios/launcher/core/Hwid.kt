package com.athenastudios.launcher.core

import android.annotation.SuppressLint
import android.content.Context
import android.os.Build
import android.provider.Settings
import java.security.MessageDigest

object Hwid {
    @SuppressLint("HardwareIds")
    fun get(context: Context): String {
        val androidId = try {
            Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID)
        } catch (e: Exception) {
            null
        } ?: "unknown"
        val seed = "$androidId|${Build.MANUFACTURER}|${Build.MODEL}"
        val digest = MessageDigest.getInstance("MD5").digest(seed.toByteArray())
        return digest.joinToString("") { "%02x".format(it) }
    }
}

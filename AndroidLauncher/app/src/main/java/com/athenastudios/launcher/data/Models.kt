package com.athenastudios.launcher.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class ServerConfig(
    @SerialName("ServerIp") val serverIp: String = "oyna.athenastudios.com.tr",
    @SerialName("AutoConnect") val autoConnect: Boolean = true,
    @SerialName("MinecraftVersion") val minecraftVersion: String = "1.12.2",
    @SerialName("LoaderType") val loaderType: String = "Forge",
    @SerialName("LoaderVersion") val loaderVersion: String = "14.23.5.2860",
    @SerialName("MaxRamMb") val maxRamMb: Int = 4096,
    @SerialName("MinRamMb") val minRamMb: Int = 2048,
)

@Serializable
data class VersionInfo(
    @SerialName("Version") val version: Int = 0,
    @SerialName("Note") val note: String = "",
    @SerialName("UpdatedAt") val updatedAt: String = "",
)

@Serializable
data class OptionalMod(
    @SerialName("Id") val id: String = "",
    @SerialName("Name") val name: String = "",
    @SerialName("Description") val description: String = "",
    @SerialName("Path") val path: String = "",
    @SerialName("Hash") val hash: String = "",
    @SerialName("Size") val size: Long = 0,
    @SerialName("Default") val default: Boolean = false,
)

@Serializable
data class ModsCatalog(
    @SerialName("Optional") val optional: List<OptionalMod> = emptyList(),
)

@Serializable
data class PackageFile(
    @SerialName("Path") val path: String = "",
    @SerialName("Hash") val hash: String = "",
    @SerialName("Size") val size: Long = 0,
)

@Serializable
data class PingResult(
    @SerialName("status") val status: String = "offline",
    @SerialName("players_online") val playersOnline: Int = 0,
    @SerialName("players_max") val playersMax: Int = 0,
    @SerialName("latency") val latency: Int = 0,
)

@Serializable
data class BanResult(
    @SerialName("banned") val banned: Boolean = false,
    @SerialName("reason") val reason: String? = null,
)

@Serializable
data class ChangelogEntry(
    @SerialName("Version") val version: Int = 0,
    @SerialName("Timestamp") val timestamp: String = "",
    @SerialName("Note") val note: String = "",
)

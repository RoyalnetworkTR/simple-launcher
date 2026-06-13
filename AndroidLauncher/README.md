# Athena Studios Launcher — Mobil (Android)

Athena'ya **kilitli**, sıfırdan yazılmış Android uygulaması (Kotlin + Jetpack Compose).
Masaüstü launcher ile **aynı backend**'i (`https://oyna.athenastudios.com.tr`) kullanır:
sunucu durumu, sürüm/changelog, opsiyonel modlar, ban kontrolü, metrik — ve modpack'i
indirip **SHA-256** ile doğrular. Yalnızca **1.12.2 Forge + oyna.athenastudios.com.tr**.

> **Motor notu:** Android'de Java Minecraft'ı fiilen çalıştıran motor (JRE + GL4ES)
> bu uygulamada yoktur; o iş **MojoLauncher**'a aittir. Bu uygulama "Athena katmanı"dır
> (marka + backend + modpack + kilit) ve iki şekilde motora bağlanır (`GameLauncher.kt`):
> **(A) Handoff** — kurulu MojoLauncher'ı başlatır (modpack zaten senkronlanmıştır);
> **(B) Embed** — MojoLauncher fork'una modül olarak gömülerek tek, tam-kilitli APK.

## Derleme

Gereksinimler: **Android Studio** (veya komut satırı), **JDK 17**, Android SDK 34.

```bash
cd AndroidLauncher
# Android Studio ile açın VEYA:
gradle assembleDebug          # APK: app/build/outputs/apk/debug/app-debug.apk
```

> Bu Windows geliştirme makinesinde Android SDK yoktur; bu yüzden APK'yı **GitHub
> Actions** derler. Repo kökündeki `.github/workflows/android-release.yml` push'lanan
> `v*` etiketinde APK'yı CI'da derler ve **otomatik Release** olarak yayınlar.
> Bkz. ana `MOBILE.md`.

İsteğe bağlı: `app/src/main/res/` altına gerçek bir logo eklemek için
`images/athlogonoback.png`'yi `drawable/athena_logo.png` yapıp `AppRoot.kt`'te
`R.drawable.ic_launcher_foreground` yerine kullanın.

## Yapı
```
app/src/main/java/com/athenastudios/launcher/
├── AppConfig.kt            # BASE_URL + sabitler (MojoLauncher paket adı)
├── MainActivity.kt         # Compose host
├── AppViewModel.kt         # durum + boot/ping/play akışı
├── data/
│   ├── Models.kt           # @Serializable JSON modelleri (PascalCase)
│   └── BackendClient.kt    # OkHttp + kotlinx.serialization (tüm uçlar)
├── core/
│   ├── Hwid.kt             # kararlı cihaz kimliği (ban için)
│   ├── ModpackInstaller.kt # indirme + SHA-256 doğrulama + mods/ beyaz liste
│   └── GameLauncher.kt     # motor handoff (MojoLauncher) / embed (TODO)
└── ui/
    ├── theme/Theme.kt      # Athena dark Material3 teması
    └── AppRoot.kt          # Ana Sayfa + Modlar + ban ekranı
```

## Kilit modeli
- Sürüm/profil seçimi yoktur — sabit **1.12.2 Forge**, sunucu **oyna.athenastudios.com.tr**.
- **Zorunlu** modlar (`/api/index.json`) her zaman indirilir + SHA-256 doğrulanır (anti-cheat).
- **Opsiyonel** modlar (`/api/mods.json`) "Modlar" sekmesinden açılır/kapanır.
- `mods/` klasörü beyaz liste ile zorlanır: izinsiz/hile jar'lar senkronizasyonda silinir.

## Engine entegrasyonu (tam kilitli tek APK)
MojoLauncher (LGPLv3) fork'u önerilir; ayrıntılı adımlar ve hazır CI workflow için
ana **`MOBILE.md`** (Seçenek B). Hemen oynanabilir yol için (kod yok): MojoLauncher'a
`https://oyna.athenastudios.com.tr/api/modpack.mrpack` adresini içe aktarın.

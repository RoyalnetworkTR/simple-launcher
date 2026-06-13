# Athena Studios Launcher — Telefon (Android) Sürümü

Bu belge, launcher'ın **telefonda** (Android) çalışması için 3 katmanı açıklar.
Hedef: oyuncular **yalnızca Athena modpack'ini** (Minecraft **1.12.2 Forge**) yükleyip
**oyna.athenastudios.com.tr** sunucusuna girsin.

> **Önemli teknik gerçek (dürüstçe):** Android'de Minecraft **Java Edition**'ı
> çalıştırmak (özel JRE + OpenGL ES çevirisi + LWJGL) devasa bir **native motor**
> işidir. Bu motoru "sıfırdan" yazmak gerçekçi değildir. En iyi çalışan açık
> kaynak motor **MojoLauncher**'dır (LGPLv3, PojavLauncher tabanlı). Dolayısıyla
> "sıfırdan APK" = **MojoLauncher'ı fork'layıp Athena'ya kilitlemek** ve kendi
> markamızla, kendi backend'imizle, GitHub Actions ile **otomatik APK
> derleyip yayınlamak** demektir. Aşağıdaki üç seçenek bunu kapsar.

---

## Seçenek A — Hemen çalışan yol (kod yok, 5 dakika)

Backend yayında olduğunda (bkz. `WebBackend/deploy.sh`) telefon kullanıcısı:

1. **MojoLauncher**'ı kurar — <https://mojolauncher.org/> / <https://github.com/mojolauncher/mojolauncher>
2. Modpack içe aktarma ekranından şu **.mrpack** adresini verir:
   ```
   https://oyna.athenastudios.com.tr/api/modpack.mrpack
   ```
3. MojoLauncher 1.12.2 Forge'u kurar, Athena modlarını + configleri indirir, sunucuya girilir.

Bu uç (`/api/modpack.mrpack`) backend tarafından **otomatik** üretilir: `files/`
içindeki modlardan standart **Modrinth modpack** (sha1+sha512 + indirme URL'leri,
configler `overrides/` altında, `dependencies: {minecraft:1.12.2, forge:14.23.5.2860}`).
PrismLauncher, ATLauncher, Modrinth App ve Pojav/Amethyst de aynı dosyayı içe aktarabilir.

> Bu yol **kilitli değildir** (kullanıcı başka şey de kurabilir). Tam kilit için Seçenek B.

---

## Seçenek B — Kilitli özel APK (MojoLauncher fork) ⭐ önerilen

Kendi markalı, **yalnızca Athena**'ya kilitli APK. Adımlar:

1. **Fork'la:** <https://github.com/mojolauncher/mojolauncher> (LGPLv3 — fork'unuz da LGPLv3 kalmalı).
2. **Özelleştir** (`app_pojavlauncher` modülü):
   - **Marka:** uygulama adı "Athena Studios", ikon (`minecraft.ico`/logo), renkler (#0E1116 / #2F81F7).
   - **Otomatik modpack:** ilk açılışta backend'den modpack'i indir. En kolayı:
     açılışta `https://oyna.athenastudios.com.tr/api/modpack.mrpack`'i MojoLauncher'ın
     mevcut **modpack import** akışına programatik ver (kullanıcıya seçtirmeden).
   - **Kilit:** sürüm/hesap/profil seçim ekranlarını gizle; doğrudan **1.12.2 Forge**
     profilini başlat ve **oyna.athenastudios.com.tr**'ye otomatik bağlan
     (`servers.dat`'a sunucuyu yaz veya başlatma argümanına `--server` ekle).
   - **Anti-cheat:** modları indirdikten sonra SHA-256 doğrula (backend `/api/index.json`).
3. **CI ile otomatik release:** fork'a aşağıdaki workflow'u ekleyin (build komutu
   MojoLauncher'a göre `:app_pojavlauncher:assembleDebug`):
   ```yaml
   name: Athena APK
   on: { push: { tags: ['v*'] }, workflow_dispatch: {} }
   permissions: { contents: write }
   jobs:
     build:
       runs-on: ubuntu-latest
       steps:
         - uses: actions/checkout@v4
           with: { submodules: recursive }
         - uses: actions/setup-java@v4
           with: { distribution: temurin, java-version: '17' }
         - uses: android-actions/setup-android@v3
         - run: chmod +x gradlew && ./gradlew :app_pojavlauncher:assembleDebug --no-daemon --stacktrace
         - uses: softprops/action-gh-release@v2
           with:
             files: app_pojavlauncher/build/outputs/apk/**/*.apk
           env: { GITHUB_TOKEN: '${{ secrets.GITHUB_TOKEN }}' }
   ```
   Artık `git tag v1.0.0 && git push --tags` → APK otomatik derlenir ve Release'e eklenir.

Bu, **yerel Android SDK gerektirmez** — derlemeyi GitHub runner'ı yapar.

---

## Seçenek C — Athena companion uygulaması (`AndroidLauncher/`)

Bu repodaki `AndroidLauncher/` — **sıfırdan** yazılmış Athena markalı Kotlin/Compose
uygulaması (Athena katmanı): backend'e bağlanır (config/sürüm/durum/haber/ban/metrik),
modpack'i indirir + **SHA-256** doğrular, ve motoru (MojoLauncher) çağırır/gömer.
Detaylar ve derleme: `AndroidLauncher/README.md`.

Bu projeyi GitHub Actions ile derleyip yayınlamak için repo kökündeki workflow hazırdır:
**`.github/workflows/android-release.yml`** (`v*` tag push'unda APK üretir + Release'e ekler).

> Not: Companion uygulamanın **arayüz + backend + modpack** katmanı tamdır; ancak
> Minecraft'ı fiilen çalıştıran **motor** MojoLauncher fork'undan gelir (Seçenek B).
> "Tek APK, tam kilitli" sonucu için Seçenek B + C birleştirilir (Athena katmanı
> fork'a modül olarak eklenir).

---

## Backend tarafı (zaten hazır)

- `GET /api/modpack.mrpack` — Athena modpack'i (Modrinth formatı) — **otomatik üretilir, test edildi**.
- Aynı backend masaüstü launcher ile ortaktır: `/api/server_config.json`, `/api/index.json`,
  `/api/mods.json`, `/api/version.json`, `/api/ping`, `/api/check_ban`, `/api/metric/*`.
- Kurulum: `WebBackend/deploy.sh` (nginx + PHP-FPM + **php-zip** + certbot). `php-zip`
  `.mrpack` üretimi için gereklidir (deploy script'inde kuruludur).

---

## Lisans / kaynaklar
- MojoLauncher (LGPLv3): <https://github.com/mojolauncher/mojolauncher>
- PojavLauncher (halefi Amethyst): <https://github.com/PojavLauncherTeam/PojavLauncher> · <https://github.com/AngelAuraMC/Amethyst-Android>
- Modrinth .mrpack biçimi: <https://support.modrinth.com/en/articles/8802351-modrinth-modpack-format-mrpack>

MojoLauncher/Pojav **LGPLv3/GPLv3** olduğundan, fork'unuzu da aynı lisansla ve kaynak
kodunu erişilebilir tutarak yayınlamalısınız.

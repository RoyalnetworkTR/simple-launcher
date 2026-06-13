<div align="center">

# Athena Studios Launcher

**1.12.2 Forge modpack başlatıcısı** — özel sunucu `oyna.athenastudios.com.tr` için.

Otomatik modpack senkronizasyonu · İsteğe bağlı mod seçimi · SHA-256 anti-cheat · Dinamik PHP backend + web yönetim paneli

</div>

---

## Hakkında

Athena Studios Launcher; oyuncuların tek tıkla **Minecraft 1.12.2 Forge** modpack'ini
indirip `oyna.athenastudios.com.tr` sunucusuna bağlanmasını sağlayan bir başlatıcıdır.
Modpack içeriği bir PHP backend tarafından **dinamik** olarak sunulur: yönetici
`files/` klasörüne dosyaları bırakır, sistem otomatik olarak istemci paketini
tanımlar, SHA-256 özetlerini çıkarır ve istemciye sunar.

> Powered by [CmlLib.Core](https://github.com/CmlLib/CmlLib.Core).

## Özellikler

- 🎮 **1.12.2 Forge** modpack'ini otomatik kurar ve çalıştırır (Java 8 / `jre-legacy` otomatik çözülür).
- 🔄 **Otomatik senkronizasyon** — istemci her açılışta güncel paketi çeker; yalnızca değişen dosyaları indirir.
- 🧩 **İsteğe bağlı modlar** — oyuncular "Modlar" ekranından izin verilen opsiyonel modları seçer; sistem otomatik kurar.
- 🔒 **SHA-256 anti-cheat** — zorunlu modlar/dosyalar SHA-256 ile kilitlenir; `mods/` klasörü beyaz liste ile zorlanır, hile/ekstra jar'lar silinir.
- 📋 **Otomatik sürüm notları** — paket içeriği değişince sürüm otomatik artar ve changelog üretilir; launcher oyunculara gösterir.
- 🛡️ **HWID ban** sistemi ve ayrıntılı kullanım metrikleri.
- 🖥️ **Web yönetim paneli** — yapılandırma, mod sınıflandırma, sürüm geçmişi, istatistikler, ban yönetimi.
- ⚙️ Ayarlanabilir RAM / Java yolu / JVM argümanları.

## Mimari

```
simple-launcher/
├── *.cs, *.xaml, *.csproj # Athena Studios Launcher (C# / .NET 9 WPF istemcisi)
│   ├── App.xaml(.cs)       # Giriş noktası, tema, global hata yakalama
│   ├── MainWindow.xaml     # Özel başlık çubuğu + kenar çubuğu navigasyon + boot akışı
│   ├── Theme/Brand.xaml    # Marka dark teması (renkler, buton/slider/toggle stilleri)
│   ├── Pages/              # Home, Mods, Settings, News sayfaları (UserControl)
│   ├── Services/LauncherCore.cs # Başlatma akışı: ban, ping, sync, Forge, telemetri
│   ├── AppConfig.cs        # Backend URL + uygulama veri klasörü + sosyal bağlantılar
│   ├── PackageManager.cs   # SHA-256 doğrulama + opsiyonel mod senkronizasyonu
│   ├── Character.cs        # Kullanıcı adından deterministik skin/UUID
│   └── tools/make_icon.ps1 # Marka görselinden çok-çözünürlüklü .ico üretici
└── WebBackend/             # PHP 8.4 backend (Python/Flask'in yerini aldı)
    ├── public/index.php    # Yönlendirici + tüm API uçları
    ├── src/                # config, db, ping, builder (dinamik index+changelog), admin
    ├── cli/build_index.php # cron için index üretici
    └── files/              # yöneticinin içerik bıraktığı klasör (1.12.2 Forge dosyaları)
```

İstemci ile sunucu sözleşmesi (JSON uçları, opsiyonel mod modeli, anti-cheat)
ve dağıtım rehberi için **[WebBackend/README.md](WebBackend/README.md)**.

## Launcher Kullanımı (oyuncu)

1. Başlatıcıyı açın, kullanıcı adınızı girin.
2. İsterseniz **Modlar** ile opsiyonel modları seçin, **Ayarlar** ile RAM'i ayarlayın.
3. **OYNA**'ya basın — modpack indirilir/doğrulanır, Forge kurulur, sunucuya bağlanır.

## Backend Kurulumu (yönetici)

Tam rehber: **[WebBackend/README.md](WebBackend/README.md)**. Özetle:

1. `WebBackend/public` dizinini web kökü yapın (Apache vhost veya nginx + php-fpm).
2. 1.12.2 Forge istemci dosyalarınızı (`mods/`, `config/`, forge kütüphaneleri, vb.)
   `WebBackend/files/` içine bırakın.
3. Yönetim panelinden (`/admin?password=...`) modları zorunlu/isteğe bağlı olarak
   sınıflandırın ve gerekiyorsa yapılandırmayı düzenleyin.
4. Sistem `files/` içeriğini otomatik tarar, `index.json` + `mods.json` üretir;
   her değişiklikte sürüm artar ve changelog'a not düşülür.

> ⚠️ İstemcideki **`AppConfig.BackendUrl`** sabitini gerçek backend adresinize göre
> ayarlayın (varsayılan: `https://oyna.athenastudios.com.tr`).

## Geliştirme / Derleme

```powershell
# İstemci (.NET 9 SDK gerekir)
dotnet build OfflineMinecraftLauncher.csproj -c Debug

# Backend yerel testi (PHP 8.4)
php -S 127.0.0.1:8099 -t WebBackend/public WebBackend/public/index.php
```

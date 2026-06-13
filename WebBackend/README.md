# Athena Studios Launcher — Web Backend (PHP)

Bu dizin, **Athena Studios Launcher** istemcisinin arka uç servisidir. Eski
Python/Flask servisinin yerini alır. Tek bir tutarlı PHP uygulamasıdır:
dosya sunucusu, sürüm/yapılandırma API'leri, Minecraft ping vekili, metrik
toplama, HWID ban kontrolü ve marka odaklı bir yönetim paneli.

- **Hedef ortam:** Linux VPS, **PHP 8.4**, PDO SQLite, Apache veya nginx + php-fpm.
- **Anti-cheat modeli:** zorunlu dosyalar SHA-256 ile doğrulanır; modlar
  isteğe bağlı/zorunlu olarak sınıflandırılır.

---

## Dizin Yapısı

```
WebBackend/
├── public/          # Web kökü (DocumentRoot burayı göstermeli)
│   ├── index.php    # Ön denetleyici / yönlendirici
│   └── .htaccess    # Apache yönlendirme kuralları
├── src/             # Uygulama mantığı (config, db, ping, builder, admin)
├── cli/             # Komut satırı araçları (build_index.php -> cron)
├── seed/            # Varsayılan şablonlar (server_config, mods_classification)
├── data/            # SQLite veritabanı (metrics.db) — gizli, web'e açılmaz
├── files/           # Yöneticinin içerik bıraktığı klasör (git'te yok, web'e açık)
└── nginx.conf.sample
```

> **Önemli:** Web kökü (DocumentRoot / nginx `root`) **`WebBackend/public`**
> dizinini göstermelidir; `WebBackend` kökünü değil. Böylece `data/`, `seed/`,
> `src/` ve `cli/` dizinleri internetten erişilemez kalır.

---

## Kurulum

### Apache (mod_php veya php-fpm)

`public/.htaccess` dosyası ön denetleyici yönlendirmesini sağlar. `AllowOverride All`
gereklidir. Örnek sanal sunucu (vhost):

```apache
<VirtualHost *:80>
    ServerName oyna.athenastudios.com.tr
    DocumentRoot /var/www/athena/WebBackend/public

    <Directory /var/www/athena/WebBackend/public>
        AllowOverride All
        Require all granted
    </Directory>

    # data/ ve seed/ web'e açılmamalı (public dışında oldukları için zaten erişilemez)
</VirtualHost>
```

`mod_rewrite` etkin olmalıdır: `a2enmod rewrite && systemctl reload apache2`.

### nginx + php-fpm

`nginx.conf.sample` dosyasını kopyalayıp uyarlayın (`server_name`, `root`,
`fastcgi_pass`). Özet:

```nginx
root /var/www/athena/WebBackend/public;
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}
```

### İzinler

`php-fpm`/Apache çalıştıran kullanıcı `data/` ve `files/` dizinlerine yazabilmelidir:

```bash
chown -R www-data:www-data WebBackend/data WebBackend/files
chmod -R u+rwX WebBackend/data WebBackend/files
```

---

## İçerik Yükleme (`files/`)

İstemciye sunulacak tüm oyun dosyalarını doğrudan `files/` içine bırakın; alt
klasör yapısı istemcideki dizin yapısıyla aynıdır:

```
files/
├── mods/        # tüm mod .jar dosyaları (zorunlu VE isteğe bağlı) burada durur
├── config/
├── ...          # forge kütüphaneleri, kaynak paketleri, vb.
└── athena_logo.png   # (opsiyonel) yönetim panelinde görünür
```

Sistem `files/` dizinini özyinelemeli tarar, her dosyanın **SHA-256** özetini
alır ve şunları üretir:

- **`index.json`** — zorunlu (kilitli / anti-cheat) dosyalar. İstemci bunları
  her zaman indirir ve SHA-256 ile doğrular.
- **`mods.json`** — isteğe bağlı (oyuncunun seçebileceği) modlar.

`index`/`mods` üretiminden **hariç tutulanlar:** meta dosyalar
(`index.json`, `server_config.json`, `mods.json`, `mods_classification.json`),
`data/` altındaki her şey ve isteğe bağlı işaretlenmiş mod dosyaları
(bunlar yalnızca `mods.json` içine girer).

---

## Optsiyonel / Zorunlu Mod Modeli (anti-cheat)

- Tüm mod jar dosyaları fiziksel olarak **`files/mods/`** içinde durur.
- `files/mods_classification.json` her mod dosya adını sınıflandırır:

  ```json
  {
    "mods": {
      "journeymap-1.12.2.jar": { "type": "optional", "name": "JourneyMap", "description": "Mini harita", "default": false },
      "somelib.jar": { "type": "required" }
    }
  }
  ```

- Sınıflandırmada **bulunmayan** her mod varsayılan olarak **zorunlu** kabul
  edilir (her şey kilitli, aksi açıkça belirtilmedikçe).
- İstemci `mods/` klasörü beyaz liste ile zorlanır: yalnızca (zorunlu ∪
  seçilen-isteğe-bağlı) dosyalar kalır; her ekstra/hile jar senkronizasyonda
  silinir.

Sınıflandırmayı yönetim panelindeki **Mod Sınıflandırma** bölümünden
düzenleyebilir veya `files/mods_classification.json` dosyasını elle
güncelleyebilirsiniz. Her iki durumda da index'i yeniden oluşturun.

---

## Dinamik Paket + Otomatik Sürüm Notları (changelog)

Paket **dinamik** üretilir: istemci her `/api/index.json` veya `/api/mods.json`
istediğinde sistem `files/` dizinini ucuzca tarar (yalnızca `boyut`+`mtime`
değişen dosyaları yeniden SHA-256'lar — büyük paketleri her istekte baştan
hash'lemez). Bir değişiklik (ekleme / güncelleme / silme / yeniden
sınıflandırma) tespit edildiğinde:

- `index.json` ve `mods.json` yeniden yazılır,
- **paket sürümü otomatik artar** (`/api/version.json`),
- otomatik bir **sürüm notu** changelog'a eklenir (`/api/changelog.json`),
  örn. `�?� Eklendi: JourneyMap | g��� Güncellendi: Forge kütüphanesi`.

Launcher bu notu başlık altında oyunculara gösterir; yönetim paneli tüm
geçmişi listeler. Durum `data/pack_state.json`, changelog `data/changelog.json`,
sürüm `data/version.json` içinde tutulur (hepsi gizli, web'e açılmaz).

> **Not:** Değişiklik tespiti dosya `boyut`+`mtime` değerine bakar. Dosyaları
> `mtime`'ı koruyarak kopyalarsanız değişiklik fark edilmeyebilir; bu durumda
> aşağıdaki tam yenilemeyi (`--force`) kullanın.

### Index'i elle yenileme

1. **Yönetim paneli:** "Index'i Yeniden Oluştur" butonu (tam yeniden hash).
2. **CLI (artımlı, ucuz):** `php /path/to/WebBackend/cli/build_index.php`
3. **CLI (tam yeniden hash):** `php /path/to/WebBackend/cli/build_index.php --force`

### Cron (önerilen)

Hiç istemci gelmese bile `files/` dizinini düzenli tarayıp sürüm/changelog'u
güncel tutmak için (artımlı, ucuz):

```cron
*/5 * * * * php /path/to/WebBackend/cli/build_index.php
```

(Her 5 dakikada bir çalışır.)

---

## Yönetim Paneli

```
https://oyna.athenastudios.com.tr/admin?password=�?İFRE
```

Panelde şunlar bulunur: istatistik kartları (toplam başlatma, benzersiz
oyuncu, bugünkü başlatma, ortalama RAM), aktif yapılandırma düzenleme formu,
mod sınıflandırma, index yeniden oluşturma, son 100 aktivite tablosu ve HWID
ban yönetimi.

> **GÜVENLİK — Yönetici şifresi:**
> Depoda **gömülü/sabit şifre yoktur.** �?ifre şu sırayla belirlenir:
> 1. `ATHENA_ADMIN_PW` ortam değişkeni (üretim için önerilir):
>    ```
>    # nginx/php-fpm pool: env[ATHENA_ADMIN_PW] = "yeni-guclu-sifre"
>    # Apache (SetEnv):   SetEnv ATHENA_ADMIN_PW "yeni-guclu-sifre"
>    ```
> 2. `data/admin_password.txt` dosyası — kendi şifrenizi koymak için bu dosyayı
>    düzenleyin.
> 3. İkisi de yoksa **ilk açılışta rastgele güçlü bir şifre üretilir** ve
>    `data/admin_password.txt` içine yazılır. İlk kurulumdan sonra şifreyi bu
>    dosyadan okuyun (`cat WebBackend/data/admin_password.txt`).
>
> - Paneli ve tüm API'leri **mutlaka HTTPS** üzerinden sunun (şifre URL'de gider).

---

## API Uç Noktaları

Tüm yollar `{BASE}` = `https://oyna.athenastudios.com.tr` köküne görelidir.

| Yöntem | Yol | Açıklama |
|--------|-----|----------|
| GET  | `/api/server_config.json` | Sunucu yapılandırması (PascalCase) |
| GET  | `/api/index.json` | Zorunlu dosya listesi (SHA-256) |
| GET  | `/api/mods.json` | İsteğe bağlı mod kataloğu |
| GET  | `/api/version.json` | Güncel paket sürümü + son değişiklik notu |
| GET  | `/api/changelog.json` | Otomatik sürüm notları (en yeni önce) |
| GET  | `/api/modpack.mrpack` | Telefon için Modrinth modpack (MojoLauncher/Pojav içe aktarır) |
| GET  | `/files/<relpath>` | Ham dosya indirme (traversal + meta korumalı) |
| GET  | `/api/ping?ip=host[:port]` | Minecraft sunucu durumu / gecikme |
| POST | `/api/metric/<action>?...` | Metrik kaydı |
| GET  | `/api/check_ban?hwid=...` | HWID ban kontrolü |
| POST | `/api/ban?password=...` | Ban / ban kaldırma (JSON gövde) |
| GET  | `/admin?password=...` | Yönetim paneli (HTML) |
| POST | `/api/admin/rebuild?password=...` | Index'i yeniden oluştur |
| POST | `/api/admin/classify?password=...` | Mod sınıflandırmasını kaydet + yeniden oluştur |
| POST | `/api/admin/config?password=...` | Sunucu yapılandırmasını kaydet |

---

## Yerel Test

```bash
php -S 127.0.0.1:8099 -t WebBackend/public WebBackend/public/index.php
curl http://127.0.0.1:8099/api/server_config.json
curl http://127.0.0.1:8099/api/index.json
```

(Canlı bir Minecraft sunucusu olmadan `/api/ping` "offline" döner — bu normaldir.)


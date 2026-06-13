# Athena — Kurulum / Devreye Alma Rehberi (HANDOFF)

Bu dosya, **senin** yapman gereken (otomatikleştirilemeyen / kasıtlı olarak ertelenen) adımları
sıralar. Kod tarafı (backend + AthenaCore mod + CI) hazır; aşağıdakiler devreye alma içindir.

> ⚠️ **Önce güvenlik:** Sohbette paylaştığın Discord Client Secret, Bot Token ve root şifresi
> açıkta kaldı. Devreye almadan/aldıktan sonra **Discord secret + bot token'ı yenile (Regenerate)**
> ve **root şifreni değiştir** (`passwd`). Sırlar yalnızca sunucuda `WebBackend/data/.env` içinde
> tutulur — repoya asla girmez (`.gitignore` + nginx `deny /data` ile korunur).

---

## 1) Discord uygulaması (OAuth2)

1. https://discord.com/developers/applications → **New Application** (veya mevcut uygulaman).
2. **OAuth2 → Redirects** kısmına şunu ekle (birebir):
   ```
   https://oyna.athenastudios.com.tr/api/auth/discord/callback
   ```
3. **OAuth2 → Client ID** ve **Client Secret** değerlerini al.
4. Scope olarak yalnızca **`identify`** kullanılıyor (ekstra izin gerekmez).
5. Bu değerleri sunucuda `WebBackend/data/.env` içine yaz (aşağıda).

---

## 2) Backend'i sunucuya kur (oyna.athenastudios.com.tr)

`WebBackend/` klasörünü sunucuya kopyala ve root olarak:

```bash
cd WebBackend
sudo ATHENA_ADMIN_PW='root-sifrenden-FARKLI-guclu-bir-parola' bash deploy.sh
```

`deploy.sh` ne yapar (idempotent — mevcut `data/` ve `files/` ASLA silinmez):
- nginx + PHP 8.4 (fpm, **gd**, sqlite3, curl, mbstring, zip) + certbot kurar,
- kodu `/var/www/html`'e kopyalar (web kökü = `public/`; `src/data/seed/cli` web'e kapalı),
- **`data/.env` yoksa üretir**: `JWT_SECRET` ve `ATHENA_SERVER_KEY` otomatik; Discord alanları boş,
- `files/skins/` oluşturur, izinleri (www-data) ayarlar,
- nginx'te **Authorization header'ı PHP'ye iletir** (Bearer JWT için şart),
- HTTPS (Let's Encrypt) + HSTS + güvenlik başlıkları + ufw (22/80/443/25565),
- SHA-256 index üretir.

Kurulumdan sonra **`data/.env`'i düzenleyip Discord bilgilerini gir**:
```bash
sudo nano /var/www/html/data/.env
# DISCORD_CLIENT_ID=...    DISCORD_CLIENT_SECRET=...   (DISCORD_BOT_TOKEN opsiyonel)
sudo systemctl reload php8.4-fpm nginx
```

`ATHENA_SERVER_KEY` değerini not al — oyun sunucusu modunda **aynısını** kullanacaksın (adım 4).

### Doğrulama
```bash
curl -I  https://oyna.athenastudios.com.tr/api/server_config.json     # 200
curl -sI "https://oyna.athenastudios.com.tr/api/auth/discord/start?platform=desktop&port=1&nonce=x"  # 302 -> discord.com
```
Admin panel: `https://oyna.athenastudios.com.tr/admin?password=ADMIN_PAROLAN`
(yeni kartlar: **Kullanıcılar (Discord)**, **Minecraft Hesapları & Skinler**, ban/skin yönetimi).

---

## 3) AthenaCore modu (jar) — derle ve yerleştir

1. **Derle:** GitHub'da `v1.0.0` gibi bir tag push'la → `AthenaCore Mod` workflow'u jar üretir
   (Release + artifact). Ya da Actions'tan elle `workflow_dispatch`.
   - Çıktı: `athenacore.jar`.
2. **Modpack'e koy (kilitli/anti-cheat):** jar'ı sunucuda `/var/www/html/files/mods/` içine bırak,
   admin panelden **Mod Sınıflandırma → AthenaCore → Zorunlu** işaretle ve **Index'i Yenile**.
   Böylece tüm istemcilere otomatik, kilitli iner.
3. **Oyun sunucusuna koy:** aynı jar'ı Forge 1.12.2 sunucusunun `mods/` klasörüne kopyala.

---

## 4) Forge 1.12.2 oyun sunucusu ayarı

> Bu adımı **sen** uygula (canlı sunucuya ben dokunmadım). Önce dünya + eklenti/yetki verisini **yedekle**.

1. `server.properties`: `online-mode=false` (offline kalır; güvenlik AthenaCore + join_token ile).
   - UUID'ler değişmez (deterministik offline UUID kullanılıyor) → dünya/envanter/yetki **bozulmaz**.
2. AthenaCore'a backend anahtarını ver. **En temizi ortam değişkeni** (start script'te):
   ```bash
   export ATHENA_SERVER_KEY="<data/.env içindeki ATHENA_SERVER_KEY ile AYNI>"
   java -Xmx... -jar forge-1.12.2-14.23.5.2860-universal.jar nogui
   ```
   (Alternatif: ilk açılışta oluşan `config/athenacore.cfg` içinde `serverKey=` ve `backendUrl=`.)
3. Başlat. Davranış:
   - Athena Launcher'dan **doğrulanmış, banlı olmayan** hesaplar girebilir.
   - Token sunamayan (vanilla / launcher dışı) istemciler ~12 sn içinde **atılır**.
   - Banlama: admin panelden discord/username/uuid ile → o hesap/kullanıcı giremez.
   - Skinler launcher'dan yüklenince oyunda otomatik görünür.

> İlk kurulumda bir **test dünyasında** doğrula: bir hesapla gir (UUID `usercache.json`'da
> değişmemeli), skin render olmalı, launcher dışı bir client atılmalı.

---

## 5) Masaüstü launcher (Avalonia, Win/Linux) & Android

- Masaüstü ve Android launcher'lar bu backend'e (`https://oyna.athenastudios.com.tr`) bağlanır.
- CI ile derlenirler (bkz. `.github/workflows/`). Tag (`v*`) push'u → otomatik Release.
- Discord giriş, çoklu hesap (1 Discord = 3-5), skin yükleme ve oyuna otomatik bağlanma launcher
  tarafında hazırdır; backend uçları yukarıda kuruldu.

---

## 6) Bakım / öneriler
- Cron (paket tazeleme): `*/5 * * * * php /var/www/html/cli/build_index.php`
- `data/.env` ve `data/*.db` yedekle. Sırları periyodik döndür.
- Admin paneli **yalnız HTTPS** üzerinden kullan (parola URL'de gider).

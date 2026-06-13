#!/usr/bin/env bash
# =============================================================================
# Athena Studios Launcher - Apache + PHP-FPM kurulum & sıkılaştırma (Ubuntu/Debian)
# -----------------------------------------------------------------------------
# deploy.sh'in (nginx) Apache karşılığı. Sunucuda root olarak çalıştırın. Yapar:
#   * apache2 + PHP-FPM + SQLite + certbot (python3-certbot-apache) kurar
#   * Gerekli modülleri açar: proxy proxy_fcgi rewrite headers setenvif ssl + php-fpm
#   * WebBackend'i /var/www/html'e kopyalar (web kökü = .../public; src/data/seed/cli AÇIK DEĞİL)
#   * Let's Encrypt (certbot --apache) ile HTTPS + 80->443 yönlendirme
#   * Güvenlik: güvenlik başlıkları, dotfile reddi, dizin listeleme kapalı, ufw
#   * Authorization (Bearer JWT) başlığını PHP'ye geçirir (CGIPassAuth)
#   * /home/athena/Desktop/RP/mods içindeki modları files/mods'a alıp index üretir
#   * Admin parolasını GÜVENLİ (root'tan FARKLI) şekilde ayarlar
#
# Kullanım:
#   sudo ATHENA_ADMIN_PW='guclu-benzersiz-parola' bash deploy-apache.sh
#   (ATHENA_ADMIN_PW verilmezse güçlü rastgele bir parola üretilir ve gösterilir.)
# =============================================================================
set -euo pipefail

DOMAIN="oyna.athenastudios.com.tr"
WEBROOT="${WEBROOT:-/var/www/html}"   # WEBROOT=/var/www/athena bash deploy-apache.sh ile değiştirilebilir
PUBLIC="$WEBROOT/public"
MODS_SRC="/home/athena/Desktop/RP/mods"
CERT_EMAIL="${CERT_EMAIL:-admin@athenastudios.com.tr}"   # certbot bildirimleri için
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"  # bu script WebBackend/ içinde

if [ "$(id -u)" -ne 0 ]; then echo "Bu script root ile çalıştırılmalı (sudo)."; exit 1; fi

echo ">> [1/9] Paketler kuruluyor..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y apache2 php-fpm php-sqlite3 php-curl php-mbstring php-zip php-gd certbot python3-certbot-apache ufw rsync openssl

# PHP-FPM sürüm/sock tespiti — SÜRÜMLÜ sokete öncelik ver (php8.4-fpm.sock),
# çünkü /run/php/php-fpm.sock genel symlink'i alfabetik olarak önce gelir ve
# basename'i "php-fpm" olur (a2enconf php-fpm DİYE bir conf yoktur, eşleşmez).
PHP_FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | grep -E 'php[0-9]' | sort -V | tail -n1 || true)"
[ -z "$PHP_FPM_SOCK" ] && PHP_FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"
if [ -z "$PHP_FPM_SOCK" ]; then echo "PHP-FPM soketi bulunamadı!"; exit 1; fi
PHP_FPM_CONF="$(basename "$PHP_FPM_SOCK" .sock)"   # ör: php8.4-fpm
echo "   PHP-FPM soketi: $PHP_FPM_SOCK  (conf: $PHP_FPM_CONF)"

echo ">> [2/9] Apache modülleri etkinleştiriliyor..."
a2enmod proxy proxy_fcgi rewrite headers setenvif ssl >/dev/null
# php-fpm yapılandırmasını (varsa) etkinleştir; mod_php KULLANMIYORUZ.
a2enconf "$PHP_FPM_CONF" >/dev/null 2>&1 || true
a2dismod php* >/dev/null 2>&1 || true   # mod_php yüklüyse devre dışı bırak (FPM kullanılacak)

echo ">> [3/9] Dosyalar $WEBROOT konumuna kopyalanıyor..."
mkdir -p "$WEBROOT"
# data/ runtime + files/ admin içeriği korunur; kod güncellenir
rsync -a --delete \
  --exclude 'data/' --exclude 'files/' --exclude 'deploy.sh' --exclude 'deploy-apache.sh' \
  "$SRC_DIR/" "$WEBROOT/"
mkdir -p "$WEBROOT/data" "$WEBROOT/files/mods" "$WEBROOT/files/skins"

echo ">> [4/9] Admin parolası + .env (sırlar) ayarlanıyor..."
ADMIN_PW="${ATHENA_ADMIN_PW:-}"
if [ -z "$ADMIN_PW" ]; then
  ADMIN_PW="$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"
  echo "   ATHENA_ADMIN_PW verilmedi -> üretilen güçlü parola: $ADMIN_PW"
fi
# config.php sırası: ENV > data/admin_password.txt > rastgele. Dosyaya yazıyoruz.
printf '%s\n' "$ADMIN_PW" > "$WEBROOT/data/admin_password.txt"

# data/.env: Discord/JWT/sunucu-anahtarı sırları. Yoksa üret (JWT + server key otomatik).
ENV_FILE="$WEBROOT/data/.env"
if [ ! -f "$ENV_FILE" ]; then
  GEN_JWT="$(openssl rand -hex 32)"
  GEN_SRVKEY="$(openssl rand -hex 32)"
  cat > "$ENV_FILE" <<ENV
# Athena backend sırları - Discord bilgilerini doldurun (DISCORD_*). JWT ve sunucu
# anahtarı otomatik üretildi. Bu dosyayı GİZLİ tutun; git'e girmez, Apache engeller.
DISCORD_CLIENT_ID=
DISCORD_CLIENT_SECRET=
DISCORD_BOT_TOKEN=
DISCORD_REDIRECT_URI=https://$DOMAIN/api/auth/discord/callback
JWT_SECRET=$GEN_JWT
ATHENA_SERVER_KEY=$GEN_SRVKEY
ACCOUNT_CAP=5
ENV
  echo "   data/.env üretildi. Discord Client ID/Secret'i doldurmayı unutmayın."
  echo "   ATHENA_SERVER_KEY (oyun sunucusunda da AYNI değeri kullanın): $GEN_SRVKEY"
else
  echo "   data/.env zaten var, korunuyor."
fi

echo ">> [5/9] Modlar alınıyor: $MODS_SRC -> files/mods ..."
if [ -d "$MODS_SRC" ]; then
  shopt -s nullglob
  cp -f "$MODS_SRC"/*.jar "$WEBROOT/files/mods/" 2>/dev/null || true
  COUNT="$(ls -1 "$WEBROOT/files/mods/"*.jar 2>/dev/null | wc -l || echo 0)"
  echo "   $COUNT mod kopyalandı."
else
  echo "   UYARI: $MODS_SRC bulunamadı, mod kopyalanmadı."
fi

echo ">> [6/9] İzinler & sahiplik (en az ayrıcalık)..."
# Kod root'a ait + herkes okur; PHP yalnızca data/ ve files/ içine YAZAR.
chown -R root:www-data "$WEBROOT"
find "$WEBROOT" -type d -exec chmod 755 {} \;
find "$WEBROOT" -type f -exec chmod 644 {} \;
chown -R www-data:www-data "$WEBROOT/data" "$WEBROOT/files"
chmod 750 "$WEBROOT/data"
chmod 640 "$WEBROOT/data/admin_password.txt"
[ -f "$WEBROOT/data/.env" ] && chmod 600 "$WEBROOT/data/.env" || true

echo ">> [7/9] Apache vhost yazılıyor (yalnız public/ açık)..."
cat > /etc/apache2/sites-available/athena.conf <<APACHE
<VirtualHost *:80>
    ServerName $DOMAIN

    DocumentRoot $PUBLIC
    LimitRequestBody 52428800

    # vhost seviyesinde rewrite: certbot --redirect'in eklediği HTTP->HTTPS
    # kuralı bu olmadan çalışmaz (mod_rewrite her bağlamda ayrı açılır).
    RewriteEngine On

    <Directory $PUBLIC>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted

        # KRİTİK: Bearer JWT'yi PHP'ye geçir (yoksa korumalı uçlar 401 döner).
        CGIPassAuth On

        # Front controller: gerçek dosya/dizin -> doğrudan; gerisi -> index.php
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} -f [OR]
        RewriteCond %{REQUEST_FILENAME} -d
        RewriteRule ^ - [L]
        RewriteRule ^ index.php [L]
    </Directory>

    # CGIPassAuth desteklemeyen eski Apache için yedek.
    SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=\$1

    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:$PHP_FPM_SOCK|fcgi://localhost"
    </FilesMatch>

    # Defans-derinliği: backend iç dizinleri asla servis edilmesin.
    RedirectMatch 404 "(?i)^/(data|seed|src|cli)(/|\$)"

    <FilesMatch "(^\.ht|~\$)">
        Require all denied
    </FilesMatch>

    ServerSignature Off
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "no-referrer"
    Header always set X-XSS-Protection "1; mode=block"

    ErrorLog  \${APACHE_LOG_DIR}/athena_error.log
    CustomLog \${APACHE_LOG_DIR}/athena_access.log combined
</VirtualHost>
APACHE
a2dissite 000-default >/dev/null 2>&1 || true
a2ensite athena >/dev/null
apache2ctl configtest
systemctl reload apache2

echo ">> [8/9] Index üretiliyor (SHA-256)..."
sudo -u www-data php "$WEBROOT/cli/build_index.php" --force || true

echo ">> [9/9] HTTPS (Let's Encrypt / certbot --apache)..."
certbot --apache -d "$DOMAIN" --non-interactive --agree-tos -m "$CERT_EMAIL" --redirect || \
  echo "   UYARI: certbot başarısız (DNS/port 80 erişimi?). Sonra elle: certbot --apache -d $DOMAIN"
# HSTS ekle (yalnızca https vhost üretildiyse anlamlı)
SSL_CONF="/etc/apache2/sites-available/athena-le-ssl.conf"
if [ -f "$SSL_CONF" ] && ! grep -q "Strict-Transport-Security" "$SSL_CONF"; then
  sed -i '/<\/VirtualHost>/i \    Header always set Strict-Transport-Security "max-age=31536000"' "$SSL_CONF" || true
  apache2ctl configtest && systemctl reload apache2 || true
fi

echo ">> Güvenlik duvarı (ufw)..."
ufw allow 22/tcp    >/dev/null 2>&1 || true   # SSH
ufw allow 80/tcp    >/dev/null 2>&1 || true   # HTTP (certbot + yönlendirme)
ufw allow 443/tcp   >/dev/null 2>&1 || true   # HTTPS
ufw allow 25565/tcp >/dev/null 2>&1 || true   # Minecraft
yes | ufw enable     >/dev/null 2>&1 || true

echo ""
echo "============================================================"
echo " KURULUM TAMAM (Apache)"
echo "  Site      : https://$DOMAIN/"
echo "  Admin     : https://$DOMAIN/admin?password=ADMIN_PAROLASI"
echo "  Admin pw  : (yukarıda gösterildi / verdiğiniz ATHENA_ADMIN_PW)"
echo "  files/    : $WEBROOT/files  (mod/dosya buraya bırakılır)"
echo "  Discord   : Redirect URI -> https://$DOMAIN/api/auth/discord/callback"
echo "  cron öneri: */5 * * * * php $WEBROOT/cli/build_index.php"
echo ""
echo "  GÜVENLİK HATIRLATMASI:"
echo "   - root parolanızı DEĞİŞTİRİN (passwd) ve mümkünse SSH anahtarına geçin."
echo "   - Admin parolası root'tan farklı tutuldu (doğru olan budur)."
echo "============================================================"

# Doğrulama
echo ">> Doğrulama (yerel):"
curl -s -o /dev/null -H "Host: $DOMAIN" -w "  /api/server_config.json -> %{http_code}\n" "http://127.0.0.1/api/server_config.json" || true
curl -s -o /dev/null -H "Host: $DOMAIN" -w "  /api/index.json         -> %{http_code}\n" "http://127.0.0.1/api/index.json" || true
curl -s -o /dev/null -H "Host: $DOMAIN" -w "  /api/version.json       -> %{http_code}\n" "http://127.0.0.1/api/version.json" || true
curl -s -o /dev/null -H "Host: $DOMAIN" -w "  /api/modpack.mrpack     -> %{http_code}\n" "http://127.0.0.1/api/modpack.mrpack" || true

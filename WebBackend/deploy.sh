#!/usr/bin/env bash
# =============================================================================
# Athena Studios Launcher - Backend kurulum & sıkılaştırma (Ubuntu/Debian)
# -----------------------------------------------------------------------------
# Sunucuda root olarak çalıştırın. Yapar:
#   * nginx + PHP-FPM + SQLite + certbot kurar
#   * WebBackend'i /var/www/html'e kopyalar (web kökü = .../public; src/data/seed/cli AÇIK DEĞİL)
#   * Let's Encrypt (certbot) ile HTTPS + 80->443 yönlendirme
#   * Güvenlik: güvenlik başlıkları, dotfile reddi, dizin listeleme kapalı, ufw
#   * /home/athena/Desktop/RP/mods içindeki modları files/mods'a alıp index üretir
#   * Admin parolasını GÜVENLİ (root'tan FARKLI) şekilde ayarlar
#
# Kullanım:
#   sudo ATHENA_ADMIN_PW='guclu-benzersiz-parola' bash deploy.sh
#   (ATHENA_ADMIN_PW verilmezse güçlü rastgele bir parola üretilir ve gösterilir.)
# =============================================================================
set -euo pipefail

DOMAIN="oyna.athenastudios.com.tr"
WEBROOT="/var/www/html"
PUBLIC="$WEBROOT/public"
MODS_SRC="/home/athena/Desktop/RP/mods"
CERT_EMAIL="${CERT_EMAIL:-admin@athenastudios.com.tr}"   # certbot bildirimleri için
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"  # bu script WebBackend/ içinde

if [ "$(id -u)" -ne 0 ]; then echo "Bu script root ile çalıştırılmalı (sudo)."; exit 1; fi

echo ">> [1/9] Paketler kuruluyor..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nginx php-fpm php-sqlite3 php-curl php-mbstring php-zip php-gd certbot python3-certbot-nginx ufw rsync openssl

# PHP-FPM sürüm/sock tespiti
PHP_FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1 || true)"
if [ -z "$PHP_FPM_SOCK" ]; then echo "PHP-FPM soketi bulunamadı!"; exit 1; fi
echo "   PHP-FPM soketi: $PHP_FPM_SOCK"

echo ">> [2/9] Dosyalar $WEBROOT konumuna kopyalanıyor..."
mkdir -p "$WEBROOT"
# data/ runtime + files/ admin içeriği korunur; kod güncellenir
rsync -a --delete \
  --exclude 'data/' --exclude 'files/' --exclude 'deploy.sh' \
  "$SRC_DIR/" "$WEBROOT/"
mkdir -p "$WEBROOT/data" "$WEBROOT/files/mods" "$WEBROOT/files/skins"

echo ">> [3/9] Admin parolası ayarlanıyor (root parolasından FARKLI, güvenli)..."
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
# anahtarı otomatik üretildi. Bu dosyayı GİZLİ tutun; git'e girmez, nginx engeller.
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

echo ">> [4/9] Modlar alınıyor: $MODS_SRC -> files/mods ..."
if [ -d "$MODS_SRC" ]; then
  shopt -s nullglob
  cp -f "$MODS_SRC"/*.jar "$WEBROOT/files/mods/" 2>/dev/null || true
  COUNT="$(ls -1 "$WEBROOT/files/mods/"*.jar 2>/dev/null | wc -l || echo 0)"
  echo "   $COUNT mod kopyalandı."
else
  echo "   UYARI: $MODS_SRC bulunamadı, mod kopyalanmadı."
fi

echo ">> [5/9] İzinler & sahiplik (en az ayrıcalık)..."
# Kod root'a ait + herkes okur; PHP yalnızca data/ ve files/ içine YAZAR.
chown -R root:www-data "$WEBROOT"
find "$WEBROOT" -type d -exec chmod 755 {} \;
find "$WEBROOT" -type f -exec chmod 644 {} \;
chown -R www-data:www-data "$WEBROOT/data" "$WEBROOT/files"
chmod 750 "$WEBROOT/data"
chmod 640 "$WEBROOT/data/admin_password.txt"
[ -f "$WEBROOT/data/.env" ] && chmod 600 "$WEBROOT/data/.env" || true

echo ">> [6/9] nginx yapılandırılıyor (yalnız public/ açık)..."
cat > /etc/nginx/sites-available/athena <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    root $PUBLIC;
    index index.php;

    server_tokens off;
    autoindex off;
    client_max_body_size 50m;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header X-XSS-Protection "1; mode=block" always;

    # Tüm istekler ön denetleyiciye
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        # Bearer JWT'nin PHP'ye ulaşması için (Authorization header'ı ilet)
        fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
    }

    # Gizli dosyalar ve yedekler reddedilir (veri sızıntısı önleme)
    location ~ /\. { deny all; }
    location ~ ~\$  { deny all; }

    # Defans-derinliği: backend iç dizinleri asla servis edilmesin (public dışında olsalar bile)
    location ~ ^/(data|seed|src|cli)/ { deny all; return 404; }
}
NGINX
ln -sf /etc/nginx/sites-available/athena /etc/nginx/sites-enabled/athena
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

echo ">> [7/9] Index üretiliyor (SHA-256)..."
sudo -u www-data php "$WEBROOT/cli/build_index.php" --force || true

echo ">> [8/9] HTTPS (Let's Encrypt / certbot)..."
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$CERT_EMAIL" --redirect || \
  echo "   UYARI: certbot başarısız (DNS/port 80 erişimi?). Sonra elle: certbot --nginx -d $DOMAIN"
# HSTS ekle (yalnızca https aktifse anlamlı)
if grep -q "listen 443" /etc/nginx/sites-available/athena; then
  sed -i '/listen 443/a \    add_header Strict-Transport-Security "max-age=31536000" always;' /etc/nginx/sites-available/athena || true
  nginx -t && systemctl reload nginx || true
fi

echo ">> [9/9] Güvenlik duvarı (ufw)..."
ufw allow 22/tcp   >/dev/null 2>&1 || true   # SSH
ufw allow 80/tcp   >/dev/null 2>&1 || true   # HTTP (certbot + yönlendirme)
ufw allow 443/tcp  >/dev/null 2>&1 || true   # HTTPS
ufw allow 25565/tcp >/dev/null 2>&1 || true  # Minecraft
yes | ufw enable    >/dev/null 2>&1 || true

echo ""
echo "============================================================"
echo " KURULUM TAMAM"
echo "  Site      : https://$DOMAIN/"
echo "  Admin     : https://$DOMAIN/admin?password=ADMIN_PAROLASI"
echo "  Admin pw  : (yukarıda gösterildi / verdiğiniz ATHENA_ADMIN_PW)"
echo "  files/    : $WEBROOT/files  (mod/dosya buraya bırakılır)"
echo "  cron öneri: */5 * * * * php $WEBROOT/cli/build_index.php"
echo ""
echo "  GÜVENLİK HATIRLATMASI:"
echo "   - root parolanızı DEĞİŞTİRİN (passwd) ve mümkünse SSH anahtarına geçin."
echo "   - Admin parolası root'tan farklı tutuldu (doğru olan budur)."
echo "============================================================"

# Doğrulama
echo ">> Doğrulama (yereI):"
curl -s -o /dev/null -H "Host: $DOMAIN" -w "  /api/server_config.json -> %{http_code}\n" "http://127.0.0.1/api/server_config.json" || true
curl -s -o /dev/null -H "Host: $DOMAIN" -w "  /api/index.json         -> %{http_code}\n" "http://127.0.0.1/api/index.json" || true
curl -s -o /dev/null -H "Host: $DOMAIN" -w "  /api/version.json        -> %{http_code}\n" "http://127.0.0.1/api/version.json" || true
curl -s -o /dev/null -H "Host: $DOMAIN" -w "  /api/modpack.mrpack      -> %{http_code}\n" "http://127.0.0.1/api/modpack.mrpack" || true

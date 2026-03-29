# Royalnetwork WebBackend

Bu proje, Royalnetwork Minecraft Launcher için arka uç servisini temsil eder.

## Özellikler
1. **Dosya Sunucusu**: `/files/` klasöründeki dosyaları (örn: jre, index.json, server_config.json) C# istemciye sunar.
2. **Ping API**: Minecraft sunucusunun anlık gecikme, oyuncu kapasitesi ve durumunu `/api/ping` endpoint'inde JSON formatında döner.
3. **Metrik Toplama**: Kullanıcı başlatmalarını `metrics.db` adlı yerel SQLite veri tabanında tutar (`/api/metric/play`).
4. **Admin Paneli**: `/admin?password=admin123` bağlantısıyla metrik kartları, aktif yapılandırma bilgisi ve son kullanıcı eylemlerini listeler.

## Kurulum ve Başlatma
1. Python bağımlılıklarını yükleyin:
   ```bash
   pip install -r requirements.txt
   ```
2. Servisi başlatın:
   ```bash
   python app.py
   ```
3. Backend varsayılan olarak `http://0.0.0.0:10230` üzerinde çalışacaktır.

## Yönetim
- Aktif yapılandırmayı (server_config.json vs.) değiştirmek için veya C# başlatıcıya dosyaları sunmak için `files` klasörünüze yerleştirin. `files/index.json` dosyası, istemcinin dosyaları ne zaman senkronize etmesi gerektiğini kontrol eder. (Bkz: PackageBuilder)

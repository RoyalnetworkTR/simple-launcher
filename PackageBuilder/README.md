# Royalnetwork PackageBuilder

Bu araç, Royalnetwork Launcher'ın istemciye indirebilmesi için gerekli olan bağımlılıkları barındıran paket klasörünün `index.json` ve `server_config.json` meta verilerini oluşturur. Aynı zamanda eğer gerekirse Adoptium API üzerinden JRE indirebilir.

## Özellikler
1. **Görsel Arayüz**: PyQt6 ile oluşturulmuş kullanımı kolay Dark-Theme arayüz.
2. **Java Kurulumu**: Kullanıcıların sisteminden bağımsız çalışabilmek için JRE 8-25 sürümlerini kendi klasörüne indirebilir.
3. **Mod Loader Ayarları**: Vanilla, Forge, Fabric, NeoForge loader türlerini belirtebilir.
4. **Hızlı Hashing**: Çıkış dizinindeki dosyaları tarar ve MD5-hash listesi oluşturur. İstemci yalnızca değişen veya eksik dosyaları indirir.
5. **Konfigürasyon (server_config.json)**: Minecraft sürümü, ayrılacak donanım RAM'i, bağlanılacak otomatik sunucu IP'leri gibi yapılandırmaları dışa aktarır.

## Sistem Gereksinimleri
- Python 3.9 veya üstü
- PyQt6
- Requests kütüphanesi

## Kullanım Kılavuzu
1. Bağlam bağımlılıklarını kurun:
   ```bash
   pip install PyQt6 requests
   ```
2. Oluşturucuyu çalıştırın:
   ```bash
   python main.py
   ```
3. Araç açıldığında:
   - Sağlayacağınız mod dosyalarının ve klasörlerin (örn. `mods\`, `config\`) bulunduğu *Paket Klasörü*'nü seçin.
   - Oynanacak Minecraft sürümünü ve Mod tipini seçin.
   - İstiyorsanız JRE versiyonu belirleyip indirme kutucuğunu aktif bırakın.
   - **"Index Oluştur / Paketi Hazırla"** butonuna basarak işlemleri tamamlayın.
   - İşlemler sonucunda hedef klasörde üretilen `server_config.json`, `index.json` ve indirdiğiniz dosyaları **WebBackend/files** klasörüne taşıyın.

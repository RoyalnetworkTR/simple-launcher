# AthenaCore (Forge 1.12.2)

Tek mod ile Athena sunucusunun istemci+sunucu tarafını backend'e bağlar:

- **Giriş doğrulama:** İstemci, launcher'ın yazdığı `config/athena_session.json` içindeki tek-kullanımlık `join_token`'ı login sırasında sunucuya gönderir; sunucu bunu `POST /api/join/verify` ile backend'e doğrular. Geçerli değilse (veya banlıysa) oyuncu **atılır**. Token sunmayan (vanilla / launcher dışı) istemciler `graceSeconds` içinde atılır. Sunucu **offline-mode** kalır → UUID/dünya verisi bozulmaz.
- **Custom skin:** İstemci her oyuncu için `GET /api/skin/{uuid}.png|.json` çekip skini uygular (vanilla doku boru hattına enjekte). Backend skini yoksa vanilla varsayılanı kullanılır.
- **Ban:** `/api/join/verify` banlı hesap/discord için `ok:false` döner → giriş engellenir.

## Yapı / Toolchain
- JDK **8**, ForgeGradle **2.3**, Gradle **4.10.3**, Forge **14.23.5.2860**, mappings `stable_39`.
- CI: `.github/workflows/athenacore-mod.yml` jar'ı derler. Çıktı: `build/libs/athenacore-1.0.0.jar`.

## Kurulum (sunucu + modpack)
1. Derlenen `athenacore-1.0.0.jar`'ı **hem** backend `WebBackend/files/mods/` (required olarak sınıflandır → tüm istemcilere kilitli iner) **hem de** oyun sunucusunun `mods/` klasörüne koy.
2. Oyun sunucusunda `ATHENA_SERVER_KEY` ortam değişkenini backend `.env` ile **aynı** değere ayarla (veya `config/athenacore.cfg` içinde `serverKey`). Bu anahtar yoksa sunucu giriş doğrulamasını uygulamaz.
3. Sunucu `server.properties`: `online-mode=false` (offline kalır; güvenlik AthenaCore + join_token ile sağlanır).

## Config (`config/athenacore.cfg`)
- `backendUrl` (vars: `https://oyna.athenastudios.com.tr`)
- `serverKey` — yalnız dedicated sunucuda; gizli tut (env `ATHENA_SERVER_KEY` önceliklidir)
- `graceSeconds` — token sunulması için tanınan süre (vars: 12)

## ⚠️ Doğrulama notu
Skin enjeksiyonu `NetworkPlayerInfo` alanlarını tip bazlı reflection ile bulur (obfuscation'a dayanıklı) ve tüm hatalar yutulur (skin uygulanmazsa vanilla varsayılan, çökme yok). **Auth/ban** kritik yolu kararlı API'lerle yazıldı. İlk kurulumda bir test dünyasında skin render'ı ve clientless-kick davranışı doğrulanmalıdır.

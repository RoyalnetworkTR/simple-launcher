# Android — Athena v2 Entegrasyon Planı (uygulanabilir spec)

Backend ve masaüstü tamamlandı ve doğrulandı. Android tarafı, dış bir projenin
(MojoLauncher) fork'lanmasını gerektirdiği için bu repoda **uygulanabilir bir spec**
olarak bırakıldı. Backend **Android'i tam destekler** — eksik olan yalnızca istemci kodu.

## Backend Android için zaten hazır
- Discord giriş **deep-link** akışı: `GET /api/auth/discord/start?platform=android&nonce=<n>` →
  Discord → `GET /api/auth/discord/callback` → `athena://auth?token=<jwt>&state=<n>` ile geri döner.
- Tüm uçlar JWT ile çalışır: `/api/accounts`, `/api/skin/upload`, `/api/join/prepare`,
  `/api/skin/{uuid}.png|.json`, `/api/auth/refresh`.
- Modpack: `GET /api/modpack.mrpack` (mevcut), AthenaCore modu pakete dahil → skin + auth otomatik.

## İki katmanlı yol (MOBILE.md ile uyumlu)

### Katman A — Mevcut companion'a backend entegrasyonu (orta iş, CI ile derlenir)
`AndroidLauncher/` (Kotlin/Compose) zaten var ve `android-release.yml` ile APK derliyor.
Eklenecekler (masaüstü `AuthService.cs` ile birebir mantık):

1. **`AndroidManifest.xml`** — deep-link Activity:
   ```xml
   <activity android:name=".AuthCallbackActivity" android:exported="true">
     <intent-filter>
       <action android:name="android.intent.action.VIEW"/>
       <category android:name="android.intent.category.DEFAULT"/>
       <category android:name="android.intent.category.BROWSABLE"/>
       <data android:scheme="athena" android:host="auth"/>
     </intent-filter>
   </activity>
   ```
2. **Discord giriş**: `CustomTabsIntent` ile `BASE_URL + "/api/auth/discord/start?platform=android&nonce=<rastgele>"` aç.
   `AuthCallbackActivity` `athena://auth?token=&state=` yakalar → `state==nonce` doğrula →
   JWT'yi `EncryptedSharedPreferences` (androidx.security-crypto) ile sakla.
3. **`data/BackendClient.kt`** — yeni metotlar (OkHttp + `Authorization: Bearer <jwt>`):
   `getAccounts()`, `createAccount(name)` (secret'i EncryptedSharedPreferences'a yaz),
   `deleteAccount(id)`, `uploadSkin(accountId, fileUri, model)` (multipart),
   `prepareJoin(accountId)` → dönen `join_token`'ı oyun dizinine `config/athena_session.json` yaz.
4. **UI** (`ui/AppRoot.kt`, `AppViewModel.kt`): LoginScreen (Discord butonu), AccountsScreen
   (listele/ekle[cap 5]/sil/seç), SkinScreen (foto seç → upload). Masaüstü `Views/`'larının Compose karşılığı.
5. **Otomatik akış** (kullanıcı isteği — manuel adım YOK): ilk açılış → Discord giriş → hesap seç →
   arka planda `modpack.mrpack` import + SHA-256 sync (mevcut `ModpackInstaller.kt`) →
   `prepareJoin` ile `athena_session.json` yaz → MojoLauncher'a **force-connect + otomatik launch**
   (`GameLauncher.kt` handoff Intent'ine `--server oyna.athenastudios.com.tr` + autostart).

> Bu katman tek başına: tam özellikli (Discord+hesap+skin) launcher + MojoLauncher motoru (handoff).
> CI: mevcut `android-release.yml` derler. **Bu, en hızlı tam-parite yoludur.**

### Katman B — Tek kilitli APK (MojoLauncher fork) (büyük iş)
`github.com/MojoLauncher/MojoLauncher` (LGPL) fork'la; Katman A UI'sını fork'un ön Activity'si yap
(handoff yerine gömülü `app_pojavlauncher` motoru). App id `com.athenastudios.mc`, marka/tema Athena,
1.12.2 Forge'a kilitle. CI: `.github/workflows/android-fork-release.yml` (submodules + NDK + JDK17 +
`./gradlew :app_pojavlauncher:assembleDebug`).
- **Riskler**: native (NDK) build, LGPL uyumu (fork kaynağını yayınla + relink izni), MojoLauncher'ın
  Play'den kaldırılmış olması (bakım belirsizliği). Önce CI build'ini prototiple, sonra entegre et.

## Öneri
Önce **Katman A** (mevcut companion + backend entegrasyonu) ile tam-parite + otomatik akışı yayına al;
**Katman B** fork'u ikinci aşama olarak tek-APK için yap. Backend her iki katmanı da bugün destekliyor.

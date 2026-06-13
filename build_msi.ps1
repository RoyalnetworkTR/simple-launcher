Write-Host "1. Proje derleniyor ve tek dosya (Single-File) olarak publish klasörüne cikariliyor..."
dotnet publish OfflineMinecraftLauncher.csproj -c Release -r win-x64 --self-contained false -p:PublishSingleFile=true -o publish

Write-Host "2. WiX Toolset kullanılarak .msi oluşturuluyor..."
wix build installer.wxs -o AthenaStudiosLauncher.msi

Write-Host "Kurulum dosyası hazır: AthenaStudiosLauncher.msi"
Write-Host "Bu dosya, %appdata%\.AthenaStudios\client klasörüne kurulum yapacak."

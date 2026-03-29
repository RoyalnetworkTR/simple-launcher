Write-Host "1. Proje derleniyor ve tek dosya (Single-File) olarak publish klasörüne cikariliyor..."
dotnet publish OfflineMinecraftLauncher.csproj -c Release -r win-x64 --self-contained false -p:PublishSingleFile=true -o publish

Write-Host "2. WiX Toolset kullanılarak .msi oluşturuluyor..."
wix build installer.wxs -o RoyalnetworkLauncher.msi

Write-Host "Kurulum dosyası hazır: RoyalnetworkLauncher.msi"
Write-Host "Bu dosya, %appdata%\.Royalnetwork\client klasörüne kurulum yapacak."

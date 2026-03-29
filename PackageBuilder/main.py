import sys
import os
import hashlib
import json
import requests
import zipfile
import time
import shutil
from PyQt6.QtWidgets import (QApplication, QWidget, QVBoxLayout, QPushButton,
                             QLabel, QFileDialog, QLineEdit, QCheckBox, QMessageBox, QHBoxLayout, QComboBox, QSpinBox, QProgressBar, QFrame, QSplashScreen, QGraphicsOpacityEffect)
from PyQt6.QtGui import QIcon, QFont, QPixmap, QColor, QPalette
from PyQt6.QtCore import Qt, QTimer, QPropertyAnimation, QEasingCurve, QThread, pyqtSignal

def compute_md5(file_path):
    hash_md5 = hashlib.md5()
    with open(file_path, "rb") as f:
        for chunk in iter(lambda: f.read(4096), b""):
            hash_md5.update(chunk)
    return hash_md5.hexdigest()

class DownloadThread(QThread):
    progress = pyqtSignal(int, str)
    finished = pyqtSignal(bool, str)

    def __init__(self, java_version, target_dir):
        super().__init__()
        self.java_version = java_version
        self.target_dir = target_dir

    def run(self):
        runtime_dir = os.path.join(self.target_dir, "runtime")
        jre_dir = os.path.join(runtime_dir, "java-runtime-gamma")
        
        # Olası çakışmayı engellemek için runtime klasörünü temizle
        if os.path.exists(runtime_dir):
            try:
                shutil.rmtree(runtime_dir)
            except Exception:
                pass

        # Adoptium API Link (Windows x64 JRE Hotspot)
        api_url = f"https://api.adoptium.net/v3/binary/latest/{self.java_version}/ga/windows/x64/jre/hotspot/normal/eclipse"
        zip_path = os.path.join(self.target_dir, "jre.zip")
        
        try:
            self.progress.emit(0, f"Java {self.java_version} JRE İndiriliyor...")
            response = requests.get(api_url, stream=True)
            response.raise_for_status()
            total_length = response.headers.get("content-length")
            
            with open(zip_path, "wb") as f:
                if total_length is None:
                    f.write(response.content)
                else:
                    dl = 0
                    total_length = int(total_length)
                    for data in response.iter_content(chunk_size=8192):
                        dl += len(data)
                        f.write(data)
                        done = int(50 * dl / total_length) # 50% for download
                        self.progress.emit(done, f"Java {self.java_version} JRE İndiriliyor... %{int((dl/total_length)*100)}")

            self.progress.emit(50, "Arşivden çıkartılıyor...")
            os.makedirs(jre_dir, exist_ok=True)
            with zipfile.ZipFile(zip_path, "r") as zip_ref:
                total_files = len(zip_ref.namelist())
                for i, file in enumerate(zip_ref.namelist()):
                    zip_ref.extract(file, jre_dir)
                    done = 50 + int(50 * (i + 1) / total_files) # 50% for extraction
                    if i % 10 == 0:
                        self.progress.emit(done, f"Arşivden çıkartılıyor... %{int(((i+1)/total_files)*100)}")
            
            if os.path.exists(zip_path):
                os.remove(zip_path)
            
            # İç içe klasör çıkarsa taşıyıp düzelt
            inner_folders = os.listdir(jre_dir)
            if len(inner_folders) == 1 and os.path.isdir(os.path.join(jre_dir, inner_folders[0])):
                inner_path = os.path.join(jre_dir, inner_folders[0])
                for item in os.listdir(inner_path):
                    os.rename(os.path.join(inner_path, item), os.path.join(jre_dir, item))
                os.rmdir(inner_path)

            self.progress.emit(100, "Java kurulumu tamamlandı!")
            self.finished.emit(True, "Başarılı.")
        except Exception as e:
            if os.path.exists(zip_path):
                os.remove(zip_path)
            self.finished.emit(False, str(e))


class PackageBuilderApp(QWidget):
    def __init__(self):
        super().__init__()
        self.target_dir = ""
        # Try to use minecraft.ico
        self.app_icon = QIcon("minecraft.ico") if os.path.exists("minecraft.ico") else QIcon()
        self.initUI()

    def initUI(self):
        self.setWindowTitle("Royalnetwork Package Builder Pro")
        self.setWindowIcon(self.app_icon)
        self.resize(500, 650)
        
        # Modern Dark Theme
        self.setStyleSheet("""
            QWidget {
                background-color: #1e1e1e;
                color: #d4d4d4;
                font-family: 'Segoe UI', Arial, sans-serif;
                font-size: 13px;
            }
            QLabel {
                font-weight: 500;
                margin-top: 5px;
            }
            QLineEdit, QComboBox, QSpinBox {
                background-color: #2d2d2d;
                border: 1px solid #3f3f46;
                border-radius: 5px;
                padding: 6px;
                color: #ffffff;
            }
            QLineEdit:focus, QComboBox:focus, QSpinBox:focus {
                border: 1px solid #007acc;
            }
            QPushButton {
                background-color: #007acc;
                border: none;
                border-radius: 5px;
                padding: 8px 12px;
                color: white;
                font-weight: bold;
            }
            QPushButton:hover {
                background-color: #0098ff;
            }
            QPushButton:pressed {
                background-color: #005f9e;
            }
            QPushButton:disabled {
                background-color: #3f3f46;
                color: #888888;
            }
            QCheckBox {
                spacing: 8px;
            }
            QCheckBox::indicator {
                width: 18px;
                height: 18px;
                background-color: #2d2d2d;
                border: 1px solid #3f3f46;
                border-radius: 3px;
            }
            QCheckBox::indicator:checked {
                background-color: #007acc;
                image: url(); /* no built-in check image, relies on bg color */
            }
            QProgressBar {
                border: 1px solid #3f3f46;
                border-radius: 4px;
                text-align: center;
                color: transparent;
                background-color: #2d2d2d;
                height: 10px;
            }
            QProgressBar::chunk {
                background-color: #007acc;
                border-radius: 3px;
            }
        """)

        layout = QVBoxLayout()
        layout.setContentsMargins(20, 20, 20, 20)
        layout.setSpacing(10)

        # Title / Header
        header = QLabel("🚀 Royalnetwork Paket Oluşturucu")
        header.setStyleSheet("font-size: 20px; font-weight: bold; color: #ffffff; margin-bottom: 10px;")
        header.setAlignment(Qt.AlignmentFlag.AlignCenter)
        layout.addWidget(header)

        # Directory Selection
        dir_layout = QHBoxLayout()
        self.btn_select_dir = QPushButton("📁 Paket Klasörünü Seç")
        self.btn_select_dir.setStyleSheet("background-color: #d97706;")
        self.btn_select_dir.clicked.connect(self.select_directory)
        dir_layout.addWidget(self.btn_select_dir)
        
        self.lbl_info = QLabel("(Klasör Seçilmedi)")
        self.lbl_info.setStyleSheet("color: #888888;")
        dir_layout.addWidget(self.lbl_info)
        layout.addLayout(dir_layout)

        # Minecraft Version
        self.lbl_mc_ver = QLabel("Minecraft Sürümü:")
        layout.addWidget(self.lbl_mc_ver)
        self.txt_mc_ver = QLineEdit("1.20.1")
        layout.addWidget(self.txt_mc_ver)

        # Loader
        loader_layout = QHBoxLayout()
        
        loader_type_vbox = QVBoxLayout()
        loader_type_vbox.addWidget(QLabel("Mod Yükleyici Türü:"))
        self.cmb_loader_type = QComboBox()
        self.cmb_loader_type.addItems(["Vanilla", "Forge", "Fabric", "NeoForge"])
        loader_type_vbox.addWidget(self.cmb_loader_type)
        loader_layout.addLayout(loader_type_vbox)

        loader_ver_vbox = QVBoxLayout()
        loader_ver_vbox.addWidget(QLabel("Yükleyici Sürümü:"))
        self.txt_loader_ver = QLineEdit("")
        self.txt_loader_ver.setPlaceholderText("Örn: 0.15.7 (Vanilla ise boş)")
        loader_ver_vbox.addWidget(self.txt_loader_ver)
        loader_layout.addLayout(loader_ver_vbox)

        layout.addLayout(loader_layout)

        # RAM Config
        ram_layout = QHBoxLayout()
        
        max_ram_vbox = QVBoxLayout()
        max_ram_vbox.addWidget(QLabel("Varsayılan Max RAM (MB):"))
        self.spn_max_ram = QSpinBox()
        self.spn_max_ram.setRange(1024, 32768)
        self.spn_max_ram.setValue(4096)
        max_ram_vbox.addWidget(self.spn_max_ram)
        ram_layout.addLayout(max_ram_vbox)

        min_ram_vbox = QVBoxLayout()
        min_ram_vbox.addWidget(QLabel("Varsayılan Min RAM (MB):"))
        self.spn_min_ram = QSpinBox()
        self.spn_min_ram.setRange(512, 16384)
        self.spn_min_ram.setValue(1024)
        min_ram_vbox.addWidget(self.spn_min_ram)
        ram_layout.addLayout(min_ram_vbox)

        layout.addLayout(ram_layout)

        # Server Setting
        self.lbl_ip = QLabel("Otomatik Bağlanılacak Sunucu IP:")
        layout.addWidget(self.lbl_ip)
        self.txt_ip = QLineEdit("oyna.royalnetwork.xyz")
        layout.addWidget(self.txt_ip)

        self.chk_auto_connect = QCheckBox("Oyuncu Girince Otomatik Bağlan")
        self.chk_auto_connect.setChecked(True)
        layout.addWidget(self.chk_auto_connect)

        # Divider
        line = QFrame()
        line.setFrameShape(QFrame.Shape.HLine)
        line.setFrameShadow(QFrame.Shadow.Sunken)
        line.setStyleSheet("background-color: #3f3f46;")
        layout.addWidget(line)

        # Java Setting
        self.chk_adoptium = QCheckBox("Paket İçine Otomatik Java (JRE) İndir & Kur")
        self.chk_adoptium.setChecked(False)
        self.chk_adoptium.toggled.connect(self.toggle_java_combobox)
        layout.addWidget(self.chk_adoptium)

        java_layout = QHBoxLayout()
        java_layout.addWidget(QLabel("Java Sürümü:"))
        self.cmb_java_version = QComboBox()
        self.cmb_java_version.addItems(["8", "11", "17", "21", "25"])
        self.cmb_java_version.setCurrentText("21") # Default 21
        self.cmb_java_version.setEnabled(False)
        java_layout.addWidget(self.cmb_java_version)
        java_layout.addStretch()
        layout.addLayout(java_layout)

        layout.addStretch()

        # Progress
        self.lbl_status = QLabel("")
        self.lbl_status.setAlignment(Qt.AlignmentFlag.AlignCenter)
        self.lbl_status.setStyleSheet("color: #007acc;")
        layout.addWidget(self.lbl_status)

        self.progress_bar = QProgressBar()
        self.progress_bar.setValue(0)
        self.progress_bar.hide()
        layout.addWidget(self.progress_bar)

        self.btn_build = QPushButton("🔥 Index Oluştur / Paketi Hazırla")
        self.btn_build.setStyleSheet("background-color: #10b981; font-size: 15px; padding: 12px;")
        self.btn_build.clicked.connect(self.start_build)
        layout.addWidget(self.btn_build)

        self.setLayout(layout)

    def toggle_java_combobox(self):
        self.cmb_java_version.setEnabled(self.chk_adoptium.isChecked())

    def select_directory(self):
        directory = QFileDialog.getExistingDirectory(self, "Paket İçeriğinin Bulunduğu Klasörü Seç")   
        if directory:
            self.target_dir = directory
            self.lbl_info.setText(f".../{os.path.basename(self.target_dir)}")
            self.lbl_info.setStyleSheet("color: #10b981;")

    def start_build(self):
        if not self.target_dir:
            QMessageBox.warning(self, "Hata", "Lütfen önce paket klasörünü seçin!")
            return

        self.btn_build.setEnabled(False)
        self.btn_select_dir.setEnabled(False)
        
        if self.chk_adoptium.isChecked():
            java_ver = self.cmb_java_version.currentText()
            self.progress_bar.show()
            self.progress_bar.setValue(0)
            
            self.thread = DownloadThread(java_ver, self.target_dir)
            self.thread.progress.connect(self.update_progress)
            self.thread.finished.connect(self.on_java_finished)
            self.thread.start()
        else:
            self.finish_building_package()

    def update_progress(self, val, msg):
        self.progress_bar.setValue(val)
        self.lbl_status.setText(msg)

    def on_java_finished(self, success, msg):
        if not success:
            QMessageBox.critical(self, "Java İndirme Hatası", f"Java indirilirken hata oluştu:\n{msg}")
            self.lbl_status.setText("İşlem iptal edildi.")
            self.btn_build.setEnabled(True)
            self.btn_select_dir.setEnabled(True)
            self.progress_bar.hide()
            return
        
        self.finish_building_package()

    def finish_building_package(self):
        self.lbl_status.setText("Hash'ler hesaplanıyor, index.json oluşturuluyor...")
        QApplication.processEvents()

        # Create server_config.json
        config = {
            "ServerIp": self.txt_ip.text(),
            "AutoConnect": self.chk_auto_connect.isChecked(),
            "MinecraftVersion": self.txt_mc_ver.text(),
            "LoaderType": self.cmb_loader_type.currentText(),
            "LoaderVersion": self.txt_loader_ver.text(),
            "MaxRamMb": self.spn_max_ram.value(),
            "MinRamMb": self.spn_min_ram.value()
        }

        config_path = os.path.join(self.target_dir, "server_config.json")
        with open(config_path, "w", encoding="utf-8") as f:
            json.dump(config, f, indent=4)

        # Generate index.json
        index_files = []
        for root, dirs, files in os.walk(self.target_dir):
            for file in files:
                if file in ["index.json", "server_config.json"]:
                    continue
                file_path = os.path.join(root, file)
                rel_path = os.path.relpath(file_path, self.target_dir).replace("\\", "/")

                index_files.append({
                    "Path": rel_path,
                    "Hash": compute_md5(file_path),
                    "Size": os.path.getsize(file_path)
                })

        index_path = os.path.join(self.target_dir, "index.json")
        with open(index_path, "w", encoding="utf-8") as f:
            json.dump(index_files, f, indent=4)

        self.lbl_status.setText(f"Hazır! {len(index_files)} dosya paketlendi.")
        self.progress_bar.hide()
        self.btn_build.setEnabled(True)
        self.btn_select_dir.setEnabled(True)
        QMessageBox.information(self, "Başarılı", f"Paket başarıyla oluşturuldu!\n{len(index_files)} dosya işlendi.\nLütfen dosyaları WebBackend/files klasörüne kopyalayın.")

class SplashLoading(QSplashScreen):
    def __init__(self, icon_path):
        pixmap = QPixmap(400, 250)
        pixmap.fill(QColor("#1e1e1e"))
        super().__init__(pixmap, Qt.WindowType.WindowStaysOnTopHint | Qt.WindowType.FramelessWindowHint)
        self.icon_path = icon_path

    def drawContents(self, painter):
        painter.setPen(QColor("#ffffff"))
        painter.setFont(QFont("Segoe UI", 20, QFont.Weight.Bold))
        painter.drawText(self.rect(), Qt.AlignmentFlag.AlignCenter, "Royalnetwork\nPackage Builder")
        
        if os.path.exists(self.icon_path):
            img = QPixmap(self.icon_path).scaled(64, 64, Qt.AspectRatioMode.KeepAspectRatio, Qt.TransformationMode.SmoothTransformation)
            painter.drawPixmap(168, 40, img)

if __name__ == '__main__':
    app = QApplication(sys.argv)
    
    splash = SplashLoading("minecraft.ico")
    splash.show()
    
    for i in range(1, 101):
        QApplication.processEvents()
        time.sleep(0.01)
        splash.showMessage(f"Yükleniyor... %{i}", Qt.AlignmentFlag.AlignBottom | Qt.AlignmentFlag.AlignCenter, QColor("#10b981"))

    ex = PackageBuilderApp()
    ex.show()
    splash.finish(ex)
    sys.exit(app.exec())

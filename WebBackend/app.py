from flask import Flask, request, jsonify, send_from_directory, render_template_string
import sqlite3
import os
import json
from datetime import datetime
from mcstatus import JavaServer

app = Flask(__name__)
DB_NAME = "metrics.db"
FILES_DIR = os.path.join(os.path.dirname(__file__), "files")

# Ensure files directory exists
os.makedirs(FILES_DIR, exist_ok=True)

# Initialize database with all metric columns
def init_db():
    with sqlite3.connect(DB_NAME) as conn:
        cursor = conn.cursor()
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid TEXT,
                action TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                ip TEXT,
                username TEXT,
                os TEXT,
                os_arch TEXT,
                dotnet TEXT,
                ram_total INTEGER,
                ram_max INTEGER,
                ram_min INTEGER,
                resolution TEXT,
                mc_version TEXT,
                loader_type TEXT,
                loader_version TEXT,
                launcher_version TEXT,
                cpu TEXT,
                gpu TEXT,
                java_path TEXT
            )
        ''')
        
        # Migrate old tables: add columns if missing
        existing = set()
        for row in cursor.execute("PRAGMA table_info(metrics)"):
            existing.add(row[1])
        
        new_cols = {
            "ip": "TEXT", "username": "TEXT", "os": "TEXT", "os_arch": "TEXT",
            "dotnet": "TEXT", "ram_total": "INTEGER", "ram_max": "INTEGER",
            "ram_min": "INTEGER", "resolution": "TEXT", "mc_version": "TEXT",
            "loader_type": "TEXT", "loader_version": "TEXT", "launcher_version": "TEXT",
            "cpu": "TEXT", "gpu": "TEXT", "java_path": "TEXT"
        }
        for col, ctype in new_cols.items():
            if col not in existing:
                try:
                    cursor.execute(f"ALTER TABLE metrics ADD COLUMN {col} {ctype}")
                except:
                    pass
        conn.commit()

init_db()

@app.route('/api/index.json', methods=['GET'])
def get_index():
    index_path = os.path.join(FILES_DIR, "index.json")
    if os.path.exists(index_path):
        return send_from_directory(FILES_DIR, "index.json")
    return jsonify([])

@app.route('/api/server_config.json', methods=['GET'])
def get_config():
    config_path = os.path.join(FILES_DIR, "server_config.json")
    if os.path.exists(config_path):
        return send_from_directory(FILES_DIR, "server_config.json")
    return jsonify({"ServerIp": "oyna.royalnetwork.xyz", "AutoConnect": True})

@app.route('/files/<path:filename>', methods=['GET'])
def download_file(filename):
    return send_from_directory(FILES_DIR, filename)

@app.route('/api/ping', methods=['GET'])
def ping_server():
    ip = request.args.get('ip', 'oyna.royalnetwork.xyz')
    try:
        server = JavaServer.lookup(ip)
        status = server.status()
        return jsonify({
            "status": "online",
            "players_online": status.players.online,
            "players_max": status.players.max,
            "latency": round(status.latency)
        })
    except Exception as e:
        return jsonify({"status": "offline", "error": str(e)}), 503

@app.route('/api/metric/<action>', methods=['POST'])
def record_metric(action):
    uuid = request.args.get('uuid', 'unknown')
    username = request.args.get('username', 'Bilinmiyor')
    ip = request.headers.get('X-Forwarded-For', request.remote_addr)
    os_name = request.args.get('os', '')
    os_arch = request.args.get('os_arch', '')
    dotnet = request.args.get('dotnet', '')
    ram_total = request.args.get('ram_total', 0, type=int)
    ram_max = request.args.get('ram_max', 0, type=int)
    ram_min = request.args.get('ram_min', 0, type=int)
    resolution = request.args.get('resolution', '')
    mc_version = request.args.get('mc_version', '')
    loader_type = request.args.get('loader_type', '')
    loader_version = request.args.get('loader_version', '')
    launcher_version = request.args.get('launcher_version', '')
    cpu = request.args.get('cpu', '')
    gpu = request.args.get('gpu', '')
    java_path = request.args.get('java_path', '')
    
    with sqlite3.connect(DB_NAME) as conn:
        cursor = conn.cursor()
        cursor.execute("""
            INSERT INTO metrics (uuid, action, ip, username, os, os_arch, dotnet, ram_total, ram_max, ram_min,
                                 resolution, mc_version, loader_type, loader_version, launcher_version, cpu, gpu, java_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (uuid, action, ip, username, os_name, os_arch, dotnet, ram_total, ram_max, ram_min,
              resolution, mc_version, loader_type, loader_version, launcher_version, cpu, gpu, java_path))
        conn.commit()
    return jsonify({"status": "ok"})

@app.route('/admin')
def admin_panel():
    password = request.args.get('password')
    if password != "4343pla54":
        return "Access Denied.", 403
    
    with sqlite3.connect(DB_NAME) as conn:
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()
        
        # Toplam başlatma sayısı
        cursor.execute("SELECT COUNT(*) as c FROM metrics WHERE action='play'")
        total_plays = cursor.fetchone()["c"]
        
        # Benzersiz oyuncu sayısı
        cursor.execute("SELECT COUNT(DISTINCT username) as c FROM metrics WHERE username != 'Bilinmiyor' AND username != ''")
        unique_players = cursor.fetchone()["c"]
        
        # Bugünkü başlatmalar
        cursor.execute("SELECT COUNT(*) as c FROM metrics WHERE action='play' AND date(timestamp) = date('now')")
        today_plays = cursor.fetchone()["c"]
        
        # İşlem türü dağılımı
        cursor.execute("SELECT action, COUNT(*) as cnt FROM metrics GROUP BY action ORDER BY cnt DESC")
        action_stats = cursor.fetchall()
        
        # İşletim sistemi dağılımı
        cursor.execute("SELECT os, COUNT(*) as cnt FROM metrics WHERE os != '' GROUP BY os ORDER BY cnt DESC LIMIT 10")
        os_stats = cursor.fetchall()
        
        # GPU dağılımı
        cursor.execute("SELECT gpu, COUNT(*) as cnt FROM metrics WHERE gpu != '' AND gpu != 'unknown' GROUP BY gpu ORDER BY cnt DESC LIMIT 10")
        gpu_stats = cursor.fetchall()

        # CPU dağılımı
        cursor.execute("SELECT cpu, COUNT(*) as cnt FROM metrics WHERE cpu != '' AND cpu != 'unknown' GROUP BY cpu ORDER BY cnt DESC LIMIT 10")
        cpu_stats = cursor.fetchall()
        
        # Ekran çözünürlüğü dağılımı
        cursor.execute("SELECT resolution, COUNT(*) as cnt FROM metrics WHERE resolution != '' AND resolution != 'unknown' GROUP BY resolution ORDER BY cnt DESC LIMIT 10")
        res_stats = cursor.fetchall()
        
        # RAM ortalaması
        cursor.execute("SELECT AVG(ram_total) as avg_ram FROM metrics WHERE ram_total > 0")
        avg_ram = cursor.fetchone()["avg_ram"] or 0

        # Launcher versiyon dağılımı
        cursor.execute("SELECT launcher_version, COUNT(*) as cnt FROM metrics WHERE launcher_version != '' GROUP BY launcher_version ORDER BY cnt DESC")
        version_stats = cursor.fetchall()
        
        # Son 100 aktivite
        cursor.execute("""SELECT uuid, action, timestamp, ip, username, os, os_arch, ram_total, ram_max, 
                                 resolution, mc_version, loader_type, launcher_version, cpu, gpu
                          FROM metrics ORDER BY timestamp DESC LIMIT 100""")
        recent = cursor.fetchall()

    config_data = {}
    config_path = os.path.join(FILES_DIR, "server_config.json")
    if os.path.exists(config_path):
        with open(config_path, "r", encoding="utf-8") as f:
            try:
                config_data = json.load(f)
            except:
                pass

    html = """
    <html>
    <head>
        <title>Royalnetwork Admin Paneli</title>
        <style>
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #0d1117; color: #c9d1d9; padding: 20px; }
            h1 { color: #58a6ff; margin-bottom: 20px; font-size: 28px; }
            h2 { color: #79c0ff; margin: 20px 0 10px 0; font-size: 20px; border-bottom: 1px solid #21262d; padding-bottom: 8px; }
            h3 { color: #d2a8ff; margin-bottom: 10px; font-size: 16px; }
            
            .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
            .stat-card { background: linear-gradient(135deg, #161b22, #1c2333); border: 1px solid #30363d; border-radius: 12px; padding: 20px; text-align: center; }
            .stat-card .number { font-size: 36px; font-weight: bold; color: #58a6ff; }
            .stat-card .label { font-size: 13px; color: #8b949e; margin-top: 4px; }
            
            .card-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 16px; margin-bottom: 24px; }
            .card { background-color: #161b22; padding: 20px; border-radius: 12px; border: 1px solid #30363d; }
            
            .dist-item { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; border-bottom: 1px solid #21262d; }
            .dist-item:last-child { border-bottom: none; }
            .dist-name { color: #c9d1d9; font-size: 13px; max-width: 70%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .dist-count { background: #1f6feb33; color: #58a6ff; padding: 2px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; }
            
            table { width: 100%; border-collapse: collapse; margin-top: 10px; background-color: #161b22; border-radius: 12px; overflow: hidden; font-size: 12px; }
            th, td { padding: 10px 12px; text-align: left; }
            th { background-color: #21262d; color: #d2a8ff; font-weight: 600; position: sticky; top: 0; }
            tr:nth-child(even) { background-color: #1c2128; }
            tr:hover { background-color: #263040; }
            
            .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }
            .badge-green { background: #23863633; color: #3fb950; }
            .badge-blue { background: #1f6feb33; color: #58a6ff; }
            .badge-purple { background: #8957e533; color: #d2a8ff; }
            
            .table-scroll { overflow-x: auto; max-height: 600px; overflow-y: auto; border-radius: 12px; border: 1px solid #30363d; }
            
            ul { list-style: none; padding: 0; }
            ul li { padding: 4px 0; font-size: 13px; }
            ul li strong { color: #79c0ff; }
        </style>
    </head>
    <body>
        <h1>🚀 Royalnetwork Yönetim Paneli</h1>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number">{{ total_plays }}</div>
                <div class="label">Toplam Başlatma</div>
            </div>
            <div class="stat-card">
                <div class="number">{{ unique_players }}</div>
                <div class="label">Benzersiz Oyuncu</div>
            </div>
            <div class="stat-card">
                <div class="number">{{ today_plays }}</div>
                <div class="label">Bugünkü Başlatma</div>
            </div>
            <div class="stat-card">
                <div class="number">{{ "%.0f"|format(avg_ram) }} MB</div>
                <div class="label">Ortalama Sistem RAM</div>
            </div>
        </div>
        
        <div class="card-container">
            <div class="card">
                <h3>🛠 Aktif Yapılandırma</h3>
                {% if config %}
                    <ul>
                    {% for key, value in config.items() %}
                        <li><strong>{{ key }}:</strong> {{ value }}</li>
                    {% endfor %}
                    </ul>
                {% else %}
                    <p>Konfigürasyon bulunamadı.</p>
                {% endif %}
            </div>
            
            <div class="card">
                <h3>📊 İşlem Türü Dağılımı</h3>
                {% for stat in action_stats %}
                <div class="dist-item">
                    <span class="dist-name">{{ stat['action'] }}</span>
                    <span class="dist-count">{{ stat['cnt'] }}</span>
                </div>
                {% endfor %}
                {% if not action_stats %}<p style="color:#8b949e">Veri yok.</p>{% endif %}
            </div>

            <div class="card">
                <h3>🖥️ İşletim Sistemi Dağılımı</h3>
                {% for stat in os_stats %}
                <div class="dist-item">
                    <span class="dist-name">{{ stat['os'][:60] }}</span>
                    <span class="dist-count">{{ stat['cnt'] }}</span>
                </div>
                {% endfor %}
                {% if not os_stats %}<p style="color:#8b949e">Veri yok.</p>{% endif %}
            </div>
            
            <div class="card">
                <h3>🎮 GPU Dağılımı</h3>
                {% for stat in gpu_stats %}
                <div class="dist-item">
                    <span class="dist-name">{{ stat['gpu'][:50] }}</span>
                    <span class="dist-count">{{ stat['cnt'] }}</span>
                </div>
                {% endfor %}
                {% if not gpu_stats %}<p style="color:#8b949e">Veri yok.</p>{% endif %}
            </div>

            <div class="card">
                <h3>⚡ CPU Dağılımı</h3>
                {% for stat in cpu_stats %}
                <div class="dist-item">
                    <span class="dist-name">{{ stat['cpu'][:50] }}</span>
                    <span class="dist-count">{{ stat['cnt'] }}</span>
                </div>
                {% endfor %}
                {% if not cpu_stats %}<p style="color:#8b949e">Veri yok.</p>{% endif %}
            </div>
            
            <div class="card">
                <h3>📐 Ekran Çözünürlüğü</h3>
                {% for stat in res_stats %}
                <div class="dist-item">
                    <span class="dist-name">{{ stat['resolution'] }}</span>
                    <span class="dist-count">{{ stat['cnt'] }}</span>
                </div>
                {% endfor %}
                {% if not res_stats %}<p style="color:#8b949e">Veri yok.</p>{% endif %}
            </div>

            <div class="card">
                <h3>📦 Launcher Versiyon Dağılımı</h3>
                {% for stat in version_stats %}
                <div class="dist-item">
                    <span class="dist-name">v{{ stat['launcher_version'] }}</span>
                    <span class="dist-count">{{ stat['cnt'] }}</span>
                </div>
                {% endfor %}
                {% if not version_stats %}<p style="color:#8b949e">Veri yok.</p>{% endif %}
            </div>
        </div>

        <h2>🕒 Son Aktiviteler (Son 100 İşlem)</h2>
        <div class="table-scroll">
            <table>
                <tr>
                    <th>Tarih</th>
                    <th>Kullanıcı</th>
                    <th>İşlem</th>
                    <th>IP</th>
                    <th>İşletim Sistemi</th>
                    <th>CPU</th>
                    <th>GPU</th>
                    <th>RAM</th>
                    <th>Ekran</th>
                    <th>MC Ver.</th>
                    <th>Loader</th>
                    <th>Launcher</th>
                </tr>
                {% for r in recent %}
                <tr>
                    <td>{{ r['timestamp'] }}</td>
                    <td><strong style="color:#3fb950;">{{ r['username'] or 'N/A' }}</strong></td>
                    <td><span class="badge badge-blue">{{ r['action'] }}</span></td>
                    <td>{{ r['ip'] or 'N/A' }}</td>
                    <td style="font-size:11px;">{{ (r['os'] or '')[:40] }}</td>
                    <td style="font-size:11px;">{{ (r['cpu'] or '')[:30] }}</td>
                    <td style="font-size:11px;">{{ (r['gpu'] or '')[:30] }}</td>
                    <td>{{ r['ram_total'] or 0 }} MB</td>
                    <td>{{ r['resolution'] or 'N/A' }}</td>
                    <td><span class="badge badge-purple">{{ r['mc_version'] or 'N/A' }}</span></td>
                    <td>{{ r['loader_type'] or 'Vanilla' }}</td>
                    <td><span class="badge badge-green">v{{ r['launcher_version'] or '?' }}</span></td>
                </tr>
                {% endfor %}
                {% if not recent %}
                <tr><td colspan="12">Kayıt bulunamadı.</td></tr>
                {% endif %}
            </table>
        </div>
    </body>
    </html>
    """
    return render_template_string(html, 
        total_plays=total_plays, unique_players=unique_players, today_plays=today_plays,
        avg_ram=avg_ram, config=config_data, action_stats=action_stats, os_stats=os_stats,
        gpu_stats=gpu_stats, cpu_stats=cpu_stats, res_stats=res_stats, version_stats=version_stats,
        recent=recent)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=10230)

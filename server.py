import sqlite3
import time
from flask import Flask, request, jsonify

app = Flask(__name__)
DB_NAME = 'usuarios .db'

# FunÃ§Ã£o para conectar ao banco
def get_db():
    conn = sqlite3.connect(DB_NAME)
    conn.row_factory = sqlite3.Row  # para acessar colunas por nome
    return conn

# Criar tabela se nÃ£o existir
def init_db():
    with get_db() as conn:
        conn.execute('''
            CREATE TABLE IF NOT EXISTS maquinas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                hostname TEXT NOT NULL,
                username TEXT NOT NULL,
                session TEXT NOT NULL,
                om TEXT NOT NULL,
                last_seen REAL NOT NULL,
                status TEXT DEFAULT 'offline',
                UNIQUE(ip, hostname, username)
            )
        ''')
        conn.commit()

# Atualiza status das mÃ¡quinas com base no tempo limite
def atualizar_status_offline(tempo_limite=30):
    agora = time.time()
    with get_db() as conn:
        conn.execute('''
            UPDATE maquinas
            SET status = 'offline'
            WHERE ?
                AND status = 'online'
                AND (? - last_seen) > ?
        ''', (agora, agora, tempo_limite))
        conn.commit()

# Rota para receber POST das mÃ¡quinas
@app.route('/heartbeat', methods=['POST'])
def heartbeat():
    data = request.get_json()

    # Campos obrigatÃ³rios
    required_fields = ['ip', 'hostname', 'username', 'session', 'om']
    for field in required_fields:
        if field not in data:
            return jsonify({"error": f"Campo {field} ausente"}), 400

    ip = data['ip']
    hostname    = data['hostname']
    username    = data['username']
    session     = data['session']
    om       = data['om']
    now         = time.time()
    print(om)
    try:
        with get_db() as conn:
            cursor = conn.cursor()
            cursor.execute('''
                INSERT INTO maquinas (ip, hostname, username, session, om, last_seen, status)
                VALUES (?, ?, ?, ?, ?, ?, 'online')
                ON CONFLICT(ip, hostname, username)
                DO UPDATE SET
                    last_seen = ?,
                    status = 'online'
            ''', (ip, hostname, username, session, om, now, now))
            conn.commit()
        return jsonify({"status": "ok"}), 200
    except Exception as e:
        return jsonify({"error": str(e)}), 500

# Rota para visualizar todas as mÃ¡quinas
@app.route('/machines', methods=['GET'])
def listar_maquinas():
    atualizar_status_offline(tempo_limite=30)  # Garantir status atualizado antes de mostrar

    with get_db() as conn:
        rows = conn.execute('SELECT * FROM maquinas ORDER BY last_seen DESC').fetchall()
        maquinas = [dict(row) for row in rows]
        return jsonify(maquinas), 200

# Rodar o servidor
if __name__ == '__main__':
    init_db()
    app.run()
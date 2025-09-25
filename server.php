<?php
header('Content-Type: application/json');

define('DB_NAME', 'usuarios.db');

// Conectar ao banco SQLite
function getDb() {
    try {
        $db = new PDO("sqlite:" . DB_NAME);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $db;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao conectar ao banco: " . $e->getMessage()]);
        exit;
    }
}

// Inicializar tabela se não existir
function initDb() {
    $sql = "
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
    ";
    try {
        $db = getDb();
        $db->exec($sql);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao criar tabela: " . $e->getMessage()]);
        exit;
    }
}

// Atualizar máquinas offline com base no tempo limite (30s)
function atualizarStatusOffline($tempoLimite = 30) {
    $agora = time();
    try {
        $db = getDb();
        $stmt = $db->prepare("
            UPDATE maquinas 
            SET status = 'offline' 
            WHERE status = 'online' 
              AND (? - last_seen) > ?
        ");
        $stmt->execute([$agora, $tempoLimite]);
    } catch (PDOException $e) {
        error_log("Erro ao atualizar status offline: " . $e->getMessage());
    }
}

// Endpoint: POST /heartbeat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['REQUEST_URI'] === '/heartbeat') {
    initDb();

    $input = json_decode(file_get_contents('php://input'), true);

    $required = ['ip', 'hostname', 'username', 'session', 'om'];
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            http_response_code(400);
            echo json_encode(["error" => "Campo {$field} ausente"]);
            exit;
        }
    }

    $ip = $input['ip'];
    $hostname = $input['hostname'];
    $username = $input['username'];
    $session = $input['session'];
    $om = $input['om'];
    $now = time();

    try {
        $db = getDb();
        $stmt = $db->prepare("
            INSERT INTO maquinas (ip, hostname, username, session, om, last_seen, status)
            VALUES (?, ?, ?, ?, ?, ?, 'online')
            ON CONFLICT(ip, hostname, username)
            DO UPDATE SET last_seen = ?, status = 'online'
        ");
        $stmt->execute([$ip, $hostname, $username, $session, $om, $now, $now]);

        http_response_code(200);
        echo json_encode(["status" => "ok"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// Endpoint: GET /machines
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/machines') {
    initDb();
    atualizarStatusOffline(30); // Atualiza status antes de listar

    try {
        $db = getDb();
        $stmt = $db->query("SELECT * FROM maquinas ORDER BY last_seen DESC");
        $maquinas = $stmt->fetchAll();

        http_response_code(200);
        echo json_encode($maquinas);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// Se nenhuma rota for reconhecida
http_response_code(404);
echo json_encode(["error" => "Rota não encontrada"]);
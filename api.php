<?php
$dbFile = '/var/lib/api_usuarios/maquinas.db';

function getDB() {
    global $dbFile;
    $pdo = new PDO("sqlite:$dbFile");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function initDB() {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS maquinas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            hostname TEXT NOT NULL,
            username TEXT NOT NULL,
            name TEXT NOT NULL,
            session TEXT NOT NULL,
            om TEXT NOT NULL,
            last_seen REAL NOT NULL,
            status TEXT DEFAULT 'offline',
            UNIQUE(hostname, username)
        )
    ");
}

function atualizarStatusOffline($tempo_limite = 30) {
    $agora = time();
    $limite = $agora - $tempo_limite;

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE maquinas
        SET status = 'offline'
        WHERE status = 'online' AND last_seen < ?
    ");
    $stmt->execute([$limite]);
}

// Inicializa banco
initDB();

// Verifica ação pela query string
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'heartbeat') {
    $input = json_decode(file_get_contents('php://input'), true);
    $required = ['ip', 'hostname', 'username', 'name', 'session', 'om'];

    foreach ($required as $field) {
        if (!isset($input[$field])) {
            http_response_code(400);
            echo json_encode(["error" => "Campo $field ausente"]);
            exit;
        }
    }

    $ip = $input['ip'];
    $hostname = $input['hostname'];
    $username = $input['username'];
    $name = $input['name'];
    $session = $input['session'];
    $om = $input['om'];
    $now = time();

    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO maquinas (ip, hostname, username, name, session, om, last_seen, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'online')
            ON CONFLICT(hostname, username) DO UPDATE SET
                last_seen = ?, status = 'online', ip = ?
        ");
        $stmt->execute([$ip, $hostname, $username, $name, $session, $om, $now, $now, $ip]);

        echo json_encode(["status" => "ok"]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'machines') {
    atualizarStatusOffline(30);
    $db = getDB();
    $stmt = $db->query("SELECT * FROM maquinas ORDER BY last_seen DESC");
    $maquinas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($maquinas);
    exit;
}
?>

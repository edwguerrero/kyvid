<?php
// api/db_connections.php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Security.php';

header('Content-Type: application/json');

function checkAdmin() {
    if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Acceso denegado.']);
        exit;
    }
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    try {
        $stmt = $pdo->query("SELECT id, name, type, host, port, database_name, database_schema, username, is_active FROM db_connections ORDER BY name");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save') {
    checkAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = $input['id'] ?? null;
    $name = $input['name'] ?? '';
    $type = $input['type'] ?? 'mysql';
    $host = $input['host'] ?? '';
    $port = $input['port'] ?? ($type === 'mysql' ? 3306 : 5432);
    $dbName = $input['database_name'] ?? '';
    $dbSchema = $input['database_schema'] ?? '';
    $user = $input['username'] ?? '';
    $pass = $input['password'] ?? '';
    $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 0;

    if (empty($name) || empty($host) || empty($dbName) || empty($user)) {
        echo json_encode(['success' => false, 'error' => 'Nombre, Host, DB y Usuario son obligatorios.']);
        exit;
    }

    try {
        // Encriptar password si se envió una nueva
        if ($id) {
            if (!empty($pass)) {
                $encPass = Security::encrypt($pass);
                $stmt = $pdo->prepare("UPDATE db_connections SET name=?, type=?, host=?, port=?, database_name=?, database_schema=?, username=?, password_encrypted=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $type, $host, $port, $dbName, $dbSchema, $user, $encPass, $isActive, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE db_connections SET name=?, type=?, host=?, port=?, database_name=?, database_schema=?, username=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $type, $host, $port, $dbName, $dbSchema, $user, $isActive, $id]);
            }
        } else {
            $encPass = Security::encrypt($pass);
            $stmt = $pdo->prepare("INSERT INTO db_connections (name, type, host, port, database_name, database_schema, username, password_encrypted, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $host, $port, $dbName, $dbSchema, $user, $encPass, $isActive]);
        }
        
        // Si activamos esta, desactivar el resto? (Opcional, pero suele haber solo una activa)
        if ($isActive) {
            $pdo->prepare("UPDATE db_connections SET is_active = 0 WHERE id != ?")->execute([$id ?? $pdo->lastInsertId()]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    checkAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;
    if (!$id) exit;

    try {
        $stmt = $pdo->prepare("DELETE FROM db_connections WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'test') {
    checkAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $host = $input['host'];
    $port = $input['port'];
    $dbName = $input['database_name'];
    $user = $input['username'];
    $pass = $input['id'] ? null : $input['password']; // Si es edición y no mandó pass, hay que buscarla
    $type = $input['type'] ?? 'mysql';
    $dbSchema = $input['database_schema'] ?? '';

    try {
        if ($input['id'] && empty($pass)) {
            $stmt = $pdo->prepare("SELECT password_encrypted FROM db_connections WHERE id = ?");
            $stmt->execute([$input['id']]);
            $pass = Security::decrypt($stmt->fetchColumn());
        }

        if ($type === 'pgsql') {
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";
        } else {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4";
        }

        $testPdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        
        if ($type === 'pgsql' && !empty($dbSchema)) {
             $testPdo->exec("SET search_path TO " . $dbSchema);
        }

        echo json_encode(['success' => true, 'message' => '¡Conexión Exitosa!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Fallo de conexión: ' . $e->getMessage()]);
    }
    exit;
}

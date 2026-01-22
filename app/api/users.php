<?php
// api/users.php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Security.php';

header('Content-Type: application/json');

// Only Admins can manage users
if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Acceso Denegado']);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    try {
        $stmt = $pdo->query("SELECT id, code, name, role, attributes_json, is_active, last_login, created_at FROM auth_accounts ORDER BY name ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = $input['id'] ?? null;
    $code = trim($input['code'] ?? '');
    $name = trim($input['name'] ?? '');
    $pass = $input['password'] ?? '';
    $role = $input['role'] ?? 'viewer';
    $attr = $input['attributes_json'] ?? '{}';
    $active = $input['is_active'] ?? 1;
    
    if (empty($code) || empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Código y Nombre son obligatorios']);
        exit;
    }
    
    // Validate JSON
    if (json_decode($attr) === null) {
        echo json_encode(['success' => false, 'error' => 'JSON de atributos inválido']);
        exit;
    }

    try {
        if ($id) {
            // Edit Mode
            if (!empty($pass)) {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE auth_accounts SET name=?, password_hash=?, role=?, attributes_json=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $hash, $role, $attr, $active, $id]);
            } else {
                // Keep old pass
                $stmt = $pdo->prepare("UPDATE auth_accounts SET name=?, role=?, attributes_json=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $role, $attr, $active, $id]);
            }
        } else {
            // Create Mode
            if (empty($pass)) {
                echo json_encode(['success' => false, 'error' => 'La contraseña es obligatoria para nuevos usuarios']);
                exit;
            }
            // Check duplicate code
            $dup = $pdo->prepare("SELECT id FROM auth_accounts WHERE code = ?");
            $dup->execute([$code]);
            if ($dup->fetch()) {
                 echo json_encode(['success' => false, 'error' => 'El código de usuario ya existe']);
                 exit;
            }

            $hash = password_hash($pass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO auth_accounts (code, name, password_hash, role, attributes_json, is_active) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$code, $name, $hash, $role, $attr, $active]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;
    
    if (!$id) { 
        echo json_encode(['success' => false, 'error' => 'ID inválido']); 
        exit;
    }
    
    // Prevent self-delete
    if ($id == $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'error' => 'No puedes eliminar tu propia cuenta mientras estás conectado']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM auth_accounts WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
         echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

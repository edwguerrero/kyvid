<?php
// api/auth.php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// --- NEW USER SYSTEM (RBAC) ---

// Step 1: Validate User Code (Pre-Login)
// Step 1: Validate User Code (Pre-Login)
if ($action === 'check_code') {
    $input = json_decode(file_get_contents('php://input'), true);
    $code = trim($input['code'] ?? '');
    
    try {
        // Case-Insensitive Search
        $stmt = $pdo->prepare("SELECT name, is_active FROM auth_accounts WHERE code = ?");
        $stmt->execute([$code]);
        $user = $stmt->fetch();
        
        if ($user) {
            if ($user['is_active'] == 1) {
                echo json_encode(['success' => true, 'name' => $user['name']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Tu cuenta está desactivada. Contacta al administrador.']);
            }
        } else {
            // Intentar búsqueda insensible si la exacta falló (útil para MySQL con collation sensible o Postgres)
            $stmt = $pdo->prepare("SELECT name, is_active, code FROM auth_accounts WHERE LOWER(code) = LOWER(?)");
            $stmt->execute([$code]);
            $userLoose = $stmt->fetch();
            
            if ($userLoose) {
                 if ($userLoose['is_active'] == 1) {
                    // Devolvemos success pero nota: el frontend usará el código escrito por el usuario, 
                    // tal vez deberíamos devolver el código real corregido.
                    echo json_encode(['success' => true, 'name' => $userLoose['name'], 'corrected_code' => $userLoose['code']]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Tu cuenta está desactivada.']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Step 2: Login with Password
if ($action === 'login_account') {
    $input = json_decode(file_get_contents('php://input'), true);
    $code = trim($input['code'] ?? '');
    $password = $input['password'] ?? '';
    $remember = $input['remember'] ?? false; // Future use

    try {
        $stmt = $pdo->prepare("SELECT * FROM auth_accounts WHERE code = ? AND is_active = 1");
        $stmt->execute([$code]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // Success!
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_code'] = $user['code'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_attr'] = json_decode($user['attributes_json'] ?? '{}', true);
            
            // Sync legacy isAdmin flag for compatibility
            $_SESSION['isAdmin'] = ($user['role'] === 'admin');
            
            // Update Last Login
            $upd = $pdo->prepare("UPDATE auth_accounts SET last_login = NOW() WHERE id = ?");
            $upd->execute([$user['id']]);

            echo json_encode(['success' => true, 'role' => $user['role']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- LEGACY SUPPORT (Redirects to new system) ---
// This handles the old modal login if the frontend isn't updated instantly
if ($action === 'login') {
    // Legacy requests usually imply 'admin' access intention
    // Checks against the old 'settings' table OR map to 'admin' user in auth_accounts
    // For simplicity, we keep the previous logic BUT map it to session variables
    $input = json_decode(file_get_contents('php://input'), true);
    $password = $input['password'] ?? '';

     // Logic copied from previous step but syncing new session vars
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'admin_password'");
    $stmt->execute();
    $dbPass = $stmt->fetchColumn();
    if (!$dbPass) $dbPass = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : 'ADMINISTRATOR';

    $valid = false;
    if (strpos($dbPass, '$2y$') === 0) {
        if (password_verify($password, $dbPass)) $valid = true;
    } elseif ($password === $dbPass) {
        $valid = true;
    }

    if ($valid) {
        $_SESSION['isAdmin'] = true;
        // Mock a user context for admin
        $_SESSION['user_code'] = 'admin';
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_name'] = 'Administrador Legacy';
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
    }
    exit;
}

if ($action === 'change_password') {
    if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $oldPass = $input['old_password'] ?? '';
    $newPass = $input['new_password'] ?? '';
    
    // ALSO update the auth_accounts table for 'admin' user if exists
    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM auth_accounts WHERE code = 'admin'");
        $stmt->execute();
        $adminHash = $stmt->fetchColumn();
        
        if ($adminHash && password_verify($oldPass, $adminHash)) {
             $newHash = password_hash($newPass, PASSWORD_BCRYPT);
             $upd = $pdo->prepare("UPDATE auth_accounts SET password_hash = ? WHERE code = 'admin'");
             $upd->execute([$newHash]);
             
             // Also update Legacy Setting for backup
             $updSetting = $pdo->prepare("UPDATE settings SET value = ? WHERE `key` = 'admin_password'");
             $updSetting->execute([$newHash]);
             
             echo json_encode(['success' => true]);
             exit;
        }
    } catch(Exception $e) { /* Fallback to legacy */ }

    // ... (Legacy Change Password Fallback kept) ...
    // Verify current password from settings
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'admin_password'");
    $stmt->execute();
    $currentStored = $stmt->fetchColumn();
    
    $validOld = false;
    if (strpos($currentStored, '$2y$') === 0) {
        if (password_verify($oldPass, $currentStored)) $validOld = true;
    } else {
        if ($oldPass === $currentStored) $validOld = true;
    }

    if ($validOld) {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE settings SET value = ? WHERE `key` = 'admin_password'")->execute([$hash]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
    }
    exit;
}

if ($action === 'logout') {
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'status') {
    echo json_encode([
        'success' => true, 
        'isAdmin' => isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] === true,
        'user' => [
            'name' => $_SESSION['user_name'] ?? 'Invitado',
            'role' => $_SESSION['user_role'] ?? 'guest',
            'code' => $_SESSION['user_code'] ?? null
        ]
    ]);
    exit;
}

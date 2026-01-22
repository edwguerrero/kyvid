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

        if ($user) {
            $isValid = false;
            if (strpos($user['password_hash'], '$2y$') === 0) {
                if (password_verify($password, $user['password_hash'])) $isValid = true;
            } else {
                if ($password === $user['password_hash']) $isValid = true;
            }

            if ($isValid) {
                // Success!
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_code'] = $user['code'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_attr'] = json_decode($user['attributes_json'] ?? '{}', true);
                
                // Sync legacy isAdmin flag for compatibility
                $_SESSION['isAdmin'] = ($user['role'] === 'admin');
                
                // Auto-upgrade to Hash if it was plaintext (Security Healing)
                if (strpos($user['password_hash'], '$2y$') !== 0) {
                    $newSecureHash = password_hash($password, PASSWORD_BCRYPT);
                    $updHash = $pdo->prepare("UPDATE auth_accounts SET password_hash = ? WHERE id = ?");
                    $updHash->execute([$newSecureHash, $user['id']]);
                    
                    // Also sync legacy settings if it's the admin
                    if ($user['code'] === 'admin') {
                        $updSet = $pdo->prepare("UPDATE settings SET value = ? WHERE `key` = 'admin_password'");
                        $updSet->execute([$newSecureHash]);
                    }
                }

                echo json_encode(['success' => true, 'role' => $user['role']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
            }
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
        $_SESSION['user_code'] = 'admin';
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_name'] = 'Administrador';
        
        // Try to find the user_id for 'admin' to avoid issues with change_password
        try {
            $stmt = $pdo->prepare("SELECT id FROM auth_accounts WHERE code = 'admin'");
            $stmt->execute();
            $uId = $stmt->fetchColumn();
            if ($uId) $_SESSION['user_id'] = $uId;
        } catch(Exception $e) { }

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Contraseña incorrecta']);
    }
    exit;
}

if ($action === 'change_password') {
    $userId = $_SESSION['user_id'] ?? null;
    $userCode = $_SESSION['user_code'] ?? null;

    // Fallback for sessions started before the update
    if (!$userCode && isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] === true) {
        $userCode = 'admin';
    }

    if (!$userId && !$userCode) {
        echo json_encode(['success' => false, 'error' => 'Sesión expirada. Por favor cierre sesión e inicie de nuevo.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $oldPass = $input['old_password'] ?? '';
    $newPass = $input['new_password'] ?? '';
    $userId = $_SESSION['user_id'];
    $userCode = $_SESSION['user_code'];

    try {
        // 1. Verify current password
        if ($userId) {
            $stmt = $pdo->prepare("SELECT id, password_hash, code FROM auth_accounts WHERE id = ?");
            $stmt->execute([$userId]);
        } else {
            $stmt = $pdo->prepare("SELECT id, password_hash, code FROM auth_accounts WHERE code = ?");
            $stmt->execute([$userCode]);
        }
        $userRow = $stmt->fetch();
        $currentHash = $userRow['password_hash'] ?? null;
        $actualUserId = $userRow['id'] ?? null;
        $actualUserCode = $userRow['code'] ?? null;
        
        if ($userRow) {
            $currentHash = $userRow['password_hash'];
            $actualUserId = $userRow['id'];
            $actualUserCode = $userRow['code'];
            
            // Allow plaintext comparison if not hashed (Legacy transition)
            $isOldPasswordCorrect = false;
            if (strpos($currentHash, '$2y$') === 0) {
                if (password_verify($oldPass, $currentHash)) $isOldPasswordCorrect = true;
            } else {
                if ($oldPass === $currentHash) $isOldPasswordCorrect = true;
            }

            if ($isOldPasswordCorrect) {
                 $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                 
                 // 2. Update auth_accounts
                 $upd = $pdo->prepare("UPDATE auth_accounts SET password_hash = ? WHERE id = ?");
                 $upd->execute([$newHash, $actualUserId]);
                 
                 // 3. If User is admin, also update legacy settings for compatibility
                 if ($actualUserCode === 'admin') {
                     $updSetting = $pdo->prepare("UPDATE settings SET value = ? WHERE `key` = 'admin_password'");
                     $updSetting->execute([$newHash]);
                 }
                 
                 echo json_encode(['success' => true]);
                 exit;
            } else {
                echo json_encode(['success' => false, 'error' => 'Contraseña actual incorrecta']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
        }
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'register') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $name = trim($input['name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email inválido']);
        exit;
    }
    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Nombre es requerido']);
        exit;
    }

    try {
        // Build Email Content first to check SMTP
        $stmt = $pdo->prepare("SELECT * FROM connections WHERE type = 'SMTP' AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $conn = $stmt->fetch();

        if (!$conn) {
            echo json_encode(['success' => false, 'error' => 'El sistema de registro no está disponible temporalmente (Servicio de correo no configurado).']);
            exit;
        }

        // Check if exists
        $stmt = $pdo->prepare("SELECT id FROM auth_accounts WHERE code = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Este correo ya está registrado. Si olvidaste tu contraseña, por favor utiliza la opción "Olvidé mi contraseña".']);
            exit;
        }

        // Generate Password
        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        $password = substr(str_shuffle($chars), 0, 8);
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Prep Email
        require_once '../src/SimpleSMTP.php';
        require_once '../src/Security.php';
        
        $config = json_decode($conn['config_json'], true);
        $smtpUser = $config['username'] ?? '';
        $creds = json_decode(Security::decrypt($conn['encrypted_creds']) ?: '{}', true);
        $smtpPass = $creds['password'] ?? '';
        $smtpHost = $config['host'] ?? '';
        $smtpPort = $config['port'] ?? 465;

        // Try to send email BEFORE creating the user
        $mailer = new SimpleSMTP($smtpHost, $smtpPort, $smtpUser, $smtpPass);
        
        $subject = "🚀 Bienvenido a Kyvid Flow - Tus credenciales";
        $body = "<h2>¡Hola $name!</h2>
                 <p>Gracias por registrarte en la demo de <b>Kyvid Flow</b>.</p>
                 <p>Tus datos de acceso son:</p>
                 <div style='background:#f4f4f4; padding: 15px; border-radius: 5px; font-family: monospace;'>
                    <b>Usuario:</b> $email<br>
                    <b>Contraseña:</b> $password
                 </div>
                 <p>Puedes iniciar sesión aquí: <a href='https://flow.kyvid.com'>Kyvid Flow Login</a></p>
                 <br>
                 <p><i>Atentamente,<br>El equipo de Kyvid.</i></p>";
        
        try {
            $mailer->send($email, $subject, $body);
            
            // If email sent successfully, create the user
            $stmt = $pdo->prepare("INSERT INTO auth_accounts (code, name, password_hash, role, is_active) VALUES (?, ?, ?, 'viewer', 1)");
            $stmt->execute([$email, $name, $hash]);

            echo json_encode(['success' => true, 'message' => '¡Registro exitoso! Revisa tu bandeja de entrada (y spam) para obtener tus datos de acceso.']);
        } catch (Exception $ex) {
            $msg = $ex->getMessage();
            if (strpos($msg, '1062') !== false) {
                echo json_encode(['success' => false, 'error' => 'Este usuario ya se encuentra registrado. Si has olvidado tu contraseña, restaurala utilizando la opción "Olvidé mi contraseña".']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Error de envío de correo: ' . $msg . '. Revisa la configuración en la pestaña Servicios.']);
            }
        }

    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, '1062') !== false) {
            echo json_encode(['success' => false, 'error' => 'Este correo ya se encuentra registrado. Por favor utiliza la opción "Olvidé mi contraseña".']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Error: ' . $msg]);
        }
    }
    exit;
}

if ($action === 'forgot_password') {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Email inválido']);
        exit;
    }

    try {
        // 1. Check if user exists (Case-Insensitive for better usability)
        $stmt = $pdo->prepare("SELECT id, name FROM auth_accounts WHERE LOWER(code) = LOWER(?)");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            // Fallback: Si no lo encuentra, intentamos una búsqueda parcial por si el campo está truncado en DB 
            // (esto es temporal mientras el usuario actualiza su campo 'code' a 100 caracteres)
            $stmt = $pdo->prepare("SELECT id, name FROM auth_accounts WHERE code LIKE ? LIMIT 1");
            $stmt->execute([substr($email, 0, 19) . '%']);
            $user = $stmt->fetch();
        }

        if (!$user) {
            echo json_encode(['success' => false, 'error' => 'No encontramos ninguna cuenta asociada a este correo.']);
            exit;
        }

        // 2. Check SMTP configuration
        $stmt = $pdo->prepare("SELECT * FROM connections WHERE type = 'SMTP' AND is_active = 1 LIMIT 1");
        $stmt->execute();
        $conn = $stmt->fetch();

        if (!$conn) {
            echo json_encode(['success' => false, 'error' => 'El servicio de recuperación no está disponible temporalmente (Sin SMTP).']);
            exit;
        }

        // 3. Generate New Password
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $newPassword = substr(str_shuffle($chars), 0, 8);
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

        // 4. Send Email FIRST
        require_once '../src/SimpleSMTP.php';
        require_once '../src/Security.php';
        
        $config = json_decode($conn['config_json'], true);
        $creds = json_decode(Security::decrypt($conn['encrypted_creds']) ?: '{}', true);
        $smtpPass = $creds['password'] ?? '';
        
        $mailer = new SimpleSMTP($config['host'], $config['port'], $config['username'], $smtpPass);
        
        $subject = "🔒 Recuperación de Contraseña - Kyvid Flow";
        $body = "<h2>Hola {$user['name']}</h2>
                 <p>Has solicitado restablecer tu contraseña en <b>Kyvid Flow</b>.</p>
                 <p>Tu nueva contraseña temporal es:</p>
                 <div style='background:#f4f4f4; padding: 15px; border-radius: 5px; font-family: monospace;'>
                    <b>Contraseña:</b> $newPassword
                 </div>
                 <p>Puedes iniciar sesión aquí: <a href='https://flow.kyvid.com'>Kyvid Flow Login</a></p>
                 <br>
                 <p><i>Atentamente,<br>Soporte de Kyvid Flow.</i></p>";
        
        $mailer->send($email, $subject, $body);

        // 5. Update Database ONLY if email sent
        $upd = $pdo->prepare("UPDATE auth_accounts SET password_hash = ? WHERE id = ?");
        $upd->execute([$newHash, $user['id']]);

        echo json_encode(['success' => true, 'message' => 'Te hemos enviado una nueva contraseña a tu correo electrónico.']);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
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

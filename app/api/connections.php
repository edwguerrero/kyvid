<?php
// api/connections.php
session_start();
error_reporting(0);
ini_set('display_errors', 0);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Security.php';

header('Content-Type: application/json');

// Global Exception Handler
set_exception_handler(function($e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

function checkAdmin() {
    Security::checkAdmin();
}

$action = $_GET['action'] ?? '';

// --- LIST ---
if ($action === 'list') {
    checkAdmin();
    try {
        $stmt = $pdo->query("SELECT id, name, type, provider, config_json, is_active FROM connections ORDER BY type, name");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decodificar JSON para frontend
        foreach ($rows as &$r) {
            $r['config'] = json_decode($r['config_json'], true);
            unset($r['config_json']);
        }
        
        echo json_encode(['success' => true, 'data' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- SAVE ---
if ($action === 'save') {
    checkAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = $input['id'] ?? null;
    $name = $input['name'] ?? 'Nueva Conexión';
    $type = $input['type'] ?? 'OTHER';
    $provider = $input['provider'] ?? null; // gmail, openai, etc.
    $isActive = $input['is_active'] ?? 1;
    
    // Separar configs públicas vs privadas
    $configData = $input['config'] ?? []; 
    $credsData = $input['creds'] ?? [];
    
    try {
        if ($id) {
            // EDITAR
            // Obtener credenciales viejas para no borrarlas si vienen vacías
            $stmt = $pdo->prepare("SELECT encrypted_creds FROM connections WHERE id = ?");
            $stmt->execute([$id]);
            $oldEnc = $stmt->fetchColumn();
            
            $existingCreds = [];
            if ($oldEnc) {
                $decrypted = Security::decrypt($oldEnc); 
                // Fix: Security::decrypt sometimes returns false on fail, check that.
                // Assuming decrypt returns the string. 
                // Double encrypt safety check: Logic in Security class might define if it returns json string.
                // Here we assume $oldEnc decrypts to the JSON string.
            }
            // Actually, Security::decrypt decrypts a string.
            // If the user sends a password like "*****", we ignore it.
            
            // Re-encrypt credentials only if updated
            $finalCreds = json_decode(Security::decrypt($oldEnc) ?: '{}', true);
            
            foreach ($credsData as $k => $v) {
                if (!empty($v) && $v !== '*****') {
                    $finalCreds[$k] = $v;
                }
            }
            
            $newEncCreds = Security::encrypt(json_encode($finalCreds));
            $newConfigJson = json_encode($configData);
            
            $sql = "UPDATE connections SET name=?, type=?, provider=?, config_json=?, encrypted_creds=?, is_active=? WHERE id=?";
            $pdo->prepare($sql)->execute([$name, $type, $provider, $newConfigJson, $newEncCreds, $isActive, $id]);
            
        } else {
            // INSERTAR
            $newEncCreds = Security::encrypt(json_encode($credsData));
            $newConfigJson = json_encode($configData);
            
            $sql = "INSERT INTO connections (name, type, provider, config_json, encrypted_creds, is_active) VALUES (?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$name, $type, $provider, $newConfigJson, $newEncCreds, $isActive]);
        }
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- DELETE ---
if ($action === 'delete') {
    checkAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;
    
    if ($id) {
        $pdo->prepare("DELETE FROM connections WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No ID']);
    }
    exit;
}

// --- TEST (Specific for Types) ---
if ($action === 'test') {
    checkAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $input['type'] ?? '';
    $creds = $input['creds'] ?? [];
    $config = $input['config'] ?? [];
    
    // Si viene ID, cargamos credenciales de DB en vez de usar las del input (para prueba segura)
    if (!empty($input['id'])) {
        $stmt = $pdo->prepare("SELECT encrypted_creds, config_json FROM connections WHERE id = ?");
        $stmt->execute([$input['id']]);
        $row = $stmt->fetch();
        if ($row) {
            $dbCreds = json_decode(Security::decrypt($row['encrypted_creds']) ?: '{}', true);
            $dbConfig = json_decode($row['config_json'], true);
            // Merge: Input prevalece si no es mascara
            $config = array_merge($dbConfig, $config); 
            foreach ($creds as $k => $v) {
                if ($v !== '*****') $dbCreds[$k] = $v;
            }
            $creds = $dbCreds;
        }
    }

    if ($type === 'SMTP') {
        require_once __DIR__ . '/../src/SimpleSMTP.php';
        
        $host = $config['host'] ?? '';
        $port = $config['port'] ?? '';
        $user = $config['username'] ?? ($config['user'] ?? '');
        $pass = $creds['password'] ?? '';
        $to = $input['recipient'] ?? $user;

        if (empty($host) || empty($user) || empty($pass)) {
            throw new Exception("Configuración SMTP incompleta (Host, Usuario o Password faltantes).");
        }

        try {
            $smtp = new SimpleSMTP($host, $port, $user, $pass);
            $sent = $smtp->send($to, "Test Conexión Kyvid Flow", "<h1>Conexión OK</h1><p>Prueba exitosa desde Kyvid Flow.</p><p>Generado el: " . date('Y-m-d H:i:s') . "</p>");
            
            if ($sent) {
                echo json_encode(['success' => true, 'message' => 'Correo de prueba enviado a ' . $to]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Servidor SMTP rechazó el envío. Verifique sus credenciales (ej. ¿App Password en Gmail activo?).']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error SMTP: ' . $e->getMessage()]);
        }
    } elseif ($type === 'AI') {
         // Real AI Test
         $provider = $provider ?: ($config['provider'] ?? 'openai');
         $apiKey = $creds['api_key'] ?? '';
         $model = $config['model'] ?? '';
         $baseUrl = $config['base_url'] ?? '';
         
         if (empty($apiKey)) throw new Exception("API Key no configurada.");

         // Auto-corrección: Si el proveedor es OpenAI pero la clave es de Google (AIza...), corregir a Gemini
         if ($provider === 'openai' && strpos($apiKey, 'AIza') === 0) {
             $provider = 'gemini';
             if (empty($model)) $model = 'gemini-1.5-flash';
         }

         require_once __DIR__ . '/ai_service.php';
         
         try {
             $testPrompt = "Hola, responde solo con la palabra 'CONECTADO'";
             $response = callAiApi($provider, $apiKey, $baseUrl, $model, "Eres un asistente de pruebas.", $testPrompt);
             
             if (isset($response['error'])) {
                 echo json_encode(['success' => false, 'error' => "Error de API ($provider): " . $response['error']]);
             } else {
                 echo json_encode(['success' => true, 'message' => "Conexión con [$provider] exitosa. La IA respondió: " . $response['content']]);
             }
         } catch (Exception $e) {
             echo json_encode(['success' => false, 'error' => "Error al probar IA ($provider): " . $e->getMessage()]);
         }
    } elseif ($type === 'TELEGRAM') {
         $token = $creds['bot_token'] ?? '';
         $chatId = $creds['chat_id'] ?? '';
         
         if (empty($token)) throw new Exception("Bot Token no configurado.");

         // Helper local para cURL (Telegram prefiere cURL para estabilidad)
         $callTelegram = function($method, $params = []) use ($token) {
             $ch = curl_init("https://api.telegram.org/bot$token/$method");
             curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
             curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
             if (!empty($params)) {
                 curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
             }
             $res = curl_exec($ch);
             curl_close($ch);
             return $res ? json_decode($res, true) : null;
         };

         // 1. Verificar Bot
         $jsonMe = $callTelegram('getMe');
         if (!$jsonMe) throw new Exception("No hubo respuesta de Telegram API. Verifique su conexión a internet o el módulo php_curl.");
         if (!$jsonMe['ok']) throw new Exception("Token Inválido: " . $jsonMe['description']);
         
         $botName = "@" . $jsonMe['result']['username'];

         // 2. Si hay Chat ID, enviar mensaje de prueba
         if (!empty($chatId)) {
             $escapedBotName = htmlspecialchars($botName);
             $jsonSend = $callTelegram('sendMessage', [
                 'chat_id' => $chatId,
                 'text' => "🔔 <b>Kyvid Alertas</b>\nTest de conexión exitoso para el bot: $escapedBotName\n📅 Fecha: " . date('Y-m-d H:i:s'),
                 'parse_mode' => 'HTML'
             ]);
             
             if ($jsonSend && $jsonSend['ok']) {
                 echo json_encode(['success' => true, 'message' => "Bot $botName OK. ¡Mensaje de prueba enviado!"]);
             } else {
                 $err = $jsonSend['description'] ?? 'Error desconocido';
                 echo json_encode(['success' => false, 'error' => "Bot $botName OK, pero falló el mensaje: $err"]);
             }
         } else {
             echo json_encode(['success' => true, 'message' => "Bot $botName conectado correctamente. (Falta Chat ID para enviar mensaje)"]);
         }
    } else {
        echo json_encode(['success' => true, 'message' => 'Tipo de conexión sin prueba automática.']);
    }
    exit;
}

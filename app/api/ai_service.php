<?php
// api/ai_service.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Security.php';

header('Content-Type: application/json');

/*
 * AI Service: Acts as a central gateway for LLM requests (OpenAI, Gemini, etc.)
 * NOW uses 'connections' table for credentials.
 */

$action = $_GET['action'] ?? '';

// --- AUTO-FIX: Create table if not exists ---
try {
    $pdo->query("SELECT 1 FROM `connections` LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `connections` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `name` varchar(100) NOT NULL,
      `type` varchar(50) NOT NULL COMMENT 'SMTP, IA, TELEGRAM, N8N, DRIVE',
      `provider` varchar(50) DEFAULT NULL COMMENT 'Subtipo: openai, gemini, gmail...',
      `encrypted_creds` text COMMENT 'JSON encriptado con claves privadas',
      `config_json` text COMMENT 'JSON legible con configuración pública',
      `is_active` tinyint(1) DEFAULT 1,
      `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}
// --------------------------------------------

// --- GET CONFIG (legacy support + new connections) ---
// Now fetches from connections table where type='AI'
if ($action === 'get_config') {
    $provider = $_GET['provider'] ?? '';
    if (!$provider) { echo json_encode(['success' => false, 'error' => 'No provider']); exit; }

    try {
        $stmt = $pdo->prepare("SELECT * FROM connections WHERE type='AI' AND provider=? AND is_active=1 LIMIT 1");
        $stmt->execute([$provider]);
        $conn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($conn) {
            $config = json_decode($conn['config_json'], true);
            $creds = json_decode(Security::decrypt($conn['encrypted_creds']), true);
            
            $data = [
                'provider' => $provider,
                'api_key' => $creds['api_key'] ?? '',
                'api_key_masked' => !empty($creds['api_key']) ? substr($creds['api_key'], 0, 4) . '***' : '',
                'model' => $config['model'] ?? '',
                'base_url' => $config['base_url'] ?? '',
                'system_prompt' => $config['system_prompt'] ?? '',
                'analysis_prompt' => $config['analysis_prompt'] ?? ''
            ];
            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            // Not found
            echo json_encode(['success' => true, 'data' => null]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- SAVE CONFIG ---
if ($action === 'save_config') {
    $input = json_decode(file_get_contents('php://input'), true);
    $provider = $input['provider'] ?? '';
    
    if (!$provider) { echo json_encode(['success' => false, 'error' => 'Provider Required']); exit; }

    try {
        // 1. Check if exists
        $stmt = $pdo->prepare("SELECT id, encrypted_creds FROM connections WHERE type='AI' AND provider=?");
        $stmt->execute([$provider]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $newCreds = ['api_key' => $input['api_key']];
        // If password/key sent is masked or empty but exists in DB, keep old one?
        // UI logic: if input[api_key] is empty, assume no change? 
        // Or if it starts with current mask? 
        // For simplicity: if input is NOT empty and NOT masked, update.
        if (empty($input['api_key']) || strpos($input['api_key'], '***') !== false) {
             if ($existing) {
                 $oldCreds = json_decode(Security::decrypt($existing['encrypted_creds']), true);
                 $newCreds['api_key'] = $oldCreds['api_key'] ?? '';
             }
        }

        $encCreds = Security::encrypt(json_encode($newCreds));
        
        $config = [
            'model' => $input['model'],
            'base_url' => $input['base_url'] ?? '',
            'system_prompt' => $input['system_prompt'],
            'analysis_prompt' => $input['analysis_prompt']
        ];
        $configJson = json_encode($config);

        if ($existing) {
            $sql = "UPDATE connections SET config_json=?, encrypted_creds=?, is_active=1 WHERE id=?";
            $pdo->prepare($sql)->execute([$configJson, $encCreds, $existing['id']]);
        } else {
            $sql = "INSERT INTO connections (name, type, provider, config_json, encrypted_creds, is_active) VALUES (?, 'AI', ?, ?, ?, 1)";
            $name = 'IA ' . ucfirst($provider);
            $pdo->prepare($sql)->execute([$name, $provider, $configJson, $encCreds]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// --- GENERATE (Run AI) ---
if ($action === 'generate') {
    $input = json_decode(file_get_contents('php://input'), true);
    $userPrompt = $input['prompt'] ?? '';
    $provider = $input['provider'] ?? 'openai';
    $context = $input['context'] ?? ''; // context string or object

    if (empty($userPrompt)) { echo json_encode(['success' => false, 'error' => 'Prompt missing']); exit; }

    // Fetch Creds: If provider specified, try that first. 
    // If not found or not specified, get the FIRST active AI connection.
    $stmt = $pdo->prepare("SELECT * FROM connections WHERE type='AI' AND (provider=? OR 1=1) AND is_active=1 ORDER BY (provider=?) DESC LIMIT 1");
    $stmt->execute([$provider, $provider]);
    $conn = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conn) {
        echo json_encode(['success' => false, 'error' => "No hay ningún servicio de IA configurado y activo en el panel de Conexiones."]);
        exit;
    }

    // Update variables based on actual provider found
    $provider = $conn['provider'];
    $config = json_decode($conn['config_json'], true);
    $creds = json_decode(Security::decrypt($conn['encrypted_creds']), true);
    $apiKey = $creds['api_key'] ?? '';
    
    $model = $config['model'] ?? '';
    $baseUrl = $config['base_url'] ?? '';
    
    // 4. Inyectar SCHEMA REAL de la base de datos seleccionada
    $schemaContext = "";
    try {
        $activeConnStmt = $pdo->query("SELECT * FROM db_connections WHERE is_active = 1 LIMIT 1");
        $activeConn = $activeConnStmt->fetch();
        
        $targetPdo = $pdo; // Por defecto local
        if ($activeConn) {
            $decPass = Security::decrypt($activeConn['password_encrypted']);
            $dsn = "mysql:host={$activeConn['host']};port={$activeConn['port']};dbname={$activeConn['database_name']};charset=utf8mb4";
            $targetPdo = new PDO($dsn, $activeConn['username'], $decPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }

        // Obtener tablas y columnas
        $tables = $targetPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $schemaContext = "ESTRUCTURA DE LA BASE DE DATOS (Usa SOLO estas tablas):\n";
        foreach ($tables as $table) {
            $cols = $targetPdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN);
            $schemaContext .= "- Tabla `$table`: (" . implode(', ', $cols) . ")\n";
        }
    } catch (Exception $e) {
        $schemaContext = "Error al leer schema: " . $e->getMessage();
    }

    // Elegir prompt según el modo (Generación SQL vs Análisis)
    $mode = $input['mode'] ?? 'sql'; 
    if ($mode === 'analysis') {
        $systemPrompt = $config['analysis_prompt'] ?? 'Eres un analista de datos experto.';
    } else {
        $systemPrompt = $config['system_prompt'] ?? 'Eres un experto en SQL.';
        
        // Agregar contexto de esquema al prompt de SQL
        $systemPrompt .= "\n\nCONTEXTO DE BASE DE DATOS ACTUAL:\n$schemaContext";

        // Refuerzo invisible para asegurar que el JSON tenga la estructura que espera la app
        $systemPrompt .= "\n\nIMPORTANTE: Tu respuesta DEBE ser un objeto JSON válido con estas llaves exactas: 
        'name' (string), 'description' (string), 
        'sql_query' (string SQL - DEBE ser SQL puro SIN placeholders como '?' o ':param'. Escribe la consulta base, el sistema añadirá los filtros automáticamente), 
        'columns_json' (array de strings de nombres de columnas), 
        'parameters_json' (array de objetos con field, label, type), 
        'php_script' (string opcional).";
    }

    // ... (Here goes the implementation of calling APIs: OpenAI, Gemini, etc.)
    // ... (For brevity, I will copy the previous logic but updated with these variables)
    
    // Simplification for the example:
    try {
        $responseText = "Simulated Response from $provider (Model: $model). \nPrompt was: $userPrompt";
        
        if ($provider === 'gemini') {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=$apiKey";
            $payload = [ "contents" => [ [ "parts" => [ [ "text" => $systemPrompt . "\nUser: " . $userPrompt ] ] ] ] ];
            // ... curl ...
        } elseif ($provider === 'openai') {
            $url = "https://api.openai.com/v1/chat/completions";
             $payload = [
                "model" => $model,
                "messages" => [
                    ["role" => "system", "content" => $systemPrompt],
                    ["role" => "user", "content" => $userPrompt]
                ]
            ];
            // ... curl ...
        }
        
        // Return dummy for now as the actual curl logic is long and redundant to re-write fully here unless requested.
        // But user asked for configuration to be fetched from module.
        // I will implement the CURL call for real.
        
        $response = callAiApi($provider, $apiKey, $baseUrl, $model, $systemPrompt, $userPrompt);
        
        if (isset($response['error'])) {
             echo json_encode(['success' => false, 'error' => $response['error']]);
        } else {
             $content = $response['content'];
             $jsonContent = null;
             
             // UNIFICADO: Extracción Robusta de JSON
             // 1. Intentar con bloques Markdown
             if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                 $jsonContent = json_decode(trim($matches[1]), true);
             } 
             
             // 2. Si falló, intentar buscar el primer { y el último }
             if (!$jsonContent) {
                 $start = strpos($content, '{');
                 $end = strrpos($content, '}');
                 if ($start !== false && $end !== false) {
                     $jsonString = substr($content, $start, $end - $start + 1);
                     $jsonContent = json_decode($jsonString, true);
                 }
             }

             // 3. Si sigue siendo nulo, es texto plano
             if (!$jsonContent) {
                 // Si es modo análisis, a veces el texto plano ES lo que queremos
                 if ($mode === 'analysis') {
                     $jsonContent = ['description' => $content];
                 } else {
                     $jsonContent = ['raw_text' => $content];
                 }
             }

             echo json_encode(['success' => true, 'data' => $jsonContent]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function callAiApi($provider, $apiKey, $baseUrl, $model, $sys, $user) {
    if ($provider === 'gemini') {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $data = ["contents" => [["parts" => [["text" => $sys . "\n" . $user]]]]];
        $headers = ["Content-Type: application/json"];
    } elseif ($provider === 'anthropic') {
        $url = "https://api.anthropic.com/v1/messages";
        $data = [
            "model" => $model,
            "max_tokens" => 4096,
            "system" => $sys,
            "messages" => [["role" => "user", "content" => $user]]
        ];
        $headers = [
            "x-api-key: $apiKey",
            "anthropic-version: 2023-06-01",
            "Content-Type: application/json"
        ];
    } elseif ($provider === 'openai' || $provider === 'deepseek' || $provider === 'groq' || $provider === 'local') {
        $url = ($baseUrl ?: "https://api.openai.com/v1") . "/chat/completions";
        $data = [
            "model" => $model,
            "messages" => [
                ["role" => "system", "content" => $sys],
                ["role" => "user", "content" => $user]
            ]
        ];
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer $apiKey"
        ];
    } else {
        return ['error' => "Provider $provider not supported"];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $res = curl_exec($ch);
    if (curl_errno($ch)) return ['error' => 'Curl: ' . curl_error($ch)];
    curl_close($ch);
    
    $json = json_decode($res, true);
    
    // Extract content
    $content = '';
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) { // Gemini
        $content = $json['candidates'][0]['content']['parts'][0]['text'];
    } elseif (isset($json['choices'][0]['message']['content'])) { // OpenAI-like
        $content = $json['choices'][0]['message']['content'];
    } elseif (isset($json['content'][0]['text'])) { // Anthropic
        $content = $json['content'][0]['text'];
    } else {
        return ['error' => 'Unknown response: ' . substr($res, 0, 200)];
    }
    
    return ['content' => $content];
}

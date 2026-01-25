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
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $userPrompt = $input['prompt'] ?? '';
        $provider = $input['provider'] ?? 'openai';
        $context = $input['context'] ?? ''; 

        if (empty($userPrompt)) {
            echo json_encode(['success' => false, 'error' => 'Prompt missing']);
            exit;
        }

        // Fetch Creds
        $stmt = $pdo->prepare("SELECT * FROM connections WHERE type='AI' AND (provider=? OR 1=1) AND is_active=1 ORDER BY (provider=?) DESC LIMIT 1");
        $stmt->execute([$provider, $provider]);
        $conn = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$conn) {
            echo json_encode(['success' => false, 'error' => "No hay ningún servicio de IA configurado y activo en el panel de Conexiones."]);
            exit;
        }

        $provider = $conn['provider'];
        $config = json_decode($conn['config_json'], true);
        $creds = json_decode(Security::decrypt($conn['encrypted_creds']), true);
        $apiKey = $creds['api_key'] ?? '';
        $model = $config['model'] ?? '';
        $baseUrl = $config['base_url'] ?? '';

        set_time_limit(120);
        ini_set('memory_limit', '256M');

        if (strlen($userPrompt) > 10000) $userPrompt = substr($userPrompt, 0, 10000) . "... [Truncado]";
        
        // Build separate Doc and Schema strings
        $userDocs = "";
        $realSchema = "";

        try {
            $conns = $pdo->query("SELECT id, name, type, host, port, database_name, database_schema, username, password_encrypted, ai_context, user_context, ai_conclusions, ai_technical_context FROM db_connections WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($conns as $conn) {
                $cName = $conn['name'];
                $cSchema = $conn['database_schema'] ?? 'public';
                
                // Documentation (Intent)
                $userDocs .= "\n--- DICCIONARIO DE NEGOCIO ($cName) ---\n";
                if(!empty($conn['user_context'])) $userDocs .= "[Manual Usuario]: " . substr($conn['user_context'], 0, 3000) . "\n";
                if(!empty($conn['ai_conclusions'])) $userDocs .= "[Analisis de IA]: " . $conn['ai_conclusions'] . "\n";
                if(!empty($conn['ai_technical_context'])) $userDocs .= "[Guia de Joins]: " . $conn['ai_technical_context'] . "\n";
                $userDocs .= "--------------------------------------\n";

                // Physical Schema (Reality)
                try {
                    $dbPass = Security::decrypt($conn['password_encrypted']);
                    $dsn = ($conn['type'] === 'pgsql')
                        ? "pgsql:host={$conn['host']};port={$conn['port']};dbname={$conn['database_name']}"
                        : "mysql:host={$conn['host']};port={$conn['port']};dbname={$conn['database_name']};charset=utf8mb4";
                    
                    $extPdo = new PDO($dsn, $conn['username'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                    
                    if ($conn['type'] === 'pgsql') {
                         $qTables = "SELECT relname as tname, n_live_tup as rcount FROM pg_stat_user_tables WHERE schemaname = '$cSchema' AND n_live_tup > 2 ORDER BY n_live_tup DESC LIMIT 50";
                         $activeTables = $extPdo->query($qTables)->fetchAll(PDO::FETCH_ASSOC);
                         foreach ($activeTables as $ta) {
                             $t = $ta['tname'];
                             $qCols = "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = '$cSchema' AND table_name = '$t' LIMIT 30";
                             $cols = $extPdo->query($qCols)->fetchAll(PDO::FETCH_ASSOC);
                             $colStr = implode(', ', array_map(fn($c) => "{$c['column_name']} ({$c['data_type']})", $cols));
                             $realSchema .= "- TABLA REAL: $t ({$ta['rcount']} filas) -> Columnas: $colStr\n";
                         }
                    } else {
                         $dbName = $conn['database_name'];
                         $qTables = "SELECT TABLE_NAME as tname, TABLE_ROWS as rcount FROM information_schema.tables WHERE TABLE_SCHEMA = '$dbName' AND TABLE_ROWS > 2 ORDER BY TABLE_ROWS DESC LIMIT 50";
                         $activeTables = $extPdo->query($qTables)->fetchAll(PDO::FETCH_ASSOC);
                         if (empty($activeTables)) {
                            $activeTables = $extPdo->query("SELECT TABLE_NAME as tname, TABLE_ROWS as rcount FROM information_schema.tables WHERE TABLE_SCHEMA = '$dbName' LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
                         }
                         foreach ($activeTables as $ta) {
                             $t = $ta['tname'];
                             try {
                                $cols = $extPdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
                                $colStr = implode(', ', array_map(fn($c) => array_change_key_case($c, CASE_LOWER)['field'] . " (" . array_change_key_case($c, CASE_LOWER)['type'] . ")", array_slice($cols, 0, 30)));
                                $realSchema .= "- TABLA REAL: $t ({$ta['rcount']} filas) -> Columnas: $colStr\n";
                             } catch (Throwable $ignore) {}
                         }
                    }
                } catch (Throwable $ex) { $realSchema .= "- Error conectando a $cName: " . $ex->getMessage() . "\n"; }
            }
        } catch (Throwable $e) { }

        $mode = $input['mode'] ?? 'sql'; 
        if ($mode === 'analysis') {
            $systemPrompt = $config['analysis_prompt'] ?? 'Eres un analista experto.';
        } else {
            $systemPrompt = "Eres un Experto en SQL sobre bases de datos HEREDADAS con nombres técnicos crípticos.
            
            REGLA DE ORO DE NOMENCLATURA:
            La 'DOCUMENTACIÓN DE NEGOCIO' usa nombres humanos (ej: Factura, Cliente), pero el 'ESQUEMA FÍSICO REAL' usa nombres técnicos (ej: mngmcn, vinculado). 
            NUNCA uses nombres de la documentación en el SQL. DEBES mapear el concepto humano al nombre técnico real.
            
            MAPEO DETECTADO (CRÍTICO):
            - Si te piden 'Facturas/Ventas/Facturación', busca tablas como 'mngmcn', 'transacciones' o similares.
            - Si te piden 'Clientes/Terceros', busca tablas como 'vinculado', 'sujetos' o similares.
            
            PASOS PARA GENERAR EL SQL:
            1. Identifica qué tablas de la sección 'ESQUEMA FÍSICO REAL' tienen más registros y columnas compatibles con la solicitud.
            2. Cruza con la 'DOCUMENTACIÓN' para confirmar la lógica (joins, filtros).
            3. Escribe el SQL usando ÚNICAMENTE los nombres de la sección 'ESQUEMA FÍSICO REAL'.";

            $systemPrompt .= "\n\n[[ DOCUMENTACIÓN DE NEGOCIO (Conceptos y Lógica) ]]\n$userDocs";
            $systemPrompt .= "\n\n[[ ESQUEMA FÍSICO REAL (Únicos Nombres Válidos para SQL) ]]\n$realSchema";
            $systemPrompt .= "\n\nRespuesta JSON: {name, description, sql_query, columns_json, parameters_json}";
        }

        if (empty($apiKey)) throw new Exception("API Key faltante.");
        
        $response = callAiApi($provider, $apiKey, $baseUrl, $model, $systemPrompt, $userPrompt);
        
        if (isset($response['error'])) {
             echo json_encode(['success' => false, 'error' => $response['error']]);
        } else {
             $content = $response['content'];
             $jsonContent = null;
             if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                 $jsonContent = json_decode(trim($matches[1]), true);
             } 
             if (!$jsonContent) {
                 $start = strpos($content, '{'); $end = strrpos($content, '}');
                 if ($start !== false && $end !== false) {
                     $jsonContent = json_decode(substr($content, $start, $end - $start + 1), true);
                 }
             }
             if (!$jsonContent) {
                 $jsonContent = ($mode === 'analysis') ? ['description' => $content] : ['raw_text' => $content];
             }
             echo json_encode(['success' => true, 'data' => $jsonContent]);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Fallo Motor IA: ' . $e->getMessage()]);
    }
    exit;
}

function callAiApi($provider, $apiKey, $baseUrl, $model, $sys, $user) {
    $data = [];
    $headers = [];
    $url = "";

    if ($provider === 'gemini') {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        // Reinforce JSON for Gemini via prompt since it doesn't support response_format well in this endpoint
        $fullUser = $user . "\n\nResponde estrictamente en formato JSON válido.";
        $data = ["contents" => [["parts" => [["text" => $sys . "\n" . $fullUser]]]]];
        $headers = ["Content-Type: application/json"];
    } elseif ($provider === 'anthropic') {
        $url = "https://api.anthropic.com/v1/messages";
        $data = [
            "model" => $model,
            "max_tokens" => 4096,
            "system" => $sys,
            "messages" => [["role" => "user", "content" => $user]]
        ];
        $headers = ["x-api-key: $apiKey", "anthropic-version: 2023-06-01", "Content-Type: application/json"];
    } else {
        // OpenAI / DeepSeek / Compatible
        $url = ($baseUrl ?: "https://api.openai.com/v1") . "/chat/completions";
        $data = [
            "model" => $model,
            "messages" => [["role" => "system", "content" => $sys], ["role" => "user", "content" => $user]]
        ];
        // Only set json_object if strictly requested and supported
        if (strpos($sys, 'JSON') !== false && !in_array($provider, ['deepseek-reasoner'])) {
            $data['response_format'] = ['type' => 'json_object'];
        }
        $headers = ["Content-Type: application/json", "Authorization: Bearer $apiKey"];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    
    if ($curlErr) return ['error' => 'CURL Exception: ' . $curlErr];
    
    $json = json_decode($res, true);
    
    if ($httpCode >= 400) {
        $msg = $json['error']['message'] ?? ($json['error'] ?? '');
        if (empty($msg)) $msg = "Respuesta vacía o error de red (HTTP $httpCode). Verifique API Key y cuota.";
        if (is_array($msg)) $msg = json_encode($msg);
        return ['error' => "IA ($provider | HTTP $httpCode): " . substr($msg, 0, 300)];
    }

    $content = '';
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) { 
        $content = $json['candidates'][0]['content']['parts'][0]['text'];
    } elseif (isset($json['choices'][0]['message']['content'])) { 
        $content = $json['choices'][0]['message']['content'];
    } elseif (isset($json['content'][0]['text'])) { 
        $content = $json['content'][0]['text'];
    } else {
        return ['error' => "Error en Estructura IA: No se encontró contenido en el JSON de respuesta. Raw: " . substr($res, 0, 100)];
    }
    
    return ['content' => $content];
}

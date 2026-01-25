<?php
// api/db_connections.php
session_start();
ob_start(); // Buffer output to prevent Warnings modifying JSON
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

// AUTO-MIGRATION: Add multi-context columns
try {
    $pdo->query("SELECT user_context FROM db_connections LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE db_connections 
            ADD COLUMN user_context TEXT DEFAULT NULL AFTER ai_context,
            ADD COLUMN ai_conclusions TEXT DEFAULT NULL AFTER user_context,
            ADD COLUMN ai_technical_context TEXT DEFAULT NULL AFTER ai_conclusions");
    } catch (Exception $ex) { }
}

if ($action === 'list') {
    try {
        $stmt = $pdo->query("SELECT id, name, type, host, port, database_name, database_schema, username, is_active, ai_context, user_context, ai_conclusions, ai_technical_context FROM db_connections ORDER BY name");
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
    $aiContext = $input['ai_context'] ?? '';
    $userContext = $input['user_context'] ?? '';
    $aiConclusions = $input['ai_conclusions'] ?? '';
    $aiTechContext = $input['ai_technical_context'] ?? '';

    if (empty($name) || empty($host) || empty($dbName) || empty($user)) {
        echo json_encode(['success' => false, 'error' => 'Nombre, Host, DB y Usuario son obligatorios.']);
        exit;
    }

    try {
        // Encriptar password si se envió una nueva
        if ($id) {
            if (!empty($pass)) {
                $encPass = Security::encrypt($pass);
                $stmt = $pdo->prepare("UPDATE db_connections SET name=?, type=?, host=?, port=?, database_name=?, database_schema=?, username=?, password_encrypted=?, is_active=?, ai_context=?, user_context=?, ai_conclusions=?, ai_technical_context=? WHERE id=?");
                $stmt->execute([$name, $type, $host, $port, $dbName, $dbSchema, $user, $encPass, $isActive, $aiContext, $userContext, $aiConclusions, $aiTechContext, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE db_connections SET name=?, type=?, host=?, port=?, database_name=?, database_schema=?, username=?, is_active=?, ai_context=?, user_context=?, ai_conclusions=?, ai_technical_context=? WHERE id=?");
                $stmt->execute([$name, $type, $host, $port, $dbName, $dbSchema, $user, $isActive, $aiContext, $userContext, $aiConclusions, $aiTechContext, $id]);
            }
        } else {
            $encPass = Security::encrypt($pass);
            $stmt = $pdo->prepare("INSERT INTO db_connections (name, type, host, port, database_name, database_schema, username, password_encrypted, is_active, ai_context, user_context, ai_conclusions, ai_technical_context) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $host, $port, $dbName, $dbSchema, $user, $encPass, $isActive, $aiContext, $userContext, $aiConclusions, $aiTechContext]);
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
    if ($input['id']) {
        // If testing from list (only ID sent), or if password empty (edit mode), 
        // we might need to fetch data from DB.
        // Specifically for list test, host/user are empty in input.
        
        $shouldFetch = empty($input['host']) || empty($input['username']);
        
        if ($shouldFetch) {
            $stmt = $pdo->prepare("SELECT * FROM db_connections WHERE id = ?");
            $stmt->execute([$input['id']]);
            $conn = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($conn) {
                // Populate variables from DB
                $host = $conn['host'];
                $port = $conn['port'];
                $dbName = $conn['database_name'];
                $user = $conn['username'];
                $type = $conn['type'];
                $dbSchema = $conn['database_schema'];
                $pass = Security::decrypt($conn['password_encrypted']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Conexión no encontrada.']);
                exit;
            }
        } else {
             // ID present (Edit mode?), but Host/User provided in input.
             // We only need to fetch password if not provided.
             if (empty($pass)) {
                 $stmt = $pdo->prepare("SELECT password_encrypted FROM db_connections WHERE id = ?");
                 $stmt->execute([$input['id']]);
                 $pass = Security::decrypt($stmt->fetchColumn());
             }
        }
    }

        if ($type === 'pgsql') {
            if (!in_array('pgsql', PDO::getAvailableDrivers())) {
                throw new Exception("El driver de PostgreSQL (pdo_pgsql) no está instalado o habilitado en el servidor PHP.");
            }
            $dsn = "pgsql:host=$host;port=$port;dbname=$dbName";
        } else {
            $dsn = "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4";
        }

        $testPdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        
        if ($type === 'pgsql' && !empty($dbSchema)) {
             $testPdo->exec("SET search_path TO " . $dbSchema);
        }

        ob_clean();
        echo json_encode(['success' => true, 'message' => '¡Conexión Exitosa!']);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Fallo de conexión: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'analyze_schema_context') {
    checkAdmin();
    set_time_limit(120); // Increase time for large DBs
    ini_set('memory_limit', '256M');

    // Support POST for large user context
    $input = json_decode(file_get_contents('php://input'), true);
    $connectionId = $input['connection_id'] ?? $_GET['connection_id'] ?? null;
    $existingUserContext = $input['user_context'] ?? $_GET['user_context'] ?? '';
    
    if (!$connectionId) { echo json_encode(['success' => false, 'error' => 'No connection ID']); exit; }

    try {
        // 1. Connect to Target DB
        $stmt = $pdo->prepare("SELECT * FROM db_connections WHERE id = ?");
        $stmt->execute([$connectionId]);
        $conn = $stmt->fetch();
        if (!$conn) throw new Exception("Conexión no encontrada");

        $cSchema = $conn['database_schema'] ?? 'public';
        $dbPass = Security::decrypt($conn['password_encrypted']);
        
        if ($conn['type'] === 'pgsql') {
             $dsn = "pgsql:host={$conn['host']};port={$conn['port']};dbname={$conn['database_name']}";
        } else {
             $dsn = "mysql:host={$conn['host']};port={$conn['port']};dbname={$conn['database_name']};charset=utf8mb4";
        }
        
        $targetPdo = new PDO($dsn, $conn['username'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);

        // 2. Extract Schema & Real Stats (Row Counts)
        $tablesAnalysis = [];
        
        if ($conn['type'] === 'pgsql') {
             // Get tables with row counts from pg_stat_user_tables or count(*)
             $q = "SELECT relname as table_name, n_live_tup as row_count 
                   FROM pg_stat_user_tables 
                   WHERE schemaname = '$cSchema' 
                   ORDER BY n_live_tup DESC";
             $rawTables = $targetPdo->query($q)->fetchAll(PDO::FETCH_ASSOC);
        } else {
             // MySQL: Use information_schema for approximate row counts (much faster for huge DBs)
             $dbName = $conn['database_name'];
             $q = "SELECT table_name, table_rows as row_count 
                   FROM information_schema.tables 
                   WHERE table_schema = '$dbName' 
                   ORDER BY table_rows DESC";
             $rawTables = $targetPdo->query($q)->fetchAll(PDO::FETCH_ASSOC);
        }

        // Processing limit: Top 60 tables by size, plus any table mentioned in user docs if detectable
        $tablesList = array_slice($rawTables, 0, 60);

        foreach ($tablesList as $tableInfo) {
            $t = $tableInfo['table_name'];
            $rowCount = $tableInfo['row_count'];

            // Get Columns (limit to 40 per table to save tokens)
            $cols = [];
            try {
                if ($conn['type'] === 'pgsql') {
                     $qCols = "SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = '$cSchema' AND table_name = '$t' LIMIT 40";
                     $rows = $targetPdo->query($qCols)->fetchAll(PDO::FETCH_ASSOC);
                     foreach($rows as $r) $cols[] = $r['column_name'] . "(" . $r['data_type'] . ")";
                } else {
                     $rows = $targetPdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
                     $rows = array_slice($rows, 0, 40);
                     foreach($rows as $r) $cols[] = $r['Field'] . "(" . $r['Type'] . ")";
                }
                
                // Get 1 sample row ONLY if table has data
                $sample = [];
                if ($rowCount > 0) {
                    $qSample = $conn['type'] === 'pgsql' ? "SELECT * FROM \"$t\" LIMIT 1" : "SELECT * FROM `$t` LIMIT 1";
                    $sample = $targetPdo->query($qSample)->fetch(PDO::FETCH_ASSOC);
                    if ($sample) {
                        foreach ($sample as $k => $v) {
                            if (is_string($v) && strlen($v) > 40) $sample[$k] = substr($v, 0, 40) . "...";
                        }
                    }
                }
            } catch (Exception $e) { $cols = ["Error loading cols"]; $sample = []; }

            $tablesAnalysis[] = [
                'name' => $t,
                'rows' => $rowCount,
                'columns' => $cols,
                'sample' => $sample
            ];
        }

        // 3. Find AI Config
        $aiStmt = $pdo->prepare("SELECT * FROM connections WHERE type='AI' AND is_active=1 LIMIT 1");
        $aiStmt->execute();
        $aiConn = $aiStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$aiConn) throw new Exception("No hay servicio de IA activo configurado.");
        
        $aiConfig = json_decode($aiConn['config_json'], true);
        $aiCreds = json_decode(Security::decrypt($aiConn['encrypted_creds']), true);
        $provider = $aiConn['provider'];
        $model = $aiConfig['model'] ?? '';
        $apiKey = $aiCreds['api_key'] ?? '';

        $systemPrompt = "Eres un Arquitecto de Datos Senior y Analista de Negocios. Tu especialidad es realizar ingeniería inversa de bases de datos complejas (ERPs, etc.) cruzando documentación técnica con estadísticas de uso real.";
        $userPrompt = "TAREA:
1. Analiza el JSON de 'DOCUMENTACIÓN SUMINISTRADA' (proporcionado por el usuario, puede ser extenso).
2. Contrasta eso con las 'ESTADÍSTICAS REALES' de la base de datos (tablas existentes y su cantidad de registros).
3. Determina qué módulos y tablas están REALMENTE en funcionamiento (prioriza tablas con row_count > 0).
4. Genera un diccionario optimizado.

DOCUMENTACIÓN SUMINISTRADA (USER DOCS):
$existingUserContext

ESTADÍSTICAS REALES Y ESTRUCTURA (DB ANALYTICS):
" . json_encode($tablesAnalysis, JSON_PRETTY_PRINT) . "

RESPUESTA REQUERIDA (JSON ESTRICTO):
{
  \"ai_conclusions\": \"Resumen ejecutivo para el cliente: ¿Qué sistema es?, ¿Qué módulos están activos?, ¿Dónde están los datos clave (Ventas, Clientes, Inventario)? Ignora módulos documentados que parezcan vacíos/sin uso.\",
  \"ai_technical_context\": \"Diccionario comprimido para otra IA. Incluye: 'T:nombre_tabla=Resumen', 'JOIN:T1.col=T2.col', 'FILTROS:clase=X'. Usa taquigrafía para ahorrar tokens.\"
}";

        // 4. Call AI (Ensure POST and adequate timeout)
        if ($provider === 'gemini') {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=$apiKey";
            $payload = ["contents" => [["parts" => [["text" => $systemPrompt . "\n" . $userPrompt]]]]];
            $headers = ["Content-Type: application/json"];
        } else {
            $url = ($aiConfig['base_url'] ?: "https://api.openai.com/v1") . "/chat/completions";
            $payload = [
                "model" => $model, "messages" => [["role" => "system", "content" => $systemPrompt], ["role" => "user", "content" => $userPrompt]],
                "response_format" => ["type" => "json_object"]
            ];
            $headers = ["Content-Type: application/json", "Authorization: Bearer $apiKey"];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90); // 90 seconds for big analysis

        $res = curl_exec($ch);
        if (curl_errno($ch)) throw new Exception("Error en comunicación con IA: " . curl_error($ch));
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) throw new Exception("Error IA ($httpCode): " . $res);

        $jsonRes = json_decode($res, true);
        $content = ($provider === 'gemini') ? ($jsonRes['candidates'][0]['content']['parts'][0]['text'] ?? '') : ($jsonRes['choices'][0]['message']['content'] ?? '');

        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false) {
             $finalJson = substr($content, $start, $end - $start + 1);
             echo json_encode(['success' => true, 'data' => json_decode($finalJson, true)]);
        } else {
             throw new Exception("La respuesta de la IA no es un JSON válido.");
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);
exit;

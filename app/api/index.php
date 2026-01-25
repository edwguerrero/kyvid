<?php
// api/index.php
if (session_status() === PHP_SESSION_NONE) {
    // Secure Session Settings
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// Security Headers
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("X-XSS-Protection: 1; mode=block");

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/ReportFilterBuilder.php';
require_once __DIR__ . '/../src/Security.php';

// --- AUTH PROTECTION ---
$action = $_GET['action'] ?? '';
$publicActions = ['login', 'check_code', 'login_account']; // Only absolute necessary login actions

if (!isset($_SESSION['user_id']) && !in_array($action, $publicActions)) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Sesión expirada o no válida. Se requiere autenticación.']);
    exit;
}
// -----------------------

// --- AUTO-MIGRATION: Update reports table with is_view and connection_id ---
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        try {
            $pdo->query("SELECT post_action_code FROM reports LIMIT 1");
        } catch (Throwable $e) {
            try {
                $pdo->exec("ALTER TABLE reports ADD COLUMN post_action_code VARCHAR(50) DEFAULT NULL, ADD COLUMN post_action_params JSON DEFAULT NULL");
            } catch (Exception $ex) { /* silent */ }
        }
    }
    
try {
    $pdo->query("SELECT is_view FROM reports LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE reports ADD COLUMN is_view TINYINT(1) DEFAULT 0 AFTER code");
    } catch (Exception $ex) { /* silent */ }
}

try {
    $pdo->query("SELECT acl_view FROM reports LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE reports 
            ADD COLUMN acl_view JSON DEFAULT NULL,
            ADD COLUMN acl_edit JSON DEFAULT NULL,
            ADD COLUMN acl_delete JSON DEFAULT NULL");
    } catch (Exception $ex) { /* silent */ }
}

try {
    $pdo->query("SELECT analysis_prompt FROM ai_configs LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE ai_configs ADD COLUMN analysis_prompt TEXT AFTER system_prompt");
    } catch (Exception $ex) { /* silent */ }
}

try {
    $pdo->query("SELECT id FROM custom_actions LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS custom_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(50) UNIQUE NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            php_content MEDIUMTEXT,
            parameters_schema JSON,
            timeout_sec INT DEFAULT 30,
            is_active TINYINT DEFAULT 1,
            last_modified_by INT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $ex) { /* silent */ }
}


try {
    $pdo->query("SELECT id FROM shared_reports LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS shared_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(100) UNIQUE NOT NULL,
            report_id INT NOT NULL,
            filters_json TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            CONSTRAINT fk_shared_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $ex) { /* silent */ }
}

// -----------------------------------------------------------
try {
    $pdo->query("SELECT value FROM settings LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(50) PRIMARY KEY,
            `value` TEXT NOT NULL
        )");
        // Seed initial password from constant if exists
        $initialPass = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : 'ADMINISTRATOR';
        $stmt = $pdo->prepare("INSERT IGNORE INTO settings (`key`, `value`) VALUES ('admin_password', ?)");
        $stmt->execute([$initialPass]);
    } catch (Exception $ex) { /* silent */ }
}
// -----------------------------------------------------------
try {
    $pdo->query("SELECT id FROM auth_accounts LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(100) UNIQUE NOT NULL,
            name VARCHAR(100) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) DEFAULT 'viewer',
            attributes_json JSON DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            last_login TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        // Seed initial Admin from environment
        $adminCode = 'admin';
        $adminPass = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : 'ADMINISTRATOR';
        $hash = password_hash($adminPass, PASSWORD_BCRYPT);
        
        $seed = $pdo->prepare("INSERT IGNORE INTO auth_accounts (code, name, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $seed->execute([$adminCode, 'Administrador del Sistema', $hash]);
        
    } catch (Throwable $ex) { /* silent */ }
}

// --- ENSURE ADMIN EXISTS (Fix for existing empty tables) ---
try {
    $stmt = $pdo->prepare("SELECT id FROM auth_accounts WHERE code = ?");
    $stmt->execute(['admin']);
    if (!$stmt->fetch()) {
        $adminPass = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : 'ADMINISTRATOR';
        $hash = password_hash($adminPass, PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO auth_accounts (code, name, password_hash, role) VALUES ('admin', 'Administrador del Sistema', ?, 'admin')")->execute([$hash]);
    }
} catch (Exception $e) { /* ignore */ }

// --- AUTO-MIGRATION: action_logs table ---
try {
    $pdo->query("SELECT id FROM action_logs LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS action_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT DEFAULT NULL,
            user_id INT DEFAULT NULL,
            action_code VARCHAR(100) DEFAULT NULL,
            trigger_type ENUM('manual', 'robot', 'system') DEFAULT 'manual',
            status ENUM('success', 'error', 'warning') DEFAULT 'success',
            message TEXT,
            details_json JSON DEFAULT NULL,
            duration_ms INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report (report_id),
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $ex) { /* silent */ }
}
// -----------------------------------------------------------

// --- RECURSIVE VIRTUAL VIEW RESOLUTION ---
function resolveVirtualViews($sql, $pdo, $targetPdo, $dbType = 'mysql', $depth = 0) {
    if ($depth > 5) return $sql; // Prevent infinite loops

    if (preg_match_all('/\b(RPT_[A-Z0-9_]+|TB_[A-Z0-9_]+)\b/i', $sql, $matches)) {
        $foundCodes = array_unique($matches[0]);
        foreach ($foundCodes as $code) {
            // Check if this code exists in our reports table (CASE INSENSITIVE)
            $stmt = $pdo->prepare("SELECT id, sql_query, php_script, is_view FROM reports WHERE UPPER(code) = UPPER(?)");
            $stmt->execute([$code]);
            $rpt = $stmt->fetch();

            if ($rpt) {
                if (empty($rpt['php_script'])) {
                    // Simple SQL Injection
                    $subSql = resolveVirtualViews($rpt['sql_query'], $pdo, $targetPdo, $dbType, $depth + 1);
                    $subSql = rtrim(trim($subSql), ';');
                    $sql = preg_replace('/\b' . preg_quote($code, '/') . '\b/i', "($subSql)", $sql);
                } else {
                    // Complex View: Execute and push
                    try {
                        $subStmt = $pdo->prepare("SELECT sql_query FROM reports WHERE id = ?");
                        $subStmt->execute([$rpt['id']]);
                        $subSqlRaw = $subStmt->fetchColumn();
                        $subData = $pdo->query($subSqlRaw)->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (!empty($rpt['php_script'])) {
                            $results = $subData;
                            eval($rpt['php_script']);
                            $subData = $results;
                        }

                        if (!empty($subData)) {
                            // FALLBACK STRATEGY: Small datasets use Virtual Subqueries (VALUES/UNION) 
                            // to avoid "TEMPORARY TABLE" permission issues.
                            if (count($subData) < 100) {
                                $virtualRows = [];
                                if ($dbType === 'pgsql') {
                                    foreach ($subData as $row) {
                                        $escaped = array_map(function($v) use ($targetPdo) { 
                                            return is_numeric($v) ? $v : $targetPdo->quote((string)$v); 
                                        }, array_values($row));
                                        $virtualRows[] = "(" . implode(", ", $escaped) . ")";
                                    }
                                    $colNames = array_keys($subData[0]);
                                    $subquery = "(SELECT * FROM (VALUES " . implode(", ", $virtualRows) . ") AS t(\"" . implode("\", \"", $colNames) . "\"))";
                                    $sql = preg_replace('/\b' . preg_quote($code, '/') . '\b/i', $subquery, $sql);
                                } else {
                                    // MySQL Fallback (UNION SELECT)
                                    foreach ($subData as $row) {
                                        $escaped = [];
                                        foreach ($row as $k => $v) {
                                            $val = is_numeric($v) ? $v : $targetPdo->quote((string)$v);
                                            $escaped[] = "$val as `$k`";
                                        }
                                        $virtualRows[] = "SELECT " . implode(", ", $escaped);
                                    }
                                    $subquery = "(" . implode(" UNION ALL ", $virtualRows) . ")";
                                    $sql = preg_replace('/\b' . preg_quote($code, '/') . '\b/i', $subquery, $sql);
                                }
                            } else {
                                // Large dataset: Use TEMPORARY TABLE for performance
                                $firstRow = $subData[0];
                                $cols = [];
                                foreach ($firstRow as $colName => $val) {
                                    $type = is_numeric($val) ? "NUMERIC" : "TEXT";
                                    $cols[] = ($dbType === 'pgsql' ? "\"$colName\"" : "`$colName`") . " $type";
                                }
                                
                                $tableName = strtolower($code);
                                $targetPdo->exec("DROP TABLE IF EXISTS " . ($dbType === 'pgsql' ? "\"$tableName\"" : "`$tableName`"));
                                $targetPdo->exec("CREATE TEMPORARY TABLE " . ($dbType === 'pgsql' ? "\"$tableName\"" : "`$tableName`") . " (" . implode(", ", $cols) . ")");
                                
                                $colNames = array_keys($firstRow);
                                $placeholders = array_fill(0, count($colNames), "?");
                                $insertSql = "INSERT INTO " . ($dbType === 'pgsql' ? "\"$tableName\" (\"" . implode("\", \"", $colNames) . "\")" : "`$tableName` (`" . implode("`, `", $colNames) . "`)") . " VALUES (" . implode(", ", $placeholders) . ")";
                                $insStmt = $targetPdo->prepare($insertSql);
                                foreach ($subData as $row) {
                                    $insStmt->execute(array_values($row));
                                }
                            }
                        }
                    } catch (Exception $e) { 
                        // silent fail
                    }
                }
            }
        }
    }
    return $sql;
}
// ------------------------------------------

header('Content-Type: application/json');

function checkAdmin() {
    if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Acceso denegado. Se requiere Modo Administrador.']);
        exit;
    }
}

$action = $_GET['action'] ?? '';


if ($action === 'list') {
    try {
        $isAdmin = isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] === true;
        $userCode = $_SESSION['user_code'] ?? '';
        $userRole = $_SESSION['user_role'] ?? 'viewer';

        if ($isAdmin) {
            $stmt = $pdo->query("SELECT *, (SELECT name FROM db_connections WHERE id = connection_id) as connection_name, 1 as has_phpscript2 FROM reports ORDER BY category, name");
        } else {
            // Include ACL columns for filtering
            $stmt = $pdo->query("SELECT id, code, name, description, category, last_execution_at, is_automatic, is_active, grouping_config, post_action_code, post_action_params, columns_json, parameters_json, acl_view, (CASE WHEN phpscript2 IS NOT NULL AND phpscript2 != '' THEN 1 ELSE 0 END) as has_phpscript2 FROM reports WHERE is_active = 1 ORDER BY category, name");
        }
        
        $all = $stmt->fetchAll();
        
        if (!$isAdmin) {
            $all = array_filter($all, function($r) use ($userCode, $userRole) {
                if (empty($r['acl_view'])) return true; // Public for internal users
                $acl = json_decode($r['acl_view'], true);
                if (!is_array($acl)) return true;
                if (empty($acl)) return true;
                
                if (in_array($userRole, $acl)) return true;
                if (in_array("U:$userCode", $acl)) return true;
                return false;
            });
            $all = array_values($all);
        }

        echo json_encode(['success' => true, 'data' => $all]);
    } catch (Throwable $e) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'execute') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Support DataTables parameters or direct JSON payload
    $reportId = $input['report_id'] ?? null;
    $filters = $input['filters'] ?? [];
    
    // DataTables specific parameters
    $draw = $input['draw'] ?? 1;
    $start = $input['start'] ?? 0;
    $length = $input['length'] ?? -1; // -1 means all
    $searchValue = $input['search']['value'] ?? '';

    if (!$reportId) {
        echo json_encode(['success' => false, 'error' => 'Missing report_id']);
        exit;
    }


    // --- DATABASE CONTEXT SWITCHING ---
    // Check if there is an active external connection
    $targetPdo = $pdo; // Default to local
    $dbType = 'mysql'; // Default type
    try {
        $activeConnStmt = $pdo->query("SELECT * FROM db_connections WHERE is_active = 1 LIMIT 1");
        $activeConn = $activeConnStmt->fetch();
        
        if ($activeConn) {
            $dbHost = $activeConn['host'];
            $dbPort = $activeConn['port'];
            $dbName = $activeConn['database_name'];
            $dbUser = $activeConn['username'];
            $dbPass = Security::decrypt($activeConn['password_encrypted']);
            
            $dbSchema = $activeConn['database_schema'] ?? '';
            $dbType = $activeConn['type'];
            
            if ($dbType === 'pgsql') {
                $dsn = "pgsql:host=$dbHost;port=$dbPort;dbname=$dbName";
            } else {
                $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
            }

            // Connect to external DB
            $targetPdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true // More flexible with params
            ]);

            // Set search_path for pgsql if schema provided
            if ($activeConn['type'] === 'pgsql' && !empty($dbSchema)) {
                $targetPdo->exec("SET search_path TO " . $dbSchema);
            }
        }
    } catch (Throwable $e) {
        // Fallback or Error? 
        echo json_encode(['success' => false, 'error' => 'Error de conexión: ' . $e->getMessage()]);
        exit;
    }
    // ----------------------------------

    try {
        $actionError = null;
        $totalRecords = 0;
        $results = []; // Initialize to prevent undefined issues
        
        // 1. Get Report Definition (ALWAYS from Local Config DB)
        $stmt = $pdo->prepare("SELECT sql_query, php_script, parameters_json, columns_json, post_action_code, acl_view FROM reports WHERE id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();

        if (!$report) {
            echo json_encode(['success' => false, 'error' => 'Report not found']);
            exit;
        }

        // --- ACL VIEW CHECK (Double Protection) ---
        $isAdminCheck = isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] === true;
        if (!$isAdminCheck) { 
            $acl = json_decode($report['acl_view'] ?? '[]', true);
            if (!empty($acl)) {
                $uCode = $_SESSION['user_code'] ?? '';
                $uRole = $_SESSION['user_role'] ?? 'viewer';
                if (!in_array($uRole, $acl) && !in_array("U:$uCode", $acl)) {
                    echo json_encode(['success' => false, 'error' => 'No tienes permiso para ver este reporte, incluso con este enlace.']);
                    exit;
                }
            }
        }

        // --- SECURITY CHECK ---
        Security::validateQuery($report['sql_query']);
        // ----------------------

        // --- NEW: Contextual User Variables Injection ---
        $preparedSql = $report['sql_query'];
        $preparedSql = str_ireplace('{USER.CODE}', $targetPdo->quote($_SESSION['user_code'] ?? ''), $preparedSql);
        $preparedSql = str_ireplace('{USER.NAME}', $targetPdo->quote($_SESSION['user_name'] ?? ''), $preparedSql);
        $preparedSql = str_ireplace('{USER.ROLE}', $targetPdo->quote($_SESSION['user_role'] ?? ''), $preparedSql);
        
        $userAttrs = $_SESSION['user_attr'] ?? [];
        if (is_array($userAttrs)) {
            foreach ($userAttrs as $k => $v) {
                $preparedSql = str_ireplace('{USER.' . strtoupper($k) . '}', $targetPdo->quote((string)$v), $preparedSql);
            }
        }
        // ------------------------------------------------

        // 2. Build Dynamic Filters
        $builder = new ReportFilterBuilder();
        $definedParams = json_decode($report['parameters_json'], true) ?? [];

        foreach ($definedParams as $paramDef) {
            $field = $paramDef['field'];
            $val = $filters[$field] ?? null;

            if ($val !== null) {
                // Determine operator
                $operator = $paramDef['operator'] ?? '=';
                if ($paramDef['type'] === 'date_range') {
                    $operator = 'BETWEEN';
                }
                $builder->addFilter($field, $operator, $val);
            }
        }

        // 3. Inject Filters
        // --- PRE-PROCESS VIRTUAL VIEWS (Nesting) ---
        $baseSql = resolveVirtualViews($preparedSql, $pdo, $targetPdo, $dbType);
        $baseSql = rtrim(trim($baseSql), ';');
        // -------------------------------------------
        $hasWhere = stripos($baseSql, 'WHERE') !== false;
        $whereClause = $builder->buildWhereClause($hasWhere);
        
        // --- Smart Injection ---
        $groupByPos = stripos($baseSql, 'GROUP BY');
        $orderByPos = stripos($baseSql, 'ORDER BY');
        
        $insertionPoint = strlen($baseSql);
        if ($groupByPos !== false) $insertionPoint = $groupByPos;
        elseif ($orderByPos !== false) $insertionPoint = $orderByPos;
        
        $sqlWithFilters = $baseSql;
        if (!empty($whereClause)) {
             $sqlWithFilters = substr($baseSql, 0, $insertionPoint) . $whereClause . " " . substr($baseSql, $insertionPoint);
        }

        // 4. Global Search (DataTables) - Simple iteration over columns if possible
        // For MVP, we skip complex global search generation on raw SQL.
        
        // 5. Count Total & Filtered
        // We wrap the query to count safely: SELECT COUNT(*) FROM ( ... ) as T
        $countSql = "SELECT COUNT(*) as total FROM ($sqlWithFilters) as agg";
        $countStmt = $targetPdo->prepare($countSql);
        $countStmt->execute($builder->getParameters());
        $totalRecords = $countStmt->fetchColumn();

        // 6. Sorting (DataTables)
        $orderSql = "";
        if (!empty($input['order']) && !empty($input['columns'])) {
            $orderParts = [];
            foreach ($input['order'] as $o) {
                $colIdx = $o['column'];
                $dir = strtoupper($o['dir']) === 'DESC' ? 'DESC' : 'ASC';
                $colName = $input['columns'][$colIdx]['data'] ?? null;
                
                // Security: Only allow alphanumeric, underscores, and spaces (for aliases)
                // and wrap in backticks to prevent injection.
                if ($colName && preg_match('/^[a-zA-Z0-9_ ]+$/', $colName)) {
                    $quote = ($dbType === 'pgsql') ? '"' : '`';
                    $orderParts[] = "$quote$colName$quote $dir";
                }
            }
            if (!empty($orderParts)) {
                $orderSql = " ORDER BY " . implode(', ', $orderParts);
            }
        }

        // 7. Execute Data Query with Sorting & Pagination
        // We wrap the filtered query so we can apply ORDER BY and LIMIT reliably
        $sqlFinal = "SELECT * FROM ($sqlWithFilters) as results_wrapped" . $orderSql;
        
        $sqlPaginated = $sqlFinal;
        if ($length > 0) {
            $sqlPaginated .= " LIMIT " . (int)$length . " OFFSET " . (int)$start;
        }

        $needsManualSortPaginate = false;
        try {
            // Attempt to execute with SQL-level sorting and pagination
            $query = $targetPdo->prepare($sqlPaginated);
            $query->execute($builder->getParameters());
            $results = $query->fetchAll();
        } catch (Throwable $e) {
            // FALLBACK: If SQL sorting fails, fetch ALL rows.
            $queryFallback = $targetPdo->prepare("SELECT * FROM ($sqlWithFilters) as results_wrapped");
            $queryFallback->execute($builder->getParameters());
            $results = $queryFallback->fetchAll();
            $needsManualSortPaginate = true;
        }

        // 8. Post-Processing PHP Script (Pre-render / Enrichment)
        if (!empty($report['php_script'])) {
            try {
                $script = $report['php_script'];
                
                // Security: Basic Blacklist (Dangerous PHP Functions)
                $blacklistRegEx = [
                    '/\b(system|shell_exec|passthru|proc_open|popen|pcntl_exec)\s*\(/i',
                    '/(?<!->)\bexec\s*\(/i', 
                    '/\b(unlink|rmdir|mkdir|chmod|rename|copy|file_put_contents|fwrite|fputs)\s*\(/i',
                    '/\b(include|require|include_once|require_once)\s*[\(\'\"]/i',
                    '/\bnew\s+(PDO|mysqli)\b/i'
                ];
                
                foreach ($blacklistRegEx as $pattern) {
                    if (preg_match($pattern, $script)) {
                        throw new Exception("Security Violation: Usage of forbidden function or construct detected.");
                    }
                }

                $executor = function(&$results) use ($script) {
                    eval($script);
                };
                $executor($results);

            } catch (Throwable $e) {
                 $actionError = 'Script Error: ' . $e->getMessage();
            }
        }

        // 9. Post-Action execution REMOVED from preview to prevent side-effects.
        // Use php_script for data enrichment needs during preview.

        // DataTables expects specific names
        if ($needsManualSortPaginate && !empty($input['order'])) {
            // PHP Sorting
            usort($results, function($a, $b) use ($input) {
                foreach ($input['order'] as $o) {
                    $colIdx = $o['column'];
                    $dir = strtoupper($o['dir']) === 'DESC' ? -1 : 1;
                    $colName = $input['columns'][$colIdx]['data'] ?? null;
                    
                    if ($colName && isset($a[$colName]) && isset($b[$colName])) {
                        if ($a[$colName] == $b[$colName]) continue;
                        return ($a[$colName] < $b[$colName] ? -1 : 1) * $dir;
                    }
                }
                return 0;
            });
            
            // PHP Pagination
            if ($length > 0) {
                $results = array_slice($results, (int)$start, (int)$length);
            }
        }

        // DataTables expects specific names
        $json = json_encode([
            'success' => true,
            'draw' => (int)$draw,
            'recordsTotal' => (int)$totalRecords,
            'recordsFiltered' => (int)$totalRecords, 
            'data' => $results,
            'columns' => json_decode($report['columns_json']),
            'action_error' => $actionError
        ]);
        if ($json === false) throw new Exception("Error codificando JSON: " . json_last_error_msg());
        echo $json;

    } catch (Throwable $e) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}



if ($action === 'save') {
    checkAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = $input['id'] ?? null;
    $code = $input['code'] ?? '';
    // Fix: Add category handling
    $category = $input['category'] ?? 'General'; 
    $name = $input['name'] ?? '';
    $description = $input['description'] ?? '';
    $sql_query = $input['sql_query'] ?? '';
    $php_script = $input['php_script'] ?? '';
    $columns_json = $input['columns_json'] ?? '[]';
    $parameters_json = $input['parameters_json'] ?? '[]';
    $grouping_config = $input['grouping_config'] ?? null;
    $phpscript2 = $input['phpscript2'] ?? null;
    $post_action_code = $input['post_action_code'] ?? null;
    $post_action_params = $input['post_action_params'] ?? null;
    if (trim((string)$post_action_params) === '') $post_action_params = null;
    
    $is_view = isset($input['is_view']) ? (int)$input['is_view'] : 0;
    $is_active = isset($input['is_active']) ? (int)$input['is_active'] : 1; 
    
    // Automation Fields
    $is_automatic = isset($input['is_automatic']) ? (int)$input['is_automatic'] : 0;
    $cron_interval_minutes = isset($input['cron_interval_minutes']) ? (int)$input['cron_interval_minutes'] : 60;
    $acl_view = $input['acl_view'] ?? null;
    $print_header = $input['print_header'] ?? null;
    $print_footer = $input['print_footer'] ?? null;

    // Default category if missing
    if (empty($category)) $category = 'General';

    if (empty($code) || empty($name) || empty($sql_query)) {
        $missing = [];
        if (empty($code)) $missing[] = 'Código';
        if (empty($name)) $missing[] = 'Nombre';
        if (empty($sql_query)) $missing[] = 'Consulta SQL';
        
        echo json_encode(['success' => false, 'error' => 'Por favor, complete los campos obligatorios: ' . implode(', ', $missing)]);
        exit;
    }

    // Determine Active Connection ID
    $connectionId = null;
    try {
        $activeConnStmt = $pdo->query("SELECT id FROM db_connections WHERE is_active = 1 LIMIT 1");
        $activeConn = $activeConnStmt->fetch();
        if ($activeConn) $connectionId = $activeConn['id'];
    } catch (Exception $e) { /* ignore */ }

    try {
        if ($id) {
            // Update
            $stmt = $pdo->prepare("UPDATE reports SET code=?, category=?, name=?, description=?, sql_query=?, php_script=?, columns_json=?, parameters_json=?, grouping_config=?, phpscript2=?, post_action_code=?, post_action_params=?, is_automatic=?, cron_interval_minutes=?, connection_id=?, is_view=?, is_active=?, acl_view=?, print_header=?, print_footer=? WHERE id=?");
            $stmt->execute([$code, $category, $name, $description, $sql_query, $php_script, $columns_json, $parameters_json, $grouping_config, $phpscript2, $post_action_code, $post_action_params, $is_automatic, $cron_interval_minutes, $connectionId, $is_view, $is_active, $acl_view, $print_header, $print_footer, $id]);
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO reports (code, category, name, description, sql_query, php_script, columns_json, parameters_json, grouping_config, phpscript2, post_action_code, post_action_params, is_automatic, cron_interval_minutes, connection_id, is_view, is_active, acl_view, print_header, print_footer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$code, $category, $name, $description, $sql_query, $php_script, $columns_json, $parameters_json, $grouping_config, $phpscript2, $post_action_code, $post_action_params, $is_automatic, $cron_interval_minutes, $connectionId, $is_view, $is_active, $acl_view, $print_header, $print_footer]);
        }
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    checkAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM reports WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'share_save') {
    $input = json_decode(file_get_contents('php://input'), true);
    $reportId = $input['report_id'] ?? null;
    $filters = $input['filters'] ?? [];
    
    if (!$reportId) {
        echo json_encode(['success' => false, 'error' => 'Missing report_id']);
        exit;
    }

    try {
        // Generate a simple token
        $token = bin2hex(random_bytes(16));
        $filters_json = json_encode($filters);
        
        $stmt = $pdo->prepare("INSERT INTO shared_reports (token, report_id, filters_json) VALUES (?, ?, ?)");
        $stmt->execute([$token, $reportId, $filters_json]);
        
        echo json_encode(['success' => true, 'token' => $token]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_schema') {
    checkAdmin();
    // No specific connection_id needed, we fetch ALL.

    $allTables = [];

    // Helper function to fetch schema from a PDO instance
    $fetchSchema = function ($pdoInstance, $dbType, $prefix, $schemaFilter = 'public', $isLocal = false) {
        $found = [];
        try {
            if ($dbType === 'pgsql') {
                $schema = $schemaFilter ?: 'public';
                $query = "SELECT table_name, column_name, data_type 
                          FROM information_schema.columns 
                          WHERE table_schema = '$schema' 
                          ORDER BY table_name, ordinal_position";
                $rows = $pdoInstance->query($query)->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $tNameRaw = $row['table_name'];
                    
                    // Local Filter Rule
                    if ($isLocal) {
                        // Allow tb_*, tp_*, and reports.
                        // Regex: ^(tb_|tp_|reports$)
                        if (!preg_match('/^(tb_|tp_|reports$)/i', $tNameRaw)) {
                            continue;
                        }
                    }

                    $tNameDisplay = $prefix ? "$prefix $tNameRaw" : $tNameRaw;
                    
                    if (!isset($found[$tNameDisplay])) {
                        $found[$tNameDisplay] = ['name' => $tNameDisplay, 'original_name' => $tNameRaw, 'columns' => []];
                    }
                    $found[$tNameDisplay]['columns'][] = [
                        'name' => $row['column_name'],
                        'type' => $row['data_type']
                    ];
                }
            } else {
                // MySQL
                $stmt = $pdoInstance->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $tName) {
                    // Local Filter Rule
                    if ($isLocal) {
                        if (!preg_match('/^(tb_|tp_|reports$)/i', $tName)) {
                            continue;
                        }
                    }

                    $tNameDisplay = $prefix ? "$prefix $tName" : $tName;
                    try {
                        $cStmt = $pdoInstance->query("SHOW COLUMNS FROM `$tName`");
                        $cols = $cStmt->fetchAll(PDO::FETCH_ASSOC);
                        $colData = [];
                        foreach ($cols as $col) {
                            $colData[] = ['name' => $col['Field'], 'type' => $col['Type']];
                        }
                        $found[$tNameDisplay] = ['name' => $tNameDisplay, 'original_name' => $tName, 'columns' => $colData];
                    } catch (Exception $e) { }
                }
            }
        } catch (Exception $ex) { 
            // Silent fail
        }
        return array_values($found);
    };

    try {
        // 1. Local Schema
        // Local usually MySQL. Pass isLocal = true
        $all = $fetchSchema($pdo, 'mysql', '[Local]', 'public', true);
        
        // 2. Fetch Active Connections
        $stmt = $pdo->query("SELECT * FROM db_connections WHERE is_active = 1");
        $conns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($conns as $conn) {
            $cName = $conn['name'];
            $cType = $conn['type'];
            $cSchema = $conn['database_schema'] ?? 'public'; // Custom Schema
            
            // Connect
            try {
                $dbPass = Security::decrypt($conn['password_encrypted']);
                $dsn = $cType === 'pgsql' 
                    ? "pgsql:host={$conn['host']};port={$conn['port']};dbname={$conn['database_name']}"
                    : "mysql:host={$conn['host']};port={$conn['port']};dbname={$conn['database_name']};charset=utf8mb4";
                
                $extPdo = new PDO($dsn, $conn['username'], $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                
                // Fetch and Merge
                // isLocal = false
                $extTables = $fetchSchema($extPdo, $cType, "[$cName]", $cSchema, false);
                $all = array_merge($all, $extTables);
                
            } catch (Exception $ex) {
            }
        }
        
        echo json_encode(['success' => true, 'tables' => $all]);

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'share_get') {
    $token = $_GET['token'] ?? '';
    if (empty($token)) {
        echo json_encode(['success' => false, 'error' => 'Missing token']);
        exit;
    }

    try {
        // Fetch FULL report details (r.*) plus the frozen filters (s.filters_json)
        $stmt = $pdo->prepare("SELECT r.*, s.filters_json as shared_filters, s.token FROM shared_reports s JOIN reports r ON s.report_id = r.id WHERE s.token = ? AND s.is_active = 1");
        $stmt->execute([$token]);
        $shared = $stmt->fetch();

        if (!$shared) {
            echo json_encode(['success' => false, 'error' => 'Link no válido o expirado']);
            exit;
        }

        echo json_encode(['success' => true, 'data' => $shared]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'logs_list') {
    checkAdmin();
    try {
        $stmt = $pdo->query("
            SELECT l.*, r.name as report_name, a.name as user_name 
            FROM action_logs l
            LEFT JOIN reports r ON l.report_id = r.id
            LEFT JOIN auth_accounts a ON l.user_id = a.id
            ORDER BY l.created_at DESC 
            LIMIT 500
        ");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'process') {
    $startTime = microtime(true);
    $input = json_decode(file_get_contents('php://input'), true);
    $reportId = $input['report_id'] ?? null;
    $filters = $input['filters'] ?? [];

    if (!$reportId) {
        echo json_encode(['success' => false, 'error' => 'Missing report_id']);
        exit;
    }

    try {
        // 1. Get Report Definition
        $stmt = $pdo->prepare("SELECT sql_query, phpscript2, post_action_code, post_action_params, parameters_json, columns_json FROM reports WHERE id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch();

        if (!$report) {
            echo json_encode(['success' => false, 'error' => 'Report not found']);
            exit;
        }

        // Must have at least one processing method
        if (empty($report['phpscript2']) && empty($report['post_action_code'])) {
            echo json_encode(['success' => false, 'error' => 'No processing action configured for this report']);
            exit;
        }

        // --- SECURITY CHECK ---
        Security::validateQuery($report['sql_query']);
        // ----------------------

        // 2. Build Dynamic Filters
        $builder = new ReportFilterBuilder();
        $definedParams = json_decode($report['parameters_json'], true) ?? [];

        foreach ($definedParams as $paramDef) {
            $field = $paramDef['field'];
            $val = $filters[$field] ?? null;

            if ($val !== null) {
                $operator = $paramDef['operator'] ?? '=';
                if ($paramDef['type'] === 'date_range') {
                    $operator = 'BETWEEN';
                }
                $builder->addFilter($field, $operator, $val);
            }
        }

        // 3. Inject Filters
        $baseSql = rtrim(trim($report['sql_query']), ';');
        $hasWhere = stripos($baseSql, 'WHERE') !== false;
        $whereClause = $builder->buildWhereClause($hasWhere);
        
        $groupByPos = stripos($baseSql, 'GROUP BY');
        $orderByPos = stripos($baseSql, 'ORDER BY');
        
        $insertionPoint = strlen($baseSql);
        if ($groupByPos !== false) $insertionPoint = $groupByPos;
        elseif ($orderByPos !== false) $insertionPoint = $orderByPos;
        
        $sqlWithFilters = $baseSql;
        if (!empty($whereClause)) {
             $sqlWithFilters = substr($baseSql, 0, $insertionPoint) . $whereClause . " " . substr($baseSql, $insertionPoint);
        }

        // 4. Execute Data Query (or use manually edited data)
        $results = $input['data'] ?? null;
        if ($results === null) {
            $query = $pdo->prepare($sqlWithFilters);
            $query->execute($builder->getParameters());
            $results = $query->fetchAll();
        }

        // 5. Execute Post-Processing Script (Legacy phpscript2)
        $message = '';
        if (!empty($report['phpscript2'])) {
            try {
                $script = $report['phpscript2'];
                $blacklistRegEx = [
                    '/\b(system|shell_exec|passthru|proc_open|popen|pcntl_exec)\s*\(/i',
                    '/(?<!->)\bexec\s*\(/i', 
                    '/\b(unlink|rmdir|mkdir|chmod|rename|copy|file_put_contents|fwrite|fputs)\s*\(/i',
                    '/\b(include|require|include_once|require_once)\s*[\(\'\"]/i'
                ];
                foreach ($blacklistRegEx as $pattern) {
                    if (preg_match($pattern, $script)) throw new Exception("Security Violation in phpscript2.");
                }
                $executor = function(&$results, $pdo, &$message) use ($script) { eval($script); };
                $executor($results, $pdo, $message);
            } catch (Throwable $t) {
                $message .= " | Script Error: " . $t->getMessage();
            }
        }

        // 6. Execute Secure FaaS Post-Action
        if (!empty($report['post_action_code'])) {
            try {
                require_once __DIR__ . '/../src/ActionExecutor.php';
                $executor = new \App\Services\ActionExecutor($pdo);
                $actionParams = json_decode($report['post_action_params'] ?? '{}', true) ?: [];
                $actionResult = $executor->run($report['post_action_code'], $results, $actionParams);
                
                if (!$actionResult['success']) {
                    $message .= " | Acción Error: " . $actionResult['error'];
                } else {
                     if (is_string($actionResult['output'])) $message .= " | " . $actionResult['output'];
                     else $message .= " | Acción ejecutada correctamente.";
                }
            } catch (Throwable $ae) {
                $message .= " | Executor Error: " . $ae->getMessage();
            }
        }

        $isFunctionalSuccess = true;
        if (strpos($message, 'Acción Error:') !== false || strpos($message, 'Executor Error:') !== false || strpos($message, 'Script Error:') !== false) {
            $isFunctionalSuccess = false;
        }

        // LOG ACTION
        $duration = (int)((microtime(true) - $startTime) * 1000);
        try {
            $logStmt = $pdo->prepare("INSERT INTO action_logs (report_id, user_id, action_code, trigger_type, status, message, duration_ms) VALUES (?, ?, ?, 'manual', ?, ?, ?)");
            $logStmt->execute([
                $reportId, 
                $_SESSION['user_id'] ?? null, 
                $report['post_action_code'] ?? 'LEGACY_PHP', 
                $isFunctionalSuccess ? 'success' : 'error',
                trim($message, " | ") ?: 'Procesamiento completado',
                $duration
            ]);
        } catch(Throwable $le) { /* ignore log error */ }
        
        $json = json_encode([
            'success' => $isFunctionalSuccess,
            'message' => trim($message, " | ") ?: 'Procesamiento completado exitosamente'
        ]);
        if ($json === false) throw new Exception("Error codificando JSON: " . json_last_error_msg());
        echo $json;

    } catch (Throwable $e) {
        // LOG CATASTROPHIC ERROR
        try {
            $duration = (int)((microtime(true) - ($startTime ?? microtime(true))) * 1000);
            $logStmt = $pdo->prepare("INSERT INTO action_logs (report_id, user_id, trigger_type, status, message, duration_ms) VALUES (?, ?, 'manual', 'error', ?, ?)");
            $logStmt->execute([$reportId ?? null, $_SESSION['user_id'] ?? null, $e->getMessage(), $duration]);
        } catch(Throwable $le) { /* ignore log error */ }

        if (!headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);

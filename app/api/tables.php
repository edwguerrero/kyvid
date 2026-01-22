<?php
// api/tables.php
require_once '../config/db.php';
require_once '../src/Security.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

// All custom table management is admin-only
Security::checkAdmin();

/**
 * UTILS
 */
function validateTableName($name) {
    // Only alphanumeric and underscores. Must start with tb_
    if (!preg_match('/^tb_[a-zA-Z0-9_]+$/', $name)) {
        throw new Exception("Nombre de tabla inválido. Debe iniciar con 'tb_' y solo contener letras, números y guiones bajos.");
    }
}

function getColumnDefinition($col) {
    // col = { name: 'xxx', type: 'string|number|boolean|datetime' }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $col['name'])) {
        throw new Exception("Nombre de columna inválido: " . $col['name']);
    }
    
    switch ($col['type']) {
        case 'number': return "`{$col['name']}` DECIMAL(20,4) DEFAULT 0";
        case 'boolean': return "`{$col['name']}` TINYINT(1) DEFAULT 0";
        case 'datetime': return "`{$col['name']}` DATETIME NULL";
        case 'string': 
        default: return "`{$col['name']}` VARCHAR(255) NULL";
    }
}

try {
    
    // 1. LIST TABLES
    if ($action === 'list') {
        // Query INFORMATION_SCHEMA to find tables starting with tb_
        $stmt = $pdo->prepare("SELECT table_name, create_time FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'tb_%'");
        $stmt->execute();
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $tables]);
    }

    // 2. CREATE TABLE
    elseif ($action === 'create_table') {
        $name = $input['name'] ?? '';
        $columns = $input['columns'] ?? []; // Array of {name, type}

        validateTableName($name);
        
        if (empty($columns)) throw new Exception("Se requiere al menos una columna.");

        $sql = "CREATE TABLE `$name` (";
        $sql .= "`id` INT AUTO_INCREMENT PRIMARY KEY, ";
        
        foreach ($columns as $col) {
            $sql .= getColumnDefinition($col) . ", ";
        }

        $sql .= "`created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, ";
        $sql .= "`updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $pdo->exec($sql);
        echo json_encode(['success' => true, 'message' => "Tabla $name creada."]);
    }

    // 3. DELETE TABLE
    elseif ($action === 'delete_table') {
        $name = $input['name'] ?? '';
        validateTableName($name);
        $pdo->exec("DROP TABLE `$name`");
        echo json_encode(['success' => true]);
    }

    // 4. GET SCHEMA (Columns)
    elseif ($action === 'get_schema') {
        $name = $_GET['name'] ?? '';
        validateTableName($name);
        $stmt = $pdo->prepare("DESCRIBE `$name`");
        $stmt->execute();
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $cols]);
    }

    // 5. GET DATA (Rows)
    elseif ($action === 'get_data') {
        $name = $input['name'] ?? '';
        validateTableName($name);
        
        // Simple pagination
        $limit = 1000; // Limit for performance safety
        // In full impl, add page/limit params
        
        $stmt = $pdo->prepare("SELECT * FROM `$name` ORDER BY id DESC LIMIT $limit");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
    }

    // 6. SAVE ROW (Insert/Update)
    elseif ($action === 'save_row') {
        $tableName = $input['table'] ?? '';
        $row = $input['row'] ?? [];
        
        validateTableName($tableName);
        
        // Filter out system columns from input if present (except ID)
        $id = $row['id'] ?? null;
        unset($row['id'], $row['created_at'], $row['updated_at']);
        
        if (empty($row)) throw new Exception("Datos vacíos.");

        $cols = array_keys($row);
        $placeholders = array_map(function($c) { return ":$c"; }, $cols);

        if ($id) {
            // UPDATE
            $setClause = implode(', ', array_map(function($c) { return "`$c` = :$c"; }, $cols));
            $sql = "UPDATE `$tableName` SET $setClause WHERE id = :_sys_id";
            $row['_sys_id'] = $id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($row);
        } else {
            // INSERT
            $colNames = implode(', ', array_map(function($c) { return "`$c`"; }, $cols));
            $valPlaceholders = implode(', ', $placeholders);
            $sql = "INSERT INTO `$tableName` ($colNames) VALUES ($valPlaceholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($row);
        }
        
        echo json_encode(['success' => true]);
    }

    // 7. DELETE ROW
    elseif ($action === 'delete_row') {
        $name = $input['table'] ?? '';
        $id = $input['id'] ?? null;
        validateTableName($name);
        
        if (!$id) throw new Exception("ID Requerido");
        
        $stmt = $pdo->prepare("DELETE FROM `$name` WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    }
    
    // 8. ADD COLUMN (Alter Table)
    elseif ($action === 'add_column') {
        $tableName = $input['table'] ?? '';
        $colDef = $input['column'] ?? [];
        
        validateTableName($tableName);
        $sqlDef = getColumnDefinition($colDef);
        
        $pdo->exec("ALTER TABLE `$tableName` ADD COLUMN $sqlDef");
        echo json_encode(['success' => true]);
    }
    
    // 9. IMPORT DATA (Bulk)
    elseif ($action === 'import_data') {
        $tableName = $input['table'] ?? '';
        $rows = $input['rows'] ?? [];
        
        validateTableName($tableName);
        
        if (empty($rows)) throw new Exception("No hay datos para importar.");
        
        $pdo->beginTransaction();
        try {
            // Get columns from first row
            $first = $rows[0];
            $cols = array_keys($first);
            // Sanitize cols
             foreach($cols as $c) {
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $c)) throw new Exception("Columna inválida en importación: $c");
             }
             
            $colNames = implode(', ', array_map(function($c) { return "`$c`"; }, $cols));
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            
            $stmt = $pdo->prepare("INSERT INTO `$tableName` ($colNames) VALUES ($placeholders)");
            
            foreach ($rows as $r) {
                // Ensure values match column order
                $vals = [];
                foreach($cols as $c) $vals[] = $r[$c] ?? null;
                $stmt->execute($vals);
            }
            
            $pdo->commit();
            echo json_encode(['success' => true, 'count' => count($rows)]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

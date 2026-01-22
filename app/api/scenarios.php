<?php
// api/scenarios.php
require_once '../config/db.php';
require_once '../src/Security.php';

header('Content-Type: application/json');

// --- AUTO-MIGRATION: shared_scenarios table ---
try {
    $pdo->query("SELECT id FROM shared_scenarios LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS shared_scenarios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(100) UNIQUE NOT NULL,
            scenario_id INT NOT NULL,
            filters_json TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_active TINYINT(1) DEFAULT 1,
            CONSTRAINT fk_shared_scenario FOREIGN KEY (scenario_id) REFERENCES scenarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Throwable $ex) { /* silent */ }
}

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    try {
        $stmt = $pdo->query("SELECT * FROM scenarios WHERE is_active = 1");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing ID']);
        exit;
    }

    try {
        // Get Scenario
        $stmt = $pdo->prepare("SELECT * FROM scenarios WHERE id = ?");
        $stmt->execute([$id]);
        $scenario = $stmt->fetch();

        if (!$scenario) {
            echo json_encode(['success' => false, 'error' => 'Scenario not found']);
            exit;
        }

        // Get Widgets with report basic info
        $stmt = $pdo->prepare("
            SELECT sw.*, r.name as report_name, r.code as report_code, r.parameters_json 
            FROM scenario_widgets sw 
            JOIN reports r ON sw.report_id = r.id 
            WHERE sw.scenario_id = ? 
            ORDER BY sw.order_index ASC
        ");
        $stmt->execute([$id]);
        $scenario['widgets'] = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $scenario]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;
    $name = $input['name'] ?? '';
    $description = $input['description'] ?? '';
    $category = $input['category'] ?? 'General';

    if (empty($name)) {
        echo json_encode(['success' => false, 'error' => 'Name is required']);
        exit;
    }

    try {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE scenarios SET name=?, description=?, category=? WHERE id=?");
            $stmt->execute([$name, $description, $category, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO scenarios (name, description, category) VALUES (?, ?, ?)");
            $stmt->execute([$name, $description, $category]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_widget') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;
    $scenario_id = $input['scenario_id'] ?? null;
    $report_id = $input['report_id'] ?? null;
    $display_type = $input['display_type'] ?? 'table';
    $grid_layout = $input['grid_layout'] ?? '{}';

    if (!$scenario_id || !$report_id) {
        echo json_encode(['success' => false, 'error' => 'Scenario ID and Report ID are required']);
        exit;
    }

    try {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE scenario_widgets SET display_type=?, grid_layout=? WHERE id=?");
            $stmt->execute([$display_type, $grid_layout, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO scenario_widgets (scenario_id, report_id, display_type, grid_layout) VALUES (?, ?, ?, ?)");
            $stmt->execute([$scenario_id, $report_id, $display_type, $grid_layout]);
            $id = $pdo->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'update_layouts') {
    $input = json_decode(file_get_contents('php://input'), true);
    $widgets = $input['widgets'] ?? [];

    try {
        $pdo->beginTransaction();
        foreach ($widgets as $w) {
            $stmt = $pdo->prepare("UPDATE scenario_widgets SET grid_layout = ? WHERE id = ?");
            $stmt->execute([json_encode($w['layout']), $w['id']]);
        }
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_widget') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM scenario_widgets WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete_scenario') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'Missing ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM scenarios WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'share_save') {
    $input = json_decode(file_get_contents('php://input'), true);
    $scenarioId = $input['scenario_id'] ?? null;
    $filters = $input['filters'] ?? [];
    
    if (!$scenarioId) {
        echo json_encode(['success' => false, 'error' => 'Missing scenario_id']);
        exit;
    }

    try {
        $token = bin2hex(random_bytes(16));
        $filters_json = json_encode($filters);
        
        $stmt = $pdo->prepare("INSERT INTO shared_scenarios (token, scenario_id, filters_json) VALUES (?, ?, ?)");
        $stmt->execute([$token, $scenarioId, $filters_json]);
        
        echo json_encode(['success' => true, 'token' => $token]);
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
        $stmt = $pdo->prepare("SELECT s.*, ss.filters_json as shared_filters FROM shared_scenarios ss JOIN scenarios s ON ss.scenario_id = s.id WHERE ss.token = ? AND ss.is_active = 1");
        $stmt->execute([$token]);
        $shared = $stmt->fetch();

        if (!$shared) {
            echo json_encode(['success' => false, 'error' => 'Link no válido o expirado']);
            exit;
        }

        // Get Widgets
        $stmt = $pdo->prepare("
            SELECT sw.*, r.name as report_name, r.code as report_code, r.parameters_json 
            FROM scenario_widgets sw 
            JOIN reports r ON sw.report_id = r.id 
            WHERE sw.scenario_id = ? 
            ORDER BY sw.order_index ASC
        ");
        $stmt->execute([$shared['id']]);
        $shared['widgets'] = $stmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $shared]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid action']);

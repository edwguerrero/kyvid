<?php
// api/actions.php

require_once '../config/db.php';
require_once '../src/Security.php';
require_once '../src/ActionExecutor.php';

use App\Security;
use App\Services\ActionExecutor;

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

if ($action === 'list') {
    try {
        $stmt = $pdo->query("SELECT id, code, name, description, category, is_active, updated_at, parameters_schema FROM custom_actions ORDER BY category, name");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get') {
    $id = $_GET['id'] ?? 0;
    try {
        $stmt = $pdo->prepare("SELECT * FROM custom_actions WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

Security::checkAdmin();

if ($action === 'save') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? null;
    $code = strtoupper($input['code'] ?? '');
    $name = $input['name'] ?? '';
    $desc = $input['description'] ?? '';
    $php = $input['php_content'] ?? '';
    $cat = $input['category'] ?? 'General';
    $schema = $input['parameters_schema'] ?? '{}';
    $active = $input['is_active'] ?? 1;

    try {
        if ($id) {
            $sql = "UPDATE custom_actions SET code=?, name=?, description=?, php_content=?, category=?, parameters_schema=?, is_active=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$code, $name, $desc, $php, $cat, $schema, $active, $id]);
        } else {
            $sql = "INSERT INTO custom_actions (code, name, description, php_content, category, parameters_schema, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$code, $name, $desc, $php, $cat, $schema, $active]);
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'delete') {
    $id = $_GET['id'] ?? 0;
    try {
        $stmt = $pdo->prepare("DELETE FROM custom_actions WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'test') {
    $input = json_decode(file_get_contents('php://input'), true);
    $code = $input['code'] ?? '';
    $testData = $input['test_data'] ?? []; // Array de prueba
    $testParams = $input['test_params'] ?? []; // Parámetros de prueba

    try {
        $executor = new ActionExecutor($pdo);
        // El executor se encarga del Sandbox y la validación
        $result = $executor->run($code, $testData, $testParams);
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

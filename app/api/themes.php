<?php
// api/themes.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Security.php';

header('Content-Type: application/json');

/*
 * Stores UI Theme preferences (Requires 'settings' table)
 * Action: 'save' | 'get'
 */

$action = $_GET['action'] ?? '';

if ($action === 'get') {
    try {
        // Defaults to 'blue'
        $currentTheme = 'blue';
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = 'ui_theme'");
        $stmt->execute();
        $stored = $stmt->fetchColumn();
        
        if ($stored) $currentTheme = $stored;
        
        echo json_encode(['success' => true, 'theme' => $currentTheme]);
    } catch (Exception $e) {
         // Fallback default
         echo json_encode(['success' => true, 'theme' => 'blue']);
    }
    exit;
}

if ($action === 'save') {
    // Only Admin can change global theme
    if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
        echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $theme = $input['theme'] ?? 'blue';
    
    // Validate list of allowed themes
    $allowed = ['blue', 'green', 'dark']; // Added 'dark' as the 3rd option
    if (!in_array($theme, $allowed)) {
         echo json_encode(['success' => false, 'error' => 'Tema no válido']);
         exit;
    }

    try {
        $stmt = $pdo->prepare("REPLACE INTO settings (`key`, `value`) VALUES ('ui_theme', ?)");
        $stmt->execute([$theme]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

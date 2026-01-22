<?php
// check_db.php
// Script sencillo para verificar conexión a DB y estado de tablas
// Útil para debugging rápido post-instalación

require_once '../../config/db.php';

header('Content-Type: text/html; charset=utf-8');

try {
    echo "<h1>Diagnóstico de Base de Datos - Kyvid Flow</h1>";
    echo "<p><strong>Host:</strong> $host</p>";
    echo "<p><strong>DB:</strong> $db</p>";
    
    // Test Connection
    $pdo->query("SELECT 1");
    echo "<div style='color:green; font-weight:bold'>✅ Conexión Exitosa</div>";

    // List Tables
    echo "<h2>Tablas Existentes:</h2><ul>";
    $stm = $pdo->query("SHOW TABLES");
    $tables = $stm->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($tables)) {
        echo "<li style='color:red'>⚠️ No se encontraron tablas (DB vacía)</li>";
    } else {
        foreach($tables as $t) {
            // Count rows
            $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            echo "<li>$t <span style='color:#666'>($count registros)</span></li>";
        }
    }
    echo "</ul>";

    // Check specific critical tables
    $critical = ['reports', 'connections', 'actions_log', 'robot_logs'];
    echo "<h2>Verificación de Tablas Críticas:</h2>";
    foreach($critical as $c) {
        if (in_array($c, $tables)) {
            echo "<div>Checking <code>$c</code>... OK</div>";
        } else {
            echo "<div style='color:red'>Checking <code>$c</code>... FALTA ❌</div>";
        }
    }

} catch (PDOException $e) {
    echo "<div style='color:red; font-weight:bold'>❌ Error de Conexión:</div>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
echo "<hr><p><small>Ejecutado: " . date('Y-m-d H:i:s') . "</small></p>";

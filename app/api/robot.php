<?php
// api/robot.php
require_once '../config/db.php';
require_once '../src/Security.php';

header('Content-Type: application/json');

/**
 * Robot: Automated Report Executor
 * This script should be called by a system cron job (e.g., every minute)
 */

try {
    // 1. Find reports that are due for execution
    // (last_execution_at is NULL) OR (current time >= last_execution_at + interval)
    $sql = "SELECT * FROM reports 
            WHERE is_automatic = 1 
            AND (last_execution_at IS NULL 
                 OR TIMESTAMPDIFF(MINUTE, last_execution_at, NOW()) >= cron_interval_minutes)";
    
    $stmt = $pdo->query($sql);
    $pendingReports = $stmt->fetchAll();

    $log = [];
    $executedCount = 0;

    foreach ($pendingReports as $report) {
        $startTime = microtime(true);
        $results = [];
        $status = "Success";
        $errorMsg = "";

        try {
            // A. Execute SQL Query
            Security::validateQuery($report['sql_query']);
            $query = $pdo->query($report['sql_query']);
            $results = $query->fetchAll();

            // B. Pre-processing PHP Script ($results)
            if (!empty($report['php_script'])) {
                $script = $report['php_script'];
                $executor = function(&$results) use ($script) {
                    eval($script);
                };
                $executor($results);
            }

            // C. Post-processing PHP Script 2 (Legacy phpscript2)
            if (!empty($report['phpscript2'])) {
                $script2 = $report['phpscript2'];
                $messageInternal = "";
                $executor2 = function(&$results, $pdo, &$messageInternal) use ($script2) { eval($script2); };
                $executor2($results, $pdo, $messageInternal);
            }

            // D. Secure FaaS Post-Action
            if (!empty($report['post_action_code'])) {
                require_once __DIR__ . '/../src/ActionExecutor.php';
                $ex = new \App\Services\ActionExecutor($pdo);
                $actionParams = json_decode($report['post_action_params'] ?? '{}', true) ?: [];
                $ex->run($report['post_action_code'], $results, $actionParams);
            }

            // D. Update Last Execution Timestamp
            $updateStmt = $pdo->prepare("UPDATE reports SET last_execution_at = NOW() WHERE id = ?");
            $updateStmt->execute([$report['id']]);
            
            // LOG SUCCESS
            $duration = (int)((microtime(true) - $startTime) * 1000);
            $logStmt = $pdo->prepare("INSERT INTO action_logs (report_id, action_code, trigger_type, status, message, duration_ms) VALUES (?, ?, 'robot', 'success', 'Ejecución automática exitosa', ?)");
            $logStmt->execute([$report['id'], $report['post_action_code'] ?? 'SQL_ONLY', $duration]);

            $executedCount++;

        } catch (Exception $e) {
            $status = "Error";
            $errorMsg = $e->getMessage();

            // LOG ERROR
            $duration = (int)((microtime(true) - $startTime) * 1000);
            $logStmt = $pdo->prepare("INSERT INTO action_logs (report_id, action_code, trigger_type, status, message, duration_ms) VALUES (?, ?, 'robot', 'error', ?, ?)");
            $logStmt->execute([$report['id'], $report['post_action_code'] ?? 'SQL_ONLY', $errorMsg, $duration]);
        }

        $log[] = [
            'report_id' => $report['id'],
            'name' => $report['name'],
            'status' => $status,
            'error' => $errorMsg,
            'duration' => round(microtime(true) - $startTime, 4) . 's'
        ];
    }

    // --- HOUSEKEEPING: Delete logs older than 30 days ---
    try {
        $pdo->exec("DELETE FROM action_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    } catch (Exception $e) { /* silent fail for housekeeping */ }
    // ----------------------------------------------------

    echo json_encode([
        'success' => true,
        'timestamp' => date('Y-m-d H:i:s'),
        'executed_count' => $executedCount,
        'details' => $log
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

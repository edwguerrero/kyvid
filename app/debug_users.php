<?php
require_once 'config/db.php';
try {
    $stmt = $pdo->query("SELECT * FROM auth_accounts");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "--- USERS DUMP ---\n";
    print_r($users);
    echo "------------------\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

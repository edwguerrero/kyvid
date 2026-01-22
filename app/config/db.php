<?php
// config/db.php

// Prioritize Environment Variables (Docker), fallback to local defaults
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'kyvid';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$charset = 'utf8mb4';

define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: 'v3nty-r3p0rt-k3y-2024-sec');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'ADMINISTRATOR');


$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // If it's an API call, return JSON
    if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Error de conexión a la Base de Datos: ' . $e->getMessage()]);
        exit;
    }
    // Otherwise, show a nice message
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

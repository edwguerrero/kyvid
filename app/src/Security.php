<?php
// src/Security.php

class Security {
    
    /**
     * Valida si una consulta SQL es segura (solo SELECT y sin tablas/columnas sensibles)
     */
    public static function validateQuery($sql) {
        $sql = trim($sql);
        
        // 1. Debe empezar por SELECT (case insensitive)
        if (!preg_match('/^SELECT\b/i', $sql)) {
            throw new Exception("Solo se permiten consultas de lectura (SELECT). Operaciones de escritura o estructura están prohibidas.");
        }
        
        // 2. Blacklist de palabras clave prohibidas (evitar subconsultas maliciosas o manipulación)
        $forbiddenKeywords = [
            'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'TRUNCATE', 'REPLACE',
            'GRANT', 'REVOKE', 'LOCK', 'UNLOCK', 'LOAD DATA', 'INTO OUTFILE', 'INFORMATION_SCHEMA'
        ];
        
        foreach ($forbiddenKeywords as $word) {
            if (stripos($sql, $word) !== false) {
                // Verificar si es un SELECT normal o si la palabra está dentro de la consulta
                // Para simplificar, si la palabra prohibida aparece, lanzamos error 
                // a menos que sea muy específica (ej: "create" dentro de un string, pero aquí bloqueamos por seguridad)
                throw new Exception("Palabra prohibida detectada en la consulta: $word");
            }
        }
        
        // 3. Blacklist de tablas sensibles
        $forbiddenTables = ['ai_configs', 'users', 'configurations', 'api_keys'];
        foreach ($forbiddenTables as $table) {
            if (preg_match('/\b' . $table . '\b/i', $sql)) {
                throw new Exception("Acceso denegado a la tabla protegida: $table");
            }
        }
        
        // 4. Blacklist de columnas o patrones sensibles
        $forbiddenColumns = ['api_key', 'password', 'passwd', 'secret', 'token', 'token_api', 'access_key'];
        foreach ($forbiddenColumns as $col) {
            if (preg_match('/\b' . $col . '\b/i', $sql)) {
                throw new Exception("Acceso denegado a campo sensible detectado: $col");
            }
        }
        
        return true;
    }

    /**
     * Encripta una cadena usando AES-256-CBC
     */
    public static function encrypt($text) {
        if (empty($text)) return '';
        $key = substr(hash('sha256', ENCRYPTION_KEY), 0, 32);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($text, 'aes-256-cbc', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Desencripta una cadena usando AES-256-CBC
     */
    public static function decrypt($cipherText) {
        if (empty($cipherText)) return '';
        $data = base64_decode($cipherText);
        $ivSize = openssl_cipher_iv_length('aes-256-cbc');
        
        if ($data === false || strlen($data) <= $ivSize) {
            return false;
        }

        $iv = substr($data, 0, $ivSize);
        $text = substr($data, $ivSize);
        $key = substr(hash('sha256', ENCRYPTION_KEY), 0, 32);
        
        $decrypted = openssl_decrypt($text, 'aes-256-cbc', $key, 0, $iv);
        return $decrypted;
    }
    /**
     * Verifica si el usuario actual tiene privilegios de administrador
     */
    public static function isAdmin() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['isAdmin']) && $_SESSION['isAdmin'] === true;
    }

    /**
     * Detiene la ejecución si el usuario no es administrador
     */
    public static function checkAdmin() {
        if (!self::isAdmin()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Acceso denegado. Se requiere Modo Administrador.']);
            exit;
        }
    }
}

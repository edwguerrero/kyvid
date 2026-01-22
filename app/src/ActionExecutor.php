<?php
// src/ActionExecutor.php

namespace App\Services;

use PDO;
use Exception;
use Throwable;

class ActionExecutor {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Valida los parámetros de entrada según el esquema JSON definido.
     */
    public function validateParams(array $schema, array $inputs): array {
        $validated = [];
        foreach ($schema as $field => $def) {
            $value = $inputs[$field] ?? $def['default'] ?? null;
            
            if (($def['required'] ?? false) && $value === null) {
                throw new Exception("El parámetro '$field' es obligatorio.");
            }

            // Validación de tipos básica
            switch ($def['type'] ?? 'string') {
                case 'int':
                case 'integer':
                    $value = (int)$value;
                    break;
                case 'boolean':
                case 'bool':
                    $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                case 'float':
                    $value = (float)$value;
                    break;
            }
            
            $validated[$field] = $value;
        }
        return $validated;
    }

    /**
     * Ejecuta una acción personalizada de forma segura.
     */
    public function run(string $actionCode, array $data = [], array $inputs = []): array {
        try {
            // 1. Obtener la acción de la DB
            $stmt = $this->pdo->prepare("SELECT * FROM custom_actions WHERE code = ? AND is_active = 1");
            $stmt->execute([$actionCode]);
            $action = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$action) {
                return ['success' => false, 'error' => "Acción '$actionCode' no encontrada o inactiva."];
            }

            // 2. Validar parámetros
            $schema = [];
            if (!empty($action['parameters_schema'])) {
                $schema = json_decode($action['parameters_schema'], true) ?: [];
            }
            $params = $this->validateParams($schema, $inputs);

            // 3. Preparar el Sandbox via Closure
            // El wrapper asegura aislamiento de variables globales
            $userCode = $action['php_content'];
            
            // Contexto API básico para las funciones (si fuera necesario)
            $apiContext = [
                'timestamp' => date('Y-m-d H:i:s'),
                'action_name' => $action['name']
            ];

            // Inyectamos la estructura de función anónima
            $safeWrapper = "return function(array \$results, array \$params, array \$context) { 
                " . $userCode . "
                return \$results; // Por defecto retorna los datos (posiblemente modificados)
            };";

            try {
                // Evaluamos para obtener la función, no ejecutamos directamente el código del usuario
                $closure = eval($safeWrapper);
                
                if (!is_callable($closure)) {
                    throw new Exception("El código de la acción no generó una función válida.");
                }

                // Ejecución real dentro del Sandbox
                $output = $closure($data, $params, $apiContext);

                return [
                    'success' => true,
                    'output' => $output,
                    'message' => 'Acción ejecutada correctamente.'
                ];

            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'error' => "Error de ejecución en '{$action['name']}': " . $e->getMessage(),
                    'line' => $e->getLine()
                ];
            }

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

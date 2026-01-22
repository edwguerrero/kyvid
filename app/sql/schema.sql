-- Database Schema for MVP Personalized Reports
-- Updated for Kyvid Flow with Unified Connections Module

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for reports
-- ----------------------------
DROP TABLE IF EXISTS `reports`;
CREATE TABLE `reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL COMMENT 'Unique code for the report',
  `category` varchar(50) DEFAULT 'General' COMMENT 'Report category (e.g., Ventas, Inventario)',
  `name` varchar(100) NOT NULL COMMENT 'Human readable name',
  `description` text COMMENT 'Report description',
  `sql_query` text NOT NULL COMMENT 'Base SQL query',
  `php_script` text COMMENT 'PHP code to execute after query (Pre-render). Available var: $results',
  `phpscript2` text COMMENT 'Post-processing PHP script (executed after user clicks Procesar). Variables: $results, $pdo, $message',
  `columns_json` text COMMENT 'JSON array of column headers',
  `parameters_json` text COMMENT 'JSON defining available filters',
  `grouping_config` text DEFAULT NULL COMMENT 'JSON for grouping and sum configuration',
  `is_automatic` tinyint(1) DEFAULT 0 COMMENT '0: Manual, 1: Automatic',
  `cron_interval_minutes` int(11) DEFAULT 60 COMMENT 'Minutes between executions',
  `last_execution_at` timestamp NULL DEFAULT NULL,
  `connection_id` int(11) DEFAULT NULL COMMENT 'Link to db_connections if not local',
  `is_view` tinyint(1) DEFAULT 0 COMMENT '1: Treat as Virtual View component',
  `post_action_code` varchar(50) DEFAULT NULL COMMENT 'Code of the custom function to run',
  `post_action_params` json DEFAULT NULL COMMENT 'JSON parameters for the custom function',
  `is_active` tinyint(1) DEFAULT 1 COMMENT '1: Visible, 0: Hidden',
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table structure for db_connections (SQL Sources)
-- ----------------------------
DROP TABLE IF EXISTS `db_connections`;
CREATE TABLE `db_connections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` enum('mysql','pgsql') DEFAULT 'mysql',
  `host` varchar(255) NOT NULL,
  `port` int(11) DEFAULT 3306,
  `database_name` varchar(255) NOT NULL,
  `database_schema` varchar(100) DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `password_encrypted` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for connections (Unified Vault: SMTP, AI, TELEGRAM, ETC)
-- ----------------------------
DROP TABLE IF EXISTS `connections`;
CREATE TABLE `connections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` varchar(50) NOT NULL COMMENT 'SMTP, AI, TELEGRAM, N8N, DRIVE',
  `provider` varchar(50) DEFAULT NULL COMMENT 'Subtipo: openai, gemini, gmail...',
  `encrypted_creds` text COMMENT 'JSON encriptado con claves privadas',
  `config_json` text COMMENT 'JSON legible con configuración pública (prompts, host, user)',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for custom_actions (Secure FaaS)
-- ----------------------------
DROP TABLE IF EXISTS `custom_actions`;
CREATE TABLE `custom_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT 'General',
  `description` text,
  `php_content` mediumtext NOT NULL,
  `parameters_schema` text COMMENT 'JSON schema for validation',
  `timeout_sec` int(11) DEFAULT 30,
  `is_active` tinyint(1) DEFAULT 1,
  `last_modified_by` int(11) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  INDEX `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Seeding Default Functions (Secure FaaS)
-- ----------------------------
INSERT INTO `custom_actions` (`code`, `name`, `category`, `description`, `php_content`, `parameters_schema`) VALUES
('INTEG_SEND_N8N', 'Enviar JSON a n8n', 'Integración', 'Envía los resultados del reporte como JSON a un webhook de n8n.', '// Envío de datos a n8n\n$webhookUrl = $params["webhook_url"];\nif (empty($webhookUrl)) return ["error" => "Webhook URL faltante"];\n\n$ch = curl_init($webhookUrl);\n$payload = json_encode([\n    "timestamp" => date("c"),\n    "report" => $context["action_name"],\n    "data" => $results\n]);\n\ncurl_setopt($ch, CURLOPT_POSTFIELDS, $payload);\ncurl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);\ncurl_setopt($ch, CURLOPT_RETURNTRANSFER, true);\ncurl_setopt($ch, CURLOPT_TIMEOUT, 10);\n\n$response = curl_exec($ch);\n$info = curl_getinfo($ch);\ncurl_close($ch);\n\nif ($info["http_code"] >= 400) {\n    return ["status" => "error", "http_code" => $info["http_code"], "response" => $response];\n}\n\nreturn ["status" => "success", "sent_count" => count($results)];', '{"webhook_url":{"type":"string","required":true,"default":""}}'),
('UTIL_SEND_TELEGRAM', 'Notificación Telegram', 'Comunicación', 'Envía un mensaje formateado o alerta a un bot de Telegram. Usa la primera conexión activa de Telegram configurada en el sistema.', 'global $pdo;\n// 1. Obtener conexión activa TELEGRAM\n$stmt = $pdo->query("SELECT * FROM connections WHERE type=''TELEGRAM'' AND is_active=1 LIMIT 1");\n$conn = $stmt->fetch(PDO::FETCH_ASSOC);\nif (!$conn) return ["error" => "No hay conexión de Telegram configurada"];\n\n$creds = json_decode(\Security::decrypt($conn["encrypted_creds"]), true);\n$botToken = $creds["bot_token"] ?? null;\n$chatId = $creds["chat_id"] ?? null;\n\n// Override con parametros si existen (opcional)\nif (!empty($params["override_chat_id"])) $chatId = $params["override_chat_id"];\n\nif (empty($botToken) || empty($chatId)) return ["error" => "Credenciales Telegram incompletas"];\n\n$titulo = $params["title"] ?? "Resumen del Reporte";\n$count = count($results);\n$text = "<b>🚀 $titulo</b>\\n\\n";\n$text .= "Se han encontrado <b>$count</b> registros.\\n";\n$text .= "<i>Generado el: " . date("d/m/Y H:i") . "</i>\\n\\n";\n\nif ($count > 0 && (!isset($params["show_samples"]) || $params["show_samples"])) {\n    $text .= "<b>Muestra (Primeros 3):</b>\\n";\n    $sample = array_slice($results, 0, 3);\n    foreach($sample as $row) {\n        $lines = [];\n        $i = 0;\n        foreach ($row as $key => $val) { if ($i++ >= 3) break; $lines[] = "<b>$key</b>: $val"; }\n        $text .= "🔹 " . implode(" | ", $lines) . "\\n";\n    }\n}\n\n$url = "https://api.telegram.org/bot$botToken/sendMessage";\n$data = ["chat_id" => $chatId, "text" => $text, "parse_mode" => "HTML"];\n\n$ch = curl_init($url);\ncurl_setopt($ch, CURLOPT_POST, true);\ncurl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));\ncurl_setopt($ch, CURLOPT_RETURNTRANSFER, true);\n$resp = curl_exec($ch);\ncurl_close($ch);\n\nreturn json_decode($resp, true);', '{"title":{"type":"string","default":"Alerta"},"override_chat_id":{"type":"string","description":"Opcional: Usar otro Chat ID"}}'),
('UTIL_EMAIL_REPORT', 'Enviar Informe por Email', 'Comunicación', 'Envía el reporte por correo usando la conexión SMTP configurada.', '// REQUIRES src/SimpleSMTP.php\nrequire_once __DIR__ . ''/../src/SimpleSMTP.php'';\nglobal $pdo;\n\n// 1. Obtener Config SMTP Activa\n$stmt = $pdo->query("SELECT * FROM connections WHERE type=''SMTP'' AND is_active=1 LIMIT 1");\n$conn = $stmt->fetch(PDO::FETCH_ASSOC);\n\nif (!$conn) return ["error" => "No hay conexión SMTP activa"];\n\n$creds = json_decode(\Security::decrypt($conn["encrypted_creds"]), true);\n$config = json_decode($conn["config_json"], true);\n\n// 2. Parámetros\n$to = $params[''destinatario''] ?? null;\n$subject = $params[''asunto''] ?? ''Reporte Kyvid Flow'';\n$includeCsv = $params[''adjuntar_csv''] ?? true;\n\nif (empty($to)) return $results;\n\n// 3. HTML Builder\n$html = ''<html><body style="font-family: sans-serif;">'';\n$html .= "<h2>" . htmlspecialchars($subject) . "</h2>";\n$html .= "<p>Datos generados: " . date(''Y-m-d H:i'') . "</p>";\nif (empty($results)) {\n    $html .= "<p><strong>No se encontraron datos para este reporte.</strong></p>";\n} else {\n    $html .= "<table border=''1'' cellpadding=''5'' style=''border-collapse:collapse; width:100%;''>";\n    $html .= "<tr style=''background:#eee;''>";\n    foreach (array_keys($results[0]) as $h) $html .= "<th>" . htmlspecialchars($h) . "</th>";\n    $html .= "</tr>";\n    foreach (array_slice($results, 0, 50) as $row) {\n        $html .= "<tr>";\n        foreach ($row as $v) $html .= "<td>" . htmlspecialchars($v) . "</td>";\n        $html .= "</tr>";\n    }\n    $html .= "</table>";\n    if (count($results) > 50) $html .= "<p>...y más filas en el adjunto.</p>";\n}\n$html .= "</body></html>";\n\n// 4. Attachments\n$atts = [];\nif ($includeCsv && !empty($results)) {\n    $f = fopen(''php://temp'', ''r+'');\n    fputcsv($f, array_keys($results[0]));\n    foreach ($results as $r) fputcsv($f, $r);\n    rewind($f);\n    $csvContent = stream_get_contents($f);\n    fclose($f);\n    $atts[] = [''name'' => ''reporte.csv'', ''type'' => ''text/csv'', ''content'' => $csvContent];\n}\n\n// 5. Enviar usando SimpleSMTP\n$smtp = new SimpleSMTP(\n    $config[''host''], \n    $config[''port''], \n    $config[''username''], \n    $creds[''password'']\n);\n\n$sent = $smtp->send($to, $subject, $html, $atts);\n\nreturn $results;', '{"destinatario":{"type":"string","required":true},"asunto":{"type":"string","required":false},"adjuntar_csv":{"type":"boolean","default":true}}'),
('AI_COMPETITOR_RESEARCH', 'Investigación de Precios IA', 'Inteligencia', 'Usa IA para estimar precios de competencia. Usa la conexión IA activa.', 'global $pdo; \nif (!$pdo) return ["error" => "No DB access"];\n\n// 1. Obtener Configuración de IA activa de connections\n$stmt = $pdo->query("SELECT * FROM connections WHERE type=''AI'' AND is_active=1 LIMIT 1");\n$conn = $stmt->fetch(PDO::FETCH_ASSOC);\n\n$warning = null;\n$hasKey = false;\n$apiKey = null;\n\nif (!$conn) {\n    $warning = "⚠️ Configure una Conexión de IA para ver precios reales.";\n} else {\n    $creds = json_decode(\Security::decrypt($conn["encrypted_creds"]), true);\n    $apiKey = $creds["api_key"] ?? null;\n    if (!$apiKey) {\n        $warning = "⚠️ API Key inválida.";\n    } else {\n        $hasKey = true;\n    }\n}\n\n$config = $conn ? json_decode($conn["config_json"], true) : [];\n$provider = $conn["provider"] ?? null;\n$model = $config["model"] ?? null;\n\n$processed = [];\n$limit = array_slice($results, 0, 5);\n\nforeach ($limit as $row) {\n    $row["ai_competitor_price"] = 0;\n    $row["ai_comparison"] = "-"; \n\n    $productName = $row["name"] ?? $row["nombre"] ?? "";\n    \n    if ($hasKey && !empty($productName)) {\n        $prompt = "Eres un analista de mercado. Investiga el precio promedio actual en el mercado de Colombia para: ''$productName''. Responde SOLO con un número decimal (el precio) sin símbolos ni texto.";\n        $price = 0;\n        \n        try {\n            // Implementación simplificada (aquí deberíamos usar el servicio unificado, pero hardcodeamos OpenAI/Gemini por brevedad)\n             if ($provider === "gemini") {\n                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=$apiKey";\n                $payload = ["contents" => [["parts" => [["text" => $prompt]]]]];\n                $ch = curl_init($url);\n                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));\n                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);\n                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);\n                $res = curl_exec($ch);\n                $resArr = json_decode($res, true);\n                $priceStr = $resArr["candidates"][0]["content"]["parts"][0]["text"] ?? "0";\n                $price = floatval(preg_replace("/[^0-9.]/", "", $priceStr));\n                curl_close($ch);\n            }\n            // ... otros proveedores se manejarían similar o centralizado\n        } catch (Exception $subE) { $price = 0; }\n\n        $row["ai_competitor_price"] = $price;\n        $myPrice = floatval($row["price"] ?? $row["precio"] ?? 0);\n        $row["ai_comparison"] = ($price > 0) ? (($myPrice > $price) ? "🔴 Más caro" : "🟢 Competitivo") : "❓ Sin datos";\n    }\n    $processed[] = $row;\n}\n\nreturn $processed;', '[]');

-- ----------------------------
-- Mock Data Tables (Customers, Products, Sales)
-- ----------------------------

-- Customers
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `region` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Products
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sale_date` date NOT NULL,
  `quantity` int(11) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Seeding Mock Data
-- ----------------------------
INSERT INTO `customers` (`name`, `email`, `region`) VALUES
('Acme Corp', 'contact@acme.com', 'North'),
('Globex Corporation', 'info@globex.com', 'East'),
('Soylent Corp', 'sales@soylent.com', 'West'),
('Umbrella Corp', 'secure@umbrella.com', 'South');

INSERT INTO `products` (`code`, `name`, `category`, `price`, `stock`) VALUES
('P001', 'Smartphone X', 'Electronics', 699.99, 15),
('P002', 'Laptop Pro', 'Electronics', 1299.99, 30),
('P003', 'Ergonomic Chair', 'Furniture', 199.99, 5),
('P004', 'Wooden Desk', 'Furniture', 299.99, 20),
('P005', 'T-Shirt Basic', 'Clothing', 19.99, 120);

INSERT INTO `sales` (`customer_id`, `product_id`, `sale_date`, `quantity`, `total_price`) VALUES
(1, 1, '2023-10-01', 2, 1399.98),
(1, 3, '2023-10-05', 5, 999.95),
(2, 2, '2023-10-10', 1, 1299.99),
(3, 4, '2023-10-15', 2, 599.98),
(4, 5, '2023-10-20', 10, 199.90),
(2, 1, '2023-11-01', 1, 699.99),
(3, 2, '2023-11-05', 3, 3899.97);

-- ----------------------------
-- Seeding Reports (Core Examples)
-- ----------------------------
INSERT INTO `reports` (`id`, `code`, `category`, `name`, `description`, `sql_query`, `php_script`, `columns_json`, `parameters_json`, `grouping_config`, `phpscript2`, `is_view`) VALUES
(1, 'SALES_BY_DATE', 'Ventas', 'Ventas por Fecha', 'Reporte detallado de ventas filtrado por rango de fechas.', 'SELECT s.id, s.sale_date, c.name as customer, p.name as product, s.quantity, s.total_price FROM sales s JOIN customers c ON s.customer_id = c.id JOIN products p ON s.product_id = p.id', NULL, '["ID", "Fecha", "Cliente", "Producto", "Cantidad", "Total"]', '[{"type": "date_range", "field": "s.sale_date", "label": "Rango de Fechas"}]', NULL, NULL, 0),
(2, 'PRICE_REVISION', 'Financieros', 'Preliquidación de Precios (Editable)', 'Edita el "Nuevo Precio" manualmente y actualiza la base de datos con "Procesar".', 'SELECT id, code, name, price, price as new_price FROM products', NULL, '["ID", "Código", "Producto", "Precio Actual", "Precio Nuevo (Editar)"]', '[]', NULL, '$updated = 0;\nforeach ($results as $row) {\n    if (isset($row["new_price"]) && is_numeric($row["new_price"]) && (float)$row["new_price"] !== (float)$row["price"]) {\n        $stmt = $pdo->prepare("UPDATE products SET price = ? WHERE id = ?");\n        $stmt->execute([$row["new_price"], $row["id"]]);\n        $updated++;\n    }\n}\n$message = $updated > 0 ? "✅ Se actualizaron $updated precios." : "ℹ️ No se detectaron cambios de precio.";', 0),
(3, 'RPT_AUTO_EMAIL_SALES', 'Automatización', 'Reporte Diario de Ventas (Email)', 'Resumen de ventas del día que se envía automáticamente.', 'SELECT s.sale_date as Fecha, c.name as Cliente, p.name as Producto, s.total_price as Total FROM sales s JOIN customers c ON s.customer_id = c.id JOIN products p ON s.product_id = p.id WHERE s.sale_date >= CURDATE()', NULL, '["Fecha", "Cliente", "Producto", "Total"]', '[]', NULL, NULL, 0),
(4, 'STOCK_STATUS', 'Inventario', 'Estado de Inventario', 'Muestra los productos con stock bajo (menos de 20 unidades).', 'SELECT code as Codigo, name as Producto, category as Categoria, stock as Existencias FROM products WHERE stock < 20', NULL, '["Codigo", "Producto", "Categoria", "Existencias"]', '[]', NULL, NULL, 0),
(5, 'SALES_BY_CAT_CHART', 'Ventas', 'Ventas por Categoría (Barras)', 'Reporte optimizado para visualización de gráficos.', 'SELECT p.category as Categoria, SUM(s.total_price) as Total FROM sales s JOIN products p ON s.product_id = p.id GROUP BY p.category', NULL, '["Categoria", "Total"]', '[]', NULL, NULL, 0),
(6, 'KPI_TOTAL_REVENUE', 'Indicadores', 'KPI: Ingresos Totales', 'Valor único de ingresos para usar en dashboards.', 'SELECT SUM(total_price) as Valor FROM sales', NULL, '["Valor"]', '[]', NULL, NULL, 0),
(7, 'RPT_INTEG_N8N_SALES', 'Integración', 'Sincronizar Ventas (N8N)', 'Envía todas las ventas recientes a un webhook de n8n para procesamiento externo.', 'SELECT * FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)', NULL, '["ID", "Cliente ID", "Producto ID", "Fecha", "Cantidad", "Total"]', '[]', NULL, NULL, 0),
(8, 'RPT_ALERT_TELEGRAM_STOCK', 'Alertas', 'Alerta Stock Crítico (Telegram)', 'Escanea productos con stock bajo y envía una notificación detallada por Telegram.', 'SELECT name as Producto, stock as Cantidad FROM products WHERE stock < 10', NULL, '["Producto", "Cantidad"]', '[]', NULL, NULL, 0);

-- Associate Post-Actions
UPDATE reports SET post_action_code = 'UTIL_EMAIL_REPORT', post_action_params = '{"destinatario": "tu-email@ejemplo.com", "asunto": "📊 Resumen de Ventas Diario", "adjuntar_csv": true}' WHERE code = 'RPT_AUTO_EMAIL_SALES';
UPDATE reports SET post_action_code = 'INTEG_SEND_N8N', post_action_params = '{"webhook_url": "https://n8n.tu-servidor.com/webhook/..."}' WHERE code = 'RPT_INTEG_N8N_SALES';
UPDATE reports SET post_action_code = 'UTIL_SEND_TELEGRAM', post_action_params = '{"title": "🚨 Alerta de Inventario Bajo", "show_samples": true}' WHERE code = 'RPT_ALERT_TELEGRAM_STOCK';

-- ----------------------------
-- Seeding DB Connections (Examples)
-- ----------------------------
INSERT INTO `db_connections` (`name`, `type`, `host`, `port`, `database_name`, `username`, `password_encrypted`, `is_active`) VALUES 
('Servidor Producción (Mock)', 'mysql', '127.0.0.1', 3306, 'prod_db', 'db_user', 'ENCRYPTED_PLACEHOLDER', 0);

-- ----------------------------
-- Seeding Unified Connections (Examples)
-- ----------------------------
INSERT INTO `connections` (`name`, `type`, `provider`, `config_json`, `encrypted_creds`, `is_active`) VALUES 
('Gmail Corporativo', 'SMTP', 'gmail', '{"host":"smtp.gmail.com","port":"465","username":"tu-app@gmail.com"}', 'ENCRYPTED_PLACEHOLDER', 1),
('OpenAI GPT-4o', 'AI', 'openai', '{"model":"gpt-4o","base_url":"https://api.openai.com/v1"}', 'ENCRYPTED_PLACEHOLDER', 1),
('Telegram Alertas', 'TELEGRAM', 'telegram', '{}', 'ENCRYPTED_PLACEHOLDER', 1);

-- ----------------------------
-- Table structure for scenarios (Canvas Container)
-- ----------------------------
DROP TABLE IF EXISTS `scenarios`;
CREATE TABLE `scenarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Ej: Dashboard Comercial, Cierre de Mes',
  `description` text COMMENT 'Objetivo del escenario y contexto para la IA',
  `category` varchar(50) DEFAULT 'General',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `scenarios` (`id`, `name`, `description`, `category`) VALUES 
(1, 'Dashboard Operativo', 'Resumen general de ventas, inventario crítico e indicadores clave del negocio.', 'General');

-- ----------------------------
-- Table structure for scenario_widgets (Positioning and Display)
-- ----------------------------
DROP TABLE IF EXISTS `scenario_widgets`;
CREATE TABLE `scenario_widgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scenario_id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `title_override` varchar(100) DEFAULT NULL,
  `display_type` enum('kpi', 'table', 'chart', 'pivot') DEFAULT 'table',
  `chart_type` varchar(20) DEFAULT 'bar',
  `grid_layout` text COMMENT 'JSON with x, y, w, h for Gridstack',
  `config_json` text COMMENT 'Special config for the widget',
  `order_index` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_widget_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `scenarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `scenario_widgets` (`scenario_id`, `report_id`, `title_override`, `display_type`, `chart_type`, `grid_layout`) VALUES 
(1, 6, 'Ingresos Totales', 'kpi', 'bar', '{"x":0,"y":0,"w":3,"h":2}'),
(1, 4, 'Stock Bajo Crítico', 'table', 'bar', '{"x":3,"y":0,"w":9,"h":4}'),
(1, 5, 'Ventas por Categoría', 'chart', 'pie', '{"x":0,"y":2,"w":3,"h":4}'),
(1, 1, 'Histórico de Ventas', 'table', 'bar', '{"x":0,"y":6,"w":12,"h":4}');


-- ----------------------------
-- Table structure for settings
-- ----------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `key` varchar(50) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`key`, `value`) VALUES 
('admin_password', 'ADMINISTRATOR');

-- ----------------------------
-- Table structure for auth_accounts
-- ----------------------------
DROP TABLE IF EXISTS `auth_accounts`;
CREATE TABLE `auth_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'viewer',
  `attributes_json` json DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `auth_accounts` (`code`, `name`, `password_hash`, `role`) VALUES
('admin', 'Administrador del Sistema', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'); 

-- ----------------------------
-- Table structure for action_logs
-- ----------------------------
DROP TABLE IF EXISTS `action_logs`;
CREATE TABLE `action_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action_code` varchar(100) DEFAULT NULL,
  `trigger_type` enum('manual','robot','system') DEFAULT 'manual',
  `status` enum('success','error','warning') DEFAULT 'success',
  `message` text,
  `details_json` json DEFAULT NULL,
  `duration_ms` int(11) DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_report` (`report_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------
-- Table structure for shared_reports
-- ----------------------------
DROP TABLE IF EXISTS `shared_reports`;
CREATE TABLE `shared_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(100) NOT NULL,
  `report_id` int(11) NOT NULL,
  `filters_json` text,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  CONSTRAINT `fk_shared_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

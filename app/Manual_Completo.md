# Kyvid Flow - Manual de Documentación & Instalación

## 1. Descripción del Proyecto

**Kyvid Flow** es una plataforma inteligente de **orquestación de datos y automatización**. Su propósito es permitir que los datos no solo sean visualizados, sino que desencadenen acciones reales en el negocio.

*"Donde tus datos toman acción"*

### Arquitectura Técnica
*   **Backend**: PHP 8.2 (Vanilla) optimizado para contenedores Docker.
*   **Frontend**: SPA Híbrida (HTML5/Bootstrap5/jQuery) con visualización avanzada (PivotTables, Chart.js).
*   **Motor**: Action Engine integrado para ejecutar scripts PHP en entornos aislados (FaaS Local).
*   **Base de Datos**: MySQL 8.
*   **Infraestructura**: Diseñado para correr en contenedores Docker detrás de un proxy Caddy (SSL Automático).

### Funcionalidades Clave

1.  **Reportes Inteligentes (SQL + AI)**:
    *   Generación de consultas SQL complejas mediante lenguaje natural (integración con OpenAI, Gemini, etc.).
    *   Filtros dinámicos inyectados automáticamente.

2.  **Kyvid Flow Actions (FaaS)**:
    *   Motor de ejecución que toma resultados de un reporte y ejecuta lógica de negocio.
    *   Ejemplos: Enviar campañas de email, alertas a Telegram, sincronizar con CRMs vía API.

3.  **Robot de Automatización (Cron Web)**:
    *   Sistema de programación de tareas integrado.
    *   Sincronización multi-pestaña ("Master Tab") para evitar duplicidad de ejecuciones.

4.  **Dashboards de Escenarios**:
    *   Canvas interactivo para combinar múltiples fuentes de datos.
    *   **Análisis Narrativo**: La IA analiza los datos visualizados y genera un reporte ejecutivo en texto.

---

## 2. Guía de Instalación (Docker + Oracle Cloud)

Esta guía asume que ya tienes un servidor Ubuntu en Oracle Cloud con Docker y Docker Compose instalados.

### Paso 1: Configurar el Servidor

1.  **Clonar/Subir el Proyecto**:
    Sube todos los archivos a `/srv/kyvid-business/` en tu servidor.

2.  **Configurar Variables de Entorno (Opcional)**:
    Edita el archivo `docker-compose.yml` para establecer contraseñas seguras para la base de datos:
    *   `MYSQL_ROOT_PASSWORD`
    *   `MYSQL_PASSWORD` -> Debe coincidir con `DB_PASS` en el servicio `kyvid-app`.
    *   `ADMIN_PASSWORD` -> Contraseña maestra para acceder a las funciones de administración en la web.

3.  **Configurar Dominio**:
    Verifica que el archivo `Caddyfile` tenga el dominio correcto:
    ```
    flow.kyvid.com {
        reverse_proxy kyvid-app:80
    }
    ```
    *Asegúrate de apuntar el registro DNS tipo A de `flow.kyvid.com` a la IP Pública de tu servidor.*

### Paso 2: Despliegue

Desde `/srv/kyvid-business/`, ejecuta:

```bash
# Construir y levantar los contenedores en segundo plano
docker compose up -d --build
```

### Paso 3: Verificación

1.  Ingresa a `https://flow.kyvid.com`. Caddy generará el certificado SSL automáticamente en unos segundos.
2.  Inicia sesión como Administrador (Click en el candado) usando la contraseña que definiste en `ADMIN_PASSWORD` (Por defecto: `ADMINISTRATOR`).

---

## 3. Hoja de Ruta (Roadmap) de Nuevas Funciones

Para consolidar a **Kyvid Flow** como una herramienta enterprise, se sugieren las siguientes mejoras:

### A. Trazabilidad y Seguridad (Completado ✅)
*   [x] **Action Logs / Bitácora**: Panel de auditoría que muestra el historial de cada ejecución del Robot y procesos manuales (Fecha, Acción, Resultado, Duración).
*   [x] **Gestión de Usuarios y RBAC**: Sistema de roles (admin/viewer) y permisos granulares (ACL) por reporte.
*   [x] **Variables Máguicas de Contexto**: Inyección dinámica de `{USER.CODE}`, `{USER.ATRIBUTO}` en SQL.

### B. Funcionalidad Core
*   [x] **Limpieza Automática**: El Robot limpia automáticamente registros de bitácora con más de 30 días.
*   [ ] **variables Globales**: Panel para definir constantes de negocio (ej: `TRM_USD`, `IVA_PCT`) reutilizables.

### C. Experiencia de Usuario
*   [ ] **Editor Visual de SQL**: Integrar una librería visual para construir consultas arrastrando tablas, facilitando el uso para usuarios no técnicos.

---

Manual generado para **Kyvid Flow v1.0**.

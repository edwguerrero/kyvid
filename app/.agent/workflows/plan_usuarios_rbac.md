# Plan de Implementación: Sistema de Gestión de Usuarios y Seguridad RBAC

## 1. Arquitectura de Datos (Base de Datos)

Se requiere una estructuras robusta para manejar usuarios, sus secretos y sus contextos de negocio.

### Nueva Tabla: `users`
Almacenará la identidad y el contexto del usuario.
```sql
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) UNIQUE NOT NULL,      -- Código de usuario (hasta 15 dígitos)
    name VARCHAR(100) NOT NULL,            -- Nombre completo
    password_hash VARCHAR(255) NOT NULL,   -- Hash Bcrypt seguro
    role ENUM('admin', 'viewer') DEFAULT 'viewer', -- Perfil base del sistema
    
    -- Contexto de Negocio (JSON)
    -- Ejemplo: {"ceco": "200", "bodegas": [1, 5], "region": "NORTE"}
    attributes_json JSON DEFAULT NULL,
    
    is_active TINYINT(1) DEFAULT 1,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Modificación: `reports`
Se añadirán columnas para definir la Matriz de Accesos (ACL) por reporte.
```sql
ALTER TABLE reports 
ADD COLUMN acl_view JSON,    -- Roles/Usuarios que pueden CONSULTAR: ["admin", "gerente", "u:123"]
ADD COLUMN acl_edit JSON,    -- Roles/Usuarios que pueden EDITAR
ADD COLUMN acl_delete JSON;  -- Roles/Usuarios que pueden ELIMINAR
```

---

## 2. Sistema de Autenticación (Login Corporativo)

Implementaremos un login de "Experiencia Entrerprise":

1.  **Paso 1**: Usuario ingresa Código.
2.  **Validación**: AJAX consulta si existe y retorna el Nombre (sin pedir password aún).
    *   *Feedback visual*: "Hola, Edwin Guerrero".
3.  **Paso 2**: Usuario ingresa Contraseña.
4.  **Sesión**: Se inicia sesión segura con `params` cargados en memoria.
5.  **Persistencia**: Opción "Recordar mi dispositivo" usando un Token seguro en cookie (no la password).

---

## 3. Motor de Filtros Contextuales (Variables Mágicas)

Esta es la funcionalidad más potente. Permitirá modificar `ReportFilterBuilder.php` para inyectar valores del usuario directamente en el SQL.

**Sintaxis Propuesta:**
En tu consulta SQL, podrás escribir:
```sql
SELECT * FROM ventas 
WHERE bodega_id IN ({USER.BODEGAS}) 
AND centro_costo = '{USER.CECO}'
```

**Comportamiento:**
Al ejecutar el reporte, el sistema:
1.  Detecta `{USER.BODEGAS}`.
2.  Busca en la sesión del usuario actual `attibutes_json['bodegas']`.
3.  Si es `[1, 5]`, reemplaza el token por `1, 5`.
4.  El SQL final queda: `WHERE bodega_id IN (1, 5)`.

---

## 4. Gestión de Permisos (UI)

### Panel de Administración de Usuarios
*   CRUD completo de usuarios.
*   **Editor de Atributos**: Una interfaz simple (Tabla clave/valor) para asignar "bodegas", "cecos", etc., a cada usuario sin escribir JSON manualmente.

### Panel de Edición de Reportes
*   Nuevos selectores múltiples (Select2 o similar) para elegir: "¿Quién puede ver esto?" (Todos, Solo Admin, Usuarios Específicos).

---

## 5. Fases de Ejecución

1.  **Fase Infraestructura**: Crear tablas y actualizar `api/auth.php` y `src/Security.php`.
2.  **Fase Frontend Login**: Crear el nuevo diseño de Login (reemplazando el modal actual simple).
3.  **Fase Gestión**: Crear el módulo "Usuarios" en el panel de administración.
4.  **Fase Core**: Implementar la lógica de inyección de variables `{USER.XYZ}` en `ReportFilterBuilder`.
5.  **Fase Blindaje**: Actualizar `api/index.php` para bloquear el acceso a reportes si el usuario no tiene permiso en el JSON `acl_view`.

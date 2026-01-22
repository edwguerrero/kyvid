# 🔄 Manual de Actualización - Kyvid Flow

Este manual detalla los procedimientos para actualizar tanto el código de la aplicación como la estructura/datos de la base de datos utilizando Docker.

---

## 🚀 1. Actualización Total del Proyecto
Usa estos comandos si has realizado cambios en el código PHP, archivos CSS/JS o quieres asegurarte de que todas las imágenes de Docker se descarguen nuevamente.

```powershell
# 1. Detener los servicios actuales
docker compose down -v

# 2. Eliminar la imagen de la aplicación para forzar reconstrucción
docker rmi kyvid-kyvid-app  

# 3. (Opcional) Eliminar la imagen de MySQL para refrescarla
docker rmi mysql:8.0

# 4. Levantar el proyecto construyendo desde cero
docker compose up -d --build
```
> **Nota:** Este proceso mantiene los datos de tu base de datos si no usas el flag `-v`.

---

## 🗄️ 2. Actualización / Reinicio de Base de Datos
Usa estos comandos si has modificado el archivo `app/sql/schema.sql` y quieres que la base de datos se borre y se vuelva a crear con los nuevos ejemplos, reportes y conexiones.

**⚠️ ADVERTENCIA:** Esto borrará todos los datos actuales de la base de datos.

```powershell
# 1. Detener servicios y BORRAR volúmenes de datos (-v)
docker compose down -v

# 2. Levantar el proyecto
# (MySQL detectará que no hay datos y ejecutará el schema.sql automáticamente)
docker compose up -d --build
```

---

## ⚡ 3. Actualización Rápida (Solo Código)
Si solo cambiaste archivos PHP/JS y no quieres tocar la base de datos ni borrar imágenes pesadas:

```powershell
# Solo reconstruye el contenedor de la app
docker compose up -d --build kyvid-app
```

---

## 🛠️ Comandos de Verificación
Para confirmar que todo subió correctamente:

*   **Ver estado:** `docker compose ps`
*   **Ver logs en vivo:** `docker compose logs -f`
*   **Ver logs de la DB:** `docker compose logs kyvid-db`

---
*Manual generado para Kyvid Flow - v1.0*

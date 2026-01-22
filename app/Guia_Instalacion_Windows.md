# Guía de Instalación Local en Windows con Docker

Esta guía te permitirá correr **Kyvid Flow** y su **Landing Page** en tu máquina local Windows utilizando Docker. Esto garantiza que el entorno sea idéntico al del servidor de producción.

---

## Prerrequisitos

1.  **Docker Desktop para Windows**:
    *   Descárgalo e instálalo desde: [docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop/)
    *   Asegúrate de que Docker esté corriendo (icono de la ballena en la barra de tareas).

2.  **Git (Opcional)**: Recomendado para clonar el repositorio, aunque también puedes descargar el ZIP.

---

## Pasos de Instalación

### 1. Preparar el Entorno

Abre una terminal (PowerShell o CMD) y navega a la carpeta donde tienes el proyecto.

```powershell
cd C:\ruta\a\tu\proyecto\kyvid
```

### 2. Configuración de Hosts (Simulación de Dominios)

Como vamos a usar dominios reales (`kyvid.com`, `flow.kyvid.com`) en local, necesitamos engañar a tu computadora para que crea que esos dominios apuntan a ella misma (`127.0.0.1`), en lugar de buscar en internet.

1.  Abre el **Bloc de Notas** como **Administrador**.
2.  Abre el archivo: `C:\Windows\System32\drivers\etc\hosts`
    *(Nota: Debes seleccionar "Todos los archivos (*.*)" para verlo)*
3.  Agrega estas líneas al final del archivo:

```text
127.0.0.1 kyvid.com
127.0.0.1 www.kyvid.com
127.0.0.1 flow.kyvid.com
```
4.  Guarda y cierra.

### 3. Ejecutar Docker Compose

En tu terminal (dentro de la carpeta del proyecto), ejecuta:

```powershell
docker compose up -d --build
```

**¿Qué hará esto?**
1.  Descargará las imágenes de PHP, MySQL y Caddy.
2.  Construirá la imagen personalizada de Kyvid Flow.
3.  Levantará los 3 contenedores.

### 4. Verificar Instalación

Abre tu navegador e ingresa a:

*   **Software**: [https://flow.kyvid.com](https://flow.kyvid.com)
    *   Caddy generará un certificado local para HTTPS. Si el navegador te advierte sobre el certificado, acepta continuar (es normal en local).
*   **Landing Page**: [https://kyvid.com](https://kyvid.com)

---

## Solución de Problemas Comunes

**1. Puerto 80/443 ocupado:**
Si tienes XAMPP, Skype o IIS corriendo, pueden chocar con los puertos de Caddy.
*   **Solución**: Detén XAMPP/Apache o IIS antes de correr Docker.

**2. Error de conexión a Base de Datos:**
Si la app dice "Error de conexión", espera unos segundos. MySQL tarda un poco más en iniciar que la App la primera vez.

**3. Resetear todo (Borrón y cuenta nueva):**
Si quieres reiniciar la base de datos desde cero:

```powershell
docker compose down -v
docker compose up -d
```
*(El `-v` borra los volúmenes de datos, incluyendo la BD).*

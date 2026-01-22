# Guía de Instalación para Ubuntu 24.04 LTS (Kyvid Flow)

Esta guía detalla cómo desplegar **Kyvid Flow** en un servidor con Ubuntu 24.04 utilizando Docker y un Caddy Server global (Host) para gestionar múltiples servicios y certificados SSL automáticamente.

---

## 1. Preparación del Sistema

Actualiza los paquetes del sistema:

```bash
sudo apt update && sudo apt upgrade -y
```

## 2. Instalación de Docker

```bash
# Instalar dependencias
sudo apt install -y ca-certificates curl gnupg lsb-release

# Añadir llave GPG de Docker
sudo mkdir -m 0755 -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg

# Añadir repositorio
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Instalar Docker
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Verificar instalación
sudo docker --version
```

## 3. Instalación de Caddy (Proxy Inverso Global)

Instalaremos Caddy directamente en el sistema operativo para que gestione todos tus dominios (n8n, kyvid, etc.) en un solo lugar.

```bash
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update
sudo apt install caddy
```

---

## 4. Despliegue de Kyvid Flow

### A. Preparar carpeta del proyecto
Crea la carpeta donde residirá el sistema:

```bash
sudo mkdir -p /srv/kyvid-business
sudo chown $USER:$USER /srv/kyvid-business
cd /srv/kyvid-business
```

### B. Subir el código
Sube los archivos de tu proyecto siguiendo esta estructura:
- `/srv/kyvid-business/app/` (Código de la aplicación)
- `/srv/kyvid-business/landing/` (Archivos de la landing page)
- `/srv/kyvid-business/docker-compose.yml` (Archivo maestro)

### C. Configurar el Caddy del Servidor (Host)
Ahora configuramos el Caddy principal de tu servidor para que dirija el tráfico a los contenedores de Docker.

```bash
sudo nano /etc/caddy/Caddyfile
```

Añade estas configuraciones (manteniendo las que ya tengas, como n8n):

```text
# n8n (Existente)
n8n1.kyvid.com {
    reverse_proxy localhost:5678
}

# Kyvid Flow - Aplicación (Software)
flow.kyvid.com {
    reverse_proxy localhost:8080
}

# Kyvid.com - Landing Page (Marketing)
kyvid.com, www.kyvid.com {
    reverse_proxy localhost:8081
}
```

Recarga Caddy para aplicar los cambios:
```bash
sudo systemctl reload caddy
```

### D. Lanzar Contenedores
Desde `/srv/kyvid-business`, ejecuta:

```bash
docker compose up -d --build
```

---

## 5. Primer Inicio y Seguridad

1.  **Acceso**: Ingresa a `https://flow.kyvid.com` o `https://kyvid.com`.
2.  **Login de 2 Pasos (App)**:
    *   Paso 1: Ingresa el código de usuario (por defecto: `admin`).
    *   Paso 2: Ingresa la contraseña definida en `ADMIN_PASSWORD` en el `docker-compose.yml`.
3.  **Configuración Inicial**:
    *   Ve a la pestaña **Usuarios** y cambia la contraseña del administrador.

---

## 6. Mantenimiento y Comandos Útiles

- **Ver estados de los contenedores**: `docker ps`
- **Ver logs de la aplicación**: `docker logs -f kyvid_app`
- **Reiniciar todo el stack**: `docker compose restart`
- **Actualizar cambios de código**: 
  1. Sube los archivos nuevos.
  2. Ejecuta: `docker compose up -d --build`

---

## 6. Consideraciones de Seguridad (UFW)

Asegúrate de permitir el tráfico necesario en el firewall:

```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 22/tcp
sudo ufw enable
```

---

*Manual actualizado para Kyvid Flow - Multi-service Proxy Deployment.*

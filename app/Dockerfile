FROM php:8.2-apache

# Instalamos extensiones necesarias
RUN docker-php-ext-install pdo pdo_mysql

# Habilitamos mod_rewrite de Apache para URLs limpias si fuera necesario en el futuro
RUN a2enmod rewrite

# Copiamos el código fuente
COPY . /var/www/html/

# Ajustamos permisos (importante para que Apache pueda escribir si hay uploads o logs)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Exponer puerto 80
EXPOSE 80

FROM php:7.4-alpine3.13

LABEL owner="Giancarlos Salas"
LABEL maintainer="giansalex@gmail.com"

# Instalar dependencias de sistema
RUN apk update && apk add --no-cache \
    wkhtmltopdf \
    ttf-droid \
    libzip \
    git \
    unzip \
    curl \
    libxml2-dev \
    bash \
    openssl

# Instalar extensiones PHP necesarias
RUN docker-php-ext-install \
    soap \
    zip \
    opcache \
    pcntl

# Configurar opcache
COPY docker/config/opcache.ini $PHP_INI_DIR/conf.d/

# Copiar proyecto
COPY . /var/www/html

# Crear carpetas con permisos
RUN mkdir -p /var/www/html/cache /var/www/html/files && \
    chmod -R 777 /var/www/html/cache /var/www/html/files

# Instalar Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

# Instalar dependencias PHP con Composer
RUN composer install --no-interaction --no-dev -o -a

# Limpiar dependencias de build si fueran necesarias
# (en este caso ya las instalamos globalmente con apk, así que no hay virtual package que eliminar)

EXPOSE 8000

ENTRYPOINT ["php", "-S", "0.0.0.0:8000"]

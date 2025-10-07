# ----------------------------
# Dockerfile PHP 7.4 + Composer
# ----------------------------
FROM php:7.4-alpine3.13

LABEL owner="Giancarlos Salas"
LABEL maintainer="giansalex@gmail.com"

# ----------------------------
# Instalar dependencias del sistema
# ----------------------------
RUN apk update && apk add --no-cache \
    wkhtmltopdf \
    ttf-droid \
    bash \
    openssl \
    git \
    unzip \
    curl \
    libzip-dev \
    libxml2-dev \
    oniguruma-dev \
    autoconf \
    g++ \
    make

# ----------------------------
# Instalar extensiones PHP
# ----------------------------
RUN docker-php-ext-install \
    soap \
    zip \
    pcntl \
    opcache

# Copiar configuración de opcache
COPY docker/config/opcache.ini $PHP_INI_DIR/conf.d/

# ----------------------------
# Instalar Composer
# ----------------------------
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# ----------------------------
# Copiar proyecto
# ----------------------------
COPY . /var/www/html

# ----------------------------
# Crear carpetas de cache y files
# ----------------------------
RUN mkdir -p /var/www/html/cache /var/www/html/files && \
    chmod -R 777 /var/www/html/cache /var/www/html/files

WORKDIR /var/www/html

# ----------------------------
# Instalar dependencias PHP
# ----------------------------
RUN composer install --no-interaction --no-dev -o -a

# ----------------------------
# Limpiar dependencias de build para reducir tamaño
# ----------------------------
RUN apk del autoconf g++ make

# ----------------------------
# Exponer puerto y definir entrypoint
# ----------------------------
EXPOSE 8000

ENTRYPOINT ["php", "-S", "0.0.0.0:8000"]

FROM php:8.1-alpine3.17

LABEL owner="Giancarlos Salas"
LABEL maintainer="giansalex@gmail.com"

# ----------------------------
# Dependencias del sistema
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
# Extensiones PHP
# ----------------------------
RUN docker-php-ext-install \
    soap \
    zip \
    pcntl \
    opcache

# Configuración opcache
COPY docker/config/opcache.ini $PHP_INI_DIR/conf.d/

# Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Proyecto
COPY . /var/www/html

RUN mkdir -p /var/www/html/cache /var/www/html/files && \
    chmod -R 777 /var/www/html/cache /var/www/html/files

WORKDIR /var/www/html

# Dependencias PHP
RUN composer install --no-interaction --no-dev -o -a

# Limpiar dependencias de build
RUN apk del autoconf g++ make

EXPOSE 8000

ENTRYPOINT ["php", "-S", "0.0.0.0:8000"]

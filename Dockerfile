FROM php:7.4-alpine3.13
LABEL owner="Giancarlos Salas"
LABEL maintainer="giansalex@gmail.com"

# Install deps
RUN apk update && apk add --no-cache wkhtmltopdf ttf-droid libzip

# Install php dev dependencies
RUN apk add --no-cache --virtual .build-green-deps \
    git \
    unzip \
    curl \
    libxml2-dev

# Configure php extensions
RUN docker-php-ext-install soap && \
    docker-php-ext-configure opcache --enable-opcache && \
    docker-php-ext-install opcache

ENV DOCKER=1

COPY docker/config/opcache.ini $PHP_INI_DIR/conf.d/

# Instalar Composer primero
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Establecer directorio de trabajo
WORKDIR /var/www/html

# Copiar solo composer.json y composer.lock primero (para cache de Docker)
COPY composer.json composer.lock* ./

# Instalar dependencias de PHP
RUN composer install --no-interaction --no-dev --optimize-autoloader --no-scripts

# Copiar el resto de los archivos
COPY . /var/www/html/

# Generar autoloader y crear carpetas necesarias
RUN composer dump-autoload --optimize && \
    mkdir -p ./cache ./files && \
    chmod -R 777 ./cache ./files && \
    mkdir -p public/boletas/xml public/boletas/pdf public/boletas/cdr && \
    mkdir -p public/notas/xml public/notas/pdf public/notas/cdr && \
    chmod -R 777 public/

# Limpiar dependencias de build
RUN apk del .build-green-deps && \
    rm -rf /var/cache/apk/*

EXPOSE 8000

ENTRYPOINT ["php", "-S", "0.0.0.0:8000"]
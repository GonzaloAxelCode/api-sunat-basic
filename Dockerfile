# Imagen base PHP 8.2 con Alpine
FROM php:8.2-alpine

LABEL owner="Giancarlos Salas"
LABEL maintainer="giansalex@gmail.com"

# ================================
# Instalar dependencias del sistema
# ================================
RUN apk update && apk add --no-cache \
    wkhtmltopdf \
    ttf-freefont \
    fontconfig \
    libxrender \
    libxext \
    libjpeg-turbo \
    libpng \
    libstdc++ \
    ca-certificates \
    libzip \
    curl \
    git \
    unzip \
    libxml2-dev

# ================================
# Extensiones PHP necesarias
# ================================
RUN docker-php-ext-install soap && \
    docker-php-ext-configure opcache --enable-opcache && \
    docker-php-ext-install opcache zip

# ================================
# Configuración
# ================================
ENV DOCKER=1
WORKDIR /var/www/html

COPY docker/config/opcache.ini $PHP_INI_DIR/conf.d/ 2>/dev/null || true
COPY . /var/www/html/

# ================================
# Instalar Composer + dependencias
# ================================
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    mkdir -p cache files && chmod -R 777 cache files && \
    composer install --no-dev -o -a

# ================================
# Limpieza final
# ================================
RUN rm -rf /var/cache/apk/* /tmp/*

# ================================
# Exponer puerto y ejecutar PHP
# ================================
EXPOSE 8000
ENTRYPOINT ["php", "-S", "0.0.0.0:8000", "-t", "src"]

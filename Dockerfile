# Imagen base compatible con wkhtmltopdf
FROM php:8.2-alpine3.14

LABEL owner="Giancarlos Salas"
LABEL maintainer="giansalex@gmail.com"

# ===============================
# Instalar dependencias del sistema
# ===============================
RUN apk update && apk add --no-cache \
    fontconfig \
    libxrender \
    libxext \
    libjpeg-turbo \
    libzip-dev \
    ttf-freefont \
    wget && \
    # Instalar wkhtmltopdf desde binario compatible
    wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox-0.12.6-1.alpine3.14-x86_64.apk && \
    apk add --allow-untrusted wkhtmltox-0.12.6-1.alpine3.14-x86_64.apk && \
    rm wkhtmltox-0.12.6-1.alpine3.14-x86_64.apk

# ===============================
# Instalar dependencias para compilación
# ===============================
RUN apk add --no-cache --virtual .build-green-deps \
    git \
    unzip \
    curl \
    libxml2-dev

# ===============================
# Extensiones PHP
# ===============================
RUN docker-php-ext-install soap && \
    docker-php-ext-configure opcache --enable-opcache && \
    docker-php-ext-install opcache

# ===============================
# Configuración del entorno
# ===============================
ENV DOCKER=1

# Copiar configuración y código
COPY docker/config/opcache.ini $PHP_INI_DIR/conf.d/
COPY . /var/www/html/

# ===============================
# Instalar dependencias PHP (Composer)
# ===============================
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    cd /var/www/html && \
    mkdir -p cache files && chmod -R 777 cache files && \
    composer install --no-interaction --no-dev -o -a

# Limpiar dependencias de compilación
RUN apk del .build-green-deps && rm -rf /var/cache/apk/*

# ===============================
# Configuración final
# ===============================
WORKDIR /var/www/html
EXPOSE 8000
ENTRYPOINT ["php", "-S", "0.0.0.0:8000"]

FROM php:8.2.12-cli-bullseye

LABEL owner="Giancarlos Salas"
LABEL maintainer="giansalex@gmail.com"

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y --no-install-recommends \
    wget \
    fontconfig \
    libxrender1 \
    libxext6 \
    libjpeg62-turbo \
    libzip-dev \
    libxml2-dev \
    git \
    unzip \
    curl \
    && docker-php-ext-install soap \
    && docker-php-ext-configure opcache --enable-opcache \
    && docker-php-ext-install opcache

# ✅ Instalar wkhtmltopdf con Qt parchado (IMPORTANTE para tickets térmicos)
RUN wget https://github.com/wkhtmltopdf/wkhtmltopdf/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.bullseye_amd64.deb \
    && apt-get install -y ./wkhtmltox_0.12.6-1.bullseye_amd64.deb \
    && rm ./wkhtmltox_0.12.6-1.bullseye_amd64.deb \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

ENV DOCKER=1

# Copiar archivos de configuración
COPY docker/config/opcache.ini $PHP_INI_DIR/conf.d/

# Copiar proyecto
COPY . /var/www/html/

# Instalar composer y dependencias del proyecto
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    cd /var/www/html && \
    mkdir -p cache files && chmod -R 777 cache files && \
    composer install --no-interaction --no-dev -o -a --ignore-platform-reqs

WORKDIR /var/www/html

EXPOSE 8000

ENTRYPOINT ["php", "-S", "0.0.0.0:8000"]

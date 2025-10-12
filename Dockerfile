FROM php:8.2-cli-bullseye

LABEL owner="Giancarlos Salas"
LABEL maintainer="giansalex@gmail.com"

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y --no-install-recommends \
    wkhtmltopdf \
    fontconfig \
    libxrender1 \
    libxext6 \
    libjpeg62-turbo \
    libzip-dev \
    git \
    unzip \
    curl \
    && docker-php-ext-install soap \
    && docker-php-ext-configure opcache --enable-opcache \
    && docker-php-ext-install opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

ENV DOCKER=1

# Copiar archivos
COPY docker/config/opcache.ini $PHP_INI_DIR/conf.d/
COPY . /var/www/html/

# Instalar composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    cd /var/www/html && \
    mkdir -p cache files && chmod -R 777 cache files && \
    composer install --no-interaction --no-dev -o -a

WORKDIR /var/www/html

EXPOSE 8000

ENTRYPOINT ["php", "-S", "0.0.0.0:8000"]

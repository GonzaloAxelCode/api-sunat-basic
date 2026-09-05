FROM php:8.2.12-cli-bullseye
LABEL owner="Giancarlos Salas"
LABEL maintainer="giansalex@gmail.com"
# ============================================================
# Fix: Debian 11 (bullseye) salió de soporte estándar y los
# mirrors deb.debian.org ya no tienen paquetes viejos como
# libglx-mesa0 (dependencia de chromium). Apuntamos a archive.debian.org
# ============================================================
RUN sed -i '/debian-security/d' /etc/apt/sources.list \
    && sed -i 's|deb.debian.org/debian|archive.debian.org/debian|g' /etc/apt/sources.list \
    && sed -i '/bullseye-updates/d' /etc/apt/sources.list \
    && apt-get update -o Acquire::Check-Valid-Until=false
# ============================================================
# Dependencias del sistema
# ============================================================
RUN apt-get install -y --no-install-recommends \
    chromium \
    ca-certificates \
    fonts-liberation \
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
    && docker-php-ext-install opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
# ============================================================
# Node.js + npm
# ============================================================
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get update -o Acquire::Check-Valid-Until=false \
    && apt-get install -y --no-install-recommends nodejs \
    && npm --version \
    && node --version \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
# ============================================================
# Variables
# ============================================================
ENV DOCKER=1
ENV PUPPETEER_SKIP_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
# ============================================================
# Composer
# ============================================================
COPY composer.json composer.lock /var/www/html/
RUN curl -sS https://getcomposer.org/installer | php \
    -- --install-dir=/usr/local/bin \
    --filename=composer
RUN cd /var/www/html \
    && mkdir -p cache files \
    && chmod -R 777 cache files \
    && composer install \
        --no-interaction \
        --no-dev \
        -o \
        -a \
        --ignore-platform-reqs \
        --no-scripts
# ============================================================
# PHP configuration
# ============================================================
COPY docker/config/opcache.ini $PHP_INI_DIR/conf.d/
# ============================================================
# Código
# ============================================================
COPY . /var/www/html/
# ============================================================
# Autoload
# ============================================================
RUN cd /var/www/html \
    && composer dump-autoload --optimize --no-dev
# ============================================================
# Post install
# ============================================================
RUN cd /var/www/html \
    && composer run-script post-install-cmd --no-interaction
# ============================================================
# Directorio de trabajo
# ============================================================
WORKDIR /var/www/html
EXPOSE 8000
ENTRYPOINT ["php", "-S", "0.0.0.0:8000"]
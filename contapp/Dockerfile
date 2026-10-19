FROM php:8.5-fpm

# openssl: las llaves criptográficas del ATV suelen venir cifradas con RC2-40,
# que OpenSSL 3 ya no abre desde PHP; CertificateInspector las abre con
# `openssl pkcs12 -legacy` al cargarlas (CLAUDE.md secc. 38).
RUN apt-get update && apt-get install -y \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libicu-dev \
        default-mysql-client \
        openssl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader

COPY . .

RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod +x docker/php/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker/php/entrypoint.sh"]
CMD ["php-fpm"]

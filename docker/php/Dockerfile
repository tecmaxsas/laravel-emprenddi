FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    icu-dev \
    nodejs \
    npm \
    git \
    unzip \
    curl \
    bash \
    linux-headers \
    $PHPIZE_DEPS

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        zip \
        gd \
        mbstring \
        intl \
        bcmath \
        opcache \
        pcntl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

FROM base AS development
RUN pecl install xdebug && docker-php-ext-enable xdebug
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/xdebug.ini /usr/local/etc/php/conf.d/xdebug.ini
CMD ["php-fpm"]

FROM base AS production
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/custom.ini
COPY . /var/www
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && npm ci && npm run build \
    && chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
CMD ["php-fpm"]

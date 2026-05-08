FROM php:8.3-bookworm

RUN apt-get update && apt-get install -y \
    git curl unzip zip \
    libicu-dev libzip-dev \
    && docker-php-ext-install intl pdo_mysql zip bcmath sockets opcache

COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
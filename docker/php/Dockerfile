FROM php:8.4-fpm

# Устанавливаем системные зависимости
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    ffmpeg \
    && docker-php-ext-install pdo pdo_mysql
# Копируем сертификат в контейнер
COPY docker/nginx/server.crt /usr/local/share/ca-certificates/mailcert.crt
RUN update-ca-certificates
# Устанавливаем composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app/backend

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["entrypoint.sh"]
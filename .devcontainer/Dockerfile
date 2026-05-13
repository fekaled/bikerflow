FROM php:8.4-fpm-alpine

WORKDIR /workspaces/bikerflow

RUN apk add --no-cache \
    git curl libpng-dev libxml2-dev zip unzip nodejs npm mysql-client bash bc

RUN docker-php-ext-install pdo_mysql bcmath gd

RUN sed -i 's/^www-data:x:82:82/www-data:x:1000:1000/' /etc/group \
    && sed -i 's/^www-data:x:82:1000/www-data:x:1000:1000/' /etc/passwd

RUN chown -R www-data:www-data /workspaces/bikerflow

USER www-data
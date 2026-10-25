FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
    && a2enmod headers rewrite \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/offbeat.ini
WORKDIR /var/www/html
COPY . .
RUN mkdir -p public/uploads storage && chown -R www-data:www-data public/uploads storage


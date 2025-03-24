FROM php:7.4-cli-alpine AS composer

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Install required dependencies
RUN apk add --no-cache icu-dev git zip unzip \
    && docker-php-ext-install intl

WORKDIR /var/www/html
COPY . /var/www/html
RUN git config --global --add safe.directory /var/www/html
RUN composer install --no-dev --optimize-autoloader

FROM php:7.4-apache
RUN apt update
RUN apt install -y libicu-dev libsodium-dev git unzip libzip-dev libxml2-dev zlib1g-dev wget
RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN docker-php-ext-configure intl && docker-php-ext-install intl

RUN docker-php-ext-enable mysqli intl pdo_mysql sodium
RUN docker-php-ext-install zip
RUN docker-php-ext-install soap
RUN pecl install xdebug-2.9.8 && docker-php-ext-enable xdebug && echo "xdebug.remote_enable=on" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
&& echo "xdebug.remote_host = host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && echo "xdebug.remote_port = 9000" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
&& echo "xdebug.remote_autostart = on" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && echo "xdebug.idekey = PHPSTORM" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
&& echo "xdebug.remote_connect_back = 0" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini && echo "xdebug.remote_log = /tmp/xdebug.log" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini





##Composer install
#RUN curl -sS https://getcomposer.org/installer -o composer-setup.php && \
#    php composer-setup.php --install-dir=/usr/bin --filename=composer && \
#    rm composer-setup.php && \
#    ls -l /usr/bin/composer && \
#    chmod +x /usr/bin/composer

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

ENV COMPOSER_HOME=/composer

ENV PATH=$PATH:/composer/vendor/bin

WORKDIR /var/www/html/

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

COPY . /var/www/html
RUN cd /var/www/html/ && composer install

#COPY install/. /var/www/html

RUN chown -R www-data:www-data /var/www/html/*

RUN cd /var/www/html \
    && chmod -R u+w data/cache/ \
    && chmod -R u+w data/log/ \
    && chmod -R u+w data/session/ \
    && chmod -R u+w public/docs-client/upload/ \
    && chmod -R u+w public/imgs-client/upload/

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini && sed -i -e "s/^ *memory_limit.*/memory_limit = 4G/g" /usr/local/etc/php/php.ini

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN a2enmod rewrite


FROM php:8.4-apache

ARG INSTALL_XDEBUG=false

RUN apt update && apt install -y \
    libicu-dev libsodium-dev git unzip libzip-dev libxml2-dev zlib1g-dev wget \
    ca-certificates && update-ca-certificates

RUN docker-php-ext-install mysqli pdo pdo_mysql \
    && docker-php-ext-configure intl && docker-php-ext-install intl \
    && docker-php-ext-install zip soap \
    && docker-php-ext-enable mysqli intl pdo_mysql sodium

# Xdebug only when INSTALL_XDEBUG=true (dev) — installed from source tarball (PECL SSL broken on older images)
RUN if [ "$INSTALL_XDEBUG" = "true" ]; then \
    cd /tmp \
    && wget --no-check-certificate https://xdebug.org/files/xdebug-3.4.2.tgz \
    && tar -xzf xdebug-3.4.2.tgz \
    && cd xdebug-3.4.2 \
    && phpize && ./configure && make && make install \
    && docker-php-ext-enable xdebug \
    && rm -rf /tmp/xdebug-3.4.2 /tmp/xdebug-3.4.2.tgz \
    && echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.idekey=PHPSTORM" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.discover_client_host=0" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.log=/tmp/xdebug.log" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    ; fi

COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_HOME=/composer
ENV PATH=$PATH:/composer/vendor/bin

WORKDIR /var/www/html/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

COPY . /var/www/html
RUN git config --global --add safe.directory /var/www/html

# Composer dependencies: managed via volume mount at runtime (docker compose exec court composer update)
# The volume mount ./:/var/www/html overrides COPY, so vendor/ from host is used directly.

RUN chown -R www-data:www-data /var/www/html/*

RUN cd /var/www/html \
    && chmod -R u+w data/cache/ \
    && chmod -R u+w data/log/ \
    && chmod -R u+w data/session/ \
    && chmod -R u+w public/docs-client/upload/ \
    && chmod -R u+w public/imgs-client/upload/

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && sed -i -e "s/^ *memory_limit.*/memory_limit = 256M/g" /usr/local/etc/php/php.ini \
    && sed -i -e "s/^ *expose_php.*/expose_php = Off/g" /usr/local/etc/php/php.ini

# Apache security: hide server version, enable mod_headers for security headers
RUN echo "ServerTokens Prod" >> /etc/apache2/conf-available/security.conf \
    && echo "ServerSignature Off" >> /etc/apache2/conf-available/security.conf \
    && a2enconf security

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Enable mod_remoteip to log real client IPs behind Traefik reverse proxy
RUN echo "RemoteIPHeader X-Forwarded-For" >> /etc/apache2/conf-available/remoteip.conf \
    && echo "RemoteIPInternalProxy 172.16.0.0/12" >> /etc/apache2/conf-available/remoteip.conf \
    && echo "RemoteIPInternalProxy 10.0.0.0/8" >> /etc/apache2/conf-available/remoteip.conf \
    && echo "RemoteIPInternalProxy 192.168.0.0/16" >> /etc/apache2/conf-available/remoteip.conf \
    && a2enconf remoteip

RUN a2enmod rewrite headers remoteip

# Use %a (mod_remoteip resolved IP) instead of %h (connection IP) in Apache log format
RUN sed -i 's/%h/%a/g' /etc/apache2/apache2.conf
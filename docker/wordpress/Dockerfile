FROM wordpress:cli-php8.2 AS wp-cli

FROM wordpress:php8.2-apache

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends msmtp msmtp-mta ca-certificates mariadb-client; \
    rm -rf /var/lib/apt/lists/*; \
    pecl install xdebug; \
    docker-php-ext-enable xdebug

COPY msmtprc /etc/msmtprc
COPY --from=wp-cli /usr/local/bin/wp /usr/local/bin/wp
COPY conf.d/xdebug.ini /usr/local/etc/php/conf.d/99-xdebug-settings.ini
COPY conf.d/mail.ini /usr/local/etc/php/conf.d/98-mailpit.ini
COPY docker-entrypoint.sh /usr/local/bin/development-assistant-wordpress-entrypoint.sh

RUN chmod +x /usr/local/bin/development-assistant-wordpress-entrypoint.sh

ENTRYPOINT ["development-assistant-wordpress-entrypoint.sh"]
CMD ["apache2-foreground"]

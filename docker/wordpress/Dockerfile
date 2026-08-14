FROM wordpress:cli-php8.2 AS wp-cli

FROM wordpress:php8.2-apache

ENV APACHE_DOCUMENT_ROOT=/app/wp

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends msmtp msmtp-mta ca-certificates mariadb-client; \
    rm -rf /var/lib/apt/lists/*; \
    pecl install xdebug; \
    docker-php-ext-enable xdebug; \
    sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

COPY msmtprc /etc/msmtprc
COPY --from=wp-cli /usr/local/bin/wp /usr/local/bin/wp
COPY conf.d/xdebug.ini /usr/local/etc/php/conf.d/99-xdebug-settings.ini
COPY conf.d/mail.ini /usr/local/etc/php/conf.d/98-mailpit.ini
COPY apache/development-assistant.conf /etc/apache2/conf-available/development-assistant.conf
COPY docker-entrypoint.sh /usr/local/bin/development-assistant-wordpress-entrypoint.sh

RUN a2enconf development-assistant \
	&& chmod +x /usr/local/bin/development-assistant-wordpress-entrypoint.sh

WORKDIR /app/wp

ENTRYPOINT ["development-assistant-wordpress-entrypoint.sh"]
CMD ["apache2-foreground"]

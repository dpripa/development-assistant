FROM wordpress:cli-php8.2

USER root

ENV WP_CLI_PACKAGES_DIR=/usr/local/share/wp-cli/packages

RUN apk add --no-cache zip \
	&& mkdir -p "$WP_CLI_PACKAGES_DIR" \
	&& chown -R www-data:www-data "$WP_CLI_PACKAGES_DIR"

USER www-data

RUN wp package install wp-cli/dist-archive-command:3.1.0

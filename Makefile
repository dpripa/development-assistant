COMPOSE ?= docker compose
PLUGIN_CONTAINER_DIR := /var/www/html/wp-content/plugins/development-assistant
RELEASE_ARCHIVE := release/development-assistant.zip

.PHONY: init setup env dependencies composer-install node-install assets up down restart reinit cert trust-cert wp-core wp-core-update logs ps shell wp db-shell db-backup db-restore db-backups sync-env xdebug-on xdebug-off xdebug-status composer-update start-watch build-src dist-archive create-release-zip fix lint prepare-to-release

init: setup

setup: env dependencies node-install build-src cert wp-core

env:
	@if [ ! -f .env ]; then cp .env.example .env; echo "Created .env from .env.example."; fi

dependencies: composer-install

composer-install:
	$(COMPOSE) --profile tools run --rm --no-deps composer install

node-install:
	NVM_DIR="$${HOME}/.nvm" && . "$${NVM_DIR}/nvm.sh" && nvm use && npm install

assets:
	@if [ ! -d node_modules ]; then $(MAKE) node-install; fi
	@if [ ! -d assets ]; then $(MAKE) build-src; fi

up: env cert wp-core composer-install assets
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

restart: env cert wp-core composer-install assets
	$(COMPOSE) up -d --build --force-recreate

reinit: env cert wp-core composer-install assets
	./scripts/db-backup.sh before-reinit
	$(COMPOSE) down --remove-orphans
	@project="$${COMPOSE_PROJECT_NAME:-$$(basename "$$(pwd)")}" ; \
	volume="$$(docker volume ls -q --filter label=com.docker.compose.project=$$project --filter label=com.docker.compose.volume=db_data)" ; \
	if [ -z "$$volume" ] && docker volume inspect "$${project}_db_data" >/dev/null 2>&1; then \
		volume="$${project}_db_data" ; \
	fi ; \
	if [ -n "$$volume" ]; then \
		echo "Removing database volume: $$volume" ; \
		docker volume rm $$volume ; \
	else \
		echo "Database volume db_data does not exist yet." ; \
	fi
	$(COMPOSE) up -d --build --force-recreate

cert:
	./scripts/generate-local-cert.sh docker/certs

trust-cert:
	mkcert -install

wp-core:
	@if [ -f wp/wp-includes/version.php ]; then echo "WordPress core mirror is already initialized."; else ./scripts/update-wp-core.sh wp; fi

wp-core-update:
	./scripts/update-wp-core.sh wp

logs:
	$(COMPOSE) logs -f

ps:
	$(COMPOSE) ps

shell:
	$(COMPOSE) exec wordpress bash

wp:
	$(COMPOSE) run --rm wp-cli $(cmd)

db-shell:
	$(COMPOSE) exec db sh -lc 'mariadb -u root -p"$${MARIADB_ROOT_PASSWORD}"'

db-backup:
	./scripts/db-backup.sh "$(label)"

db-restore:
	@if [ -z "$(file)" ]; then echo "Usage: make db-restore file=backups/db/YYYY-MM-DD-HHMMSS-db.sql.gz"; exit 1; fi
	./scripts/db-restore.sh "$(file)"

db-backups:
	@find backups/db -maxdepth 1 -type f \( -name '*.sql' -o -name '*.sql.gz' \) | sort

sync-env:
	$(COMPOSE) run --rm wp-cli sh /usr/local/bin/sync-wp-env.sh

xdebug-on:
	./scripts/set-xdebug-mode.sh debug,develop
	$(COMPOSE) up -d --build wordpress

xdebug-off:
	./scripts/set-xdebug-mode.sh off
	$(COMPOSE) up -d --build wordpress

xdebug-status:
	@grep -E '^XDEBUG_MODE=' .env 2>/dev/null || echo 'XDEBUG_MODE is not set; default is off.'
	@$(COMPOSE) exec wordpress php -i | grep -E 'xdebug.mode|xdebug.start_with_request|xdebug.client_host|xdebug.client_port' || true

composer-update:
	$(COMPOSE) --profile tools run --rm --no-deps composer update

start-watch:
	NVM_DIR="$${HOME}/.nvm" && . "$${NVM_DIR}/nvm.sh" && nvm use && npm run start

build-src:
	NVM_DIR="$${HOME}/.nvm" && . "$${NVM_DIR}/nvm.sh" && nvm use && npm run build

dist-archive:
	$(RM) "$(RELEASE_ARCHIVE)"
	$(COMPOSE) --profile tools run --rm --no-deps --build wp-cli \
		dist-archive "$(PLUGIN_CONTAINER_DIR)" "$(PLUGIN_CONTAINER_DIR)/$(RELEASE_ARCHIVE)" \
		--create-target-dir \
		--force \
		--plugin-dirname=development-assistant

create-release-zip: composer-install lint build-src
	./scripts/with-production-dependencies.sh $(MAKE) dist-archive

fix:
	./scripts/run-composer.sh run fix && npm run fix-style && npm run fix-script

lint:
	./scripts/run-composer.sh run lint && npm run lint-style && npm run lint-script

prepare-to-release: lint build-src

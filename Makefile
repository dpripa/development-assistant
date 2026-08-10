init:
	composer install && \
	sh -l ./.script/install-wp-tests.sh && \
	NVM_DIR="$${HOME}/.nvm" && . "$${NVM_DIR}/nvm.sh" && nvm use && \
	npm install && npm run build

composer-update:
	composer update

start-watch:
	NVM_DIR="$${HOME}/.nvm" && . "$${NVM_DIR}/nvm.sh" && nvm use && \
 	npm run start

build-src:
	NVM_DIR="$${HOME}/.nvm" && . "$${NVM_DIR}/nvm.sh" && nvm use && \
 	npm run build

create-release-zip:
	composer update && \
	make lint && \
	make tests && \
	make build-src && \
	npm run create-release-zip

deploy-to-dev:
	composer update && \
	make lint && \
	make tests && \
	make build-src && \
	npm run deploy-to-dev

tests:
	composer run tests

fix:
	composer run fix && npm run fix-style && npm run fix-script

lint:
	composer run lint && npm run lint-style && npm run lint-script

prepare-to-release:
	make lint && npm run build

.DEFAULT_GOAL := lint

TOOL_IMAGE := notfive:dependencies
TOOL_RUN := docker run --rm --user $(shell id -u):$(shell id -g) -v $(CURDIR):/app -w /app
PHP_TOOL := $(TOOL_RUN) --entrypoint php $(TOOL_IMAGE)
COMPOSER_TOOL := $(TOOL_RUN) -e COMPOSER_HOME=/tmp/composer --entrypoint composer $(TOOL_IMAGE)
BASE ?= origin/master
CHANGED_PHP_FILES := $(filter %.php,$(sort $(shell { git diff --name-only --diff-filter=ACMRT $(BASE)...HEAD; git diff --name-only --diff-filter=ACMRT; git diff --cached --name-only --diff-filter=ACMRT; git ls-files --others --exclude-standard; } 2>/dev/null)))

.PHONY: tooling lint lint-all phpstan phpstan-all php-cs php-cs-all php-cs-fix php-cs-fix-all rector rector-all rector-fix rector-fix-all
.NOTPARALLEL:

tooling:
	docker build --target dependencies -t $(TOOL_IMAGE) .
	$(COMPOSER_TOOL) install --no-scripts --no-interaction --prefer-dist --no-progress

lint: phpstan php-cs rector

lint-all: phpstan-all php-cs-all rector-all

phpstan: tooling
	@if [ -n "$(CHANGED_PHP_FILES)" ]; then $(PHP_TOOL) vendor/bin/phpstan analyse -c phpstan.neon.dist $(CHANGED_PHP_FILES); else echo 'Нет изменённых PHP-файлов'; fi

phpstan-all: tooling
	$(PHP_TOOL) vendor/bin/phpstan analyse -c phpstan.neon.dist

php-cs: tooling
	@if [ -n "$(CHANGED_PHP_FILES)" ]; then $(PHP_TOOL) vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php --using-cache=no --path-mode=intersection -- $(CHANGED_PHP_FILES); else echo 'Нет изменённых PHP-файлов'; fi

php-cs-all: tooling
	$(PHP_TOOL) vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php --using-cache=no

php-cs-fix: tooling
	@if [ -n "$(CHANGED_PHP_FILES)" ]; then $(PHP_TOOL) vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --path-mode=intersection -- $(CHANGED_PHP_FILES); else echo 'Нет изменённых PHP-файлов'; fi

php-cs-fix-all: tooling
	$(PHP_TOOL) vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php

rector: tooling
	@if [ -n "$(CHANGED_PHP_FILES)" ]; then $(PHP_TOOL) vendor/bin/rector process --dry-run $(CHANGED_PHP_FILES); else echo 'Нет изменённых PHP-файлов'; fi

rector-all: tooling
	$(PHP_TOOL) vendor/bin/rector process --dry-run

rector-fix: tooling
	@if [ -n "$(CHANGED_PHP_FILES)" ]; then $(PHP_TOOL) vendor/bin/rector process $(CHANGED_PHP_FILES); else echo 'Нет изменённых PHP-файлов'; fi

rector-fix-all: tooling
	$(PHP_TOOL) vendor/bin/rector process

.PHONY: eps test codestyle fix-codestyle help
.DEFAULT_GOAL:=help

export PHP_VERSION ?= 8

mkfile_dir := $(dir $(abspath $(firstword $(MAKEFILE_LIST))))

deps:
	composer install --prefer-dist --no-plugins --no-scripts

tests_output = ./test-results
tests_coverage = ${tests_output}/coverage.xml
tests_report = ${tests_output}/report.xml
test: deps	## Run the testsuite
	rm -rf ${tests_output}/*
	mkdir -p ${tests_output}
	XDEBUG_MODE=coverage vendor/bin/phpunit \
		--colors=always \
		--coverage-text \
		--coverage-cobertura ${tests_coverage} \
		--log-junit ${tests_report}

codestyle: deps	## Show PHP-Codestxyle issues
	vendor/bin/php-cs-fixer fix \
		--dry-run \
		--diff \
		--format=junit \
		--show-progress=none

fix-codestyle: deps	## Show PHP-Codestxyle issues
	vendor/bin/php-cs-fixer fix \
		--diff \
		--format=junit \
		--show-progress=none

help:	## Display this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'

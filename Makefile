.PHONY: test stan cs cs-fix check

test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan analyse --no-progress

cs:
	vendor/bin/php-cs-fixer check --diff

cs-fix:
	vendor/bin/php-cs-fixer fix

check: cs stan test

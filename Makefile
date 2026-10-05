# The storage tests run when these are set; make databases-up starts the databases they point at.
export LEDGER_PG_DSN ?= pgsql:host=127.0.0.1;port=55435;dbname=ledger;user=ledger;password=ledger
# Percona Server is MySQL 8.4 that starts reliably on arm64 Docker; CI tests the official
# mysql:8.4 image on amd64.
MYSQL_IMAGE ?= percona/percona-server:8.4
export LEDGER_MYSQL_DSN ?= mysql:host=127.0.0.1;port=33308;dbname=ledger;user=root;password=root

.PHONY: test stan cs cs-fix check databases-up databases-down

test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan analyse --no-progress

cs:
	vendor/bin/php-cs-fixer check --diff

cs-fix:
	vendor/bin/php-cs-fixer fix

check: cs stan test

databases-up:
	docker run -d --rm --name ledger-pg -e POSTGRES_USER=ledger -e POSTGRES_PASSWORD=ledger -e POSTGRES_DB=ledger -p 55435:5432 postgres:17-alpine
	docker run -d --rm --name ledger-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=ledger -p 33308:3306 $(MYSQL_IMAGE)
	for i in $$(seq 1 60); do docker exec ledger-pg pg_isready -U ledger >/dev/null 2>&1 && break; sleep 1; done
	for i in $$(seq 1 120); do docker exec ledger-mysql mysql -h127.0.0.1 -uroot -proot -e 'select 1' ledger >/dev/null 2>&1 && exit 0; sleep 1; done; docker logs ledger-mysql; exit 1

databases-down:
	docker rm -f ledger-pg ledger-mysql

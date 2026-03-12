.PHONY: install test lint clean

install:
	composer install

test:
	vendor/bin/phpunit

lint:
	vendor/bin/phpstan analyse

clean:
	rm -rf vendor composer.lock

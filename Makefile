.PHONY: up down restart bash artisan migrate fresh seed test npm-dev build logs tinker composer install ps

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

bash:
	docker compose exec app bash

artisan:
	docker compose exec app php artisan $(filter-out $@,$(MAKECMDGOALS))

composer:
	docker compose exec app composer $(filter-out $@,$(MAKECMDGOALS))

install:
	docker compose exec app composer install
	docker compose exec app npm install

migrate:
	docker compose exec app php artisan migrate

fresh:
	docker compose exec app php artisan migrate:fresh --seed

seed:
	docker compose exec app php artisan db:seed

test:
	docker compose exec app php artisan test

npm-dev:
	docker compose exec app npm run dev

build:
	docker compose exec app npm run build

logs:
	docker compose logs -f app

ps:
	docker compose ps

tinker:
	docker compose exec app php artisan tinker

%:
	@:

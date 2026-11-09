# phpMyAdmin Sentry instance

## Required

- `docker`
- `docker compose`

## Initialization

Copy `.env.dist` to `.env.`, fill the values.

Run `docker compose exec sentry sentry upgrade` to apply the migrations.

The CLI will ask if you want to create an user, say "Y" and fill the email and password.

If you missed your chance, use this command to have another one: `docker compose exec sentry sentry createuser`

Run `docker compose exec sentry pip install sentry-slack` to install the Sentry Slack integration.
Run `docker compose exec sentry pip install sentry-github` to install the Sentry GitHub integration.

Login into http://localhost:9000/ or something similar.

## Start it

Run `docker compose up` or `docker compose up -d` for detached mode.

This command is a shortcut to docker compose with the right project name.

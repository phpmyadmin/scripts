# phpMyAdmin Sentry instance

## Required

- `docker`
- `docker-compose`

## Initialization

Copy `.env.dist` to `.env.`, fill the values.

Run `./dockerl exec sentry sentry upgrade` to apply the migrations.

The CLI will ask if you want to create an user, say "Y" and fill the email and password.

If you missed your chance, use this command to have another one: `./dockerl exec sentry sentry createuser`

Run `./dockerl exec sentry pip install sentry-slack` to install the Sentry Slack integration.
Run `./dockerl exec sentry pip install sentry-github` to install the Sentry GitHub integration.

Login into http://localhost:9000/ or something similar.

## Start it

Run `./dockerl up` or `./dockerl up -d` for detached mode.

This command is a shortcut to docker compose with the right project name.

# phpMyAdmin error tracking

Sentry-SDK-compatible error tracking on PostgreSQL. Migration steps: [MIGRATION.md](MIGRATION.md).

| Service    | Role                                 | Published        |
|------------|--------------------------------------|------------------|
| `postgres` | database                             | —                |
| `sentry`   | API + dashboard + ingest             | `${SERVER_PORT}` |
| `smtp`     | exim relay, for alert email          | —                |
| `init`     | one-shot, fixes bind-mount ownership | —                |

`postgres` and `sentry` have no route out and resolve no external name; `smtp` is the only container with one, for mail delivery.

## Images

Pinned by tag and digest in `compose.yml`, where each digest has a comment with the tag it resolves and the date. Bump both together; check a digest against its tag with `docker buildx imagetools inspect postgres:16-alpine`.

## Data directories

Two bind mounts, each owned by the user its container runs as. Rootless Docker remaps uids, so the ownership is set from a container, through the `init` profile (not started by `up`):

```sh
mkdir -p ../../../error-tracking-pg-data ../../../error-tracking-data
docker compose --profile init run --rm init
```

## Start

```sh
cp .env.dist .env     # fill in, see the file
docker compose up -d  # migrations run at startup
```

Admin: `CREATE_SUPERUSER="email:password"` in `.env` before the first start, or create it in the dashboard. Dashboard on `/`, API under `/api/`, health at `/health` and `/health/ready`.

## nginx

See `MIGRATION.md` §3: add `client_max_body_size` and the plain-HTTP redirect block; `X-Forwarded-Proto` is for the proxy logs, the server does not read it. The session cookie is `Secure` only when `COOKIE_SESSION_SECURE` is true.

## Projects

Projects → New. The DSN is `https://<key>@<host>/<id>`, where `<host>` is the hostname in `PUBLIC_URL`; ingestion is `POST /api/<id>/envelope/`.

Both the legacy `POST /api/<id>/store/` and the envelope endpoint are accepted since 0.26, so the error-reporting server forwards without code changes. `/api/embed/error-page/` is still 404: feedback goes in a `user_report` envelope item, or through `POST /api/0/projects/<org>/<project>/user-feedback/`.

## Email

Alerts and invitations go through the local `smtp` relay: `smtp:25`, no TLS, on the internal `mail` network. Both `SENTRY_SERVER_EMAIL` (the sender) and `SMTP_HELO_HOSTNAME` (this host's FQDN) are required; compose refuses to start without them.

The queue is a tmpfs, so a message queued while delivery is failing is lost on restart. Add a named volume on `/var/spool/exim4` if that matters. The relay is a plain relay on a trusted network — it accepts mail from anything on `mail`, and only that network.

## Turning products off (crons, agents, logs…)

There is **no environment variable and no server-wide switch**: products are a per-project setting, `disabled_products`, editable in **Settings → Data → Products** or by API. Values: `feedback`, `releases`, `performance`, `agents`, `logs`, `crons`. `issues` cannot be turned off (400), and turning `performance` off turns `agents` off too.

`CreateProject` does not accept the field, so a new project has everything on until it is patched:

```sh
curl -fsS -X PATCH "https://$HOST/api/projects/<id>" \
  -H "Authorization: Bearer <token>" \
  -H 'Content-Type: application/json' \
  -d '{"disabled_products":["crons","agents","logs"]}'
```

What turning one off does, verified on the running stack:

| Product off   | Ingest behaviour                                              |
|---------------|---------------------------------------------------------------|
| `crons`       | check-ins answer `202` and store nothing; no monitor is created |
| `agents`      | transaction still stored, its AI spans dropped                |
| `performance` | whole transactions and their spans dropped                    |
| `logs`        | log items dropped                                             |

Dropped data is acknowledged like filtered data (the SDK does not retry), and what was already stored stays until retention removes it.

## Security

Verified on the running stack:

- Rootless Docker; non-root users (`70`, `65532`); `cap_drop: ALL`; `no-new-privileges`; read-only rootfs; `noexec,nosuid` tmpfs; mem/CPU/pids limits; digests pinned.
- All four networks are plain bridges; `db`, `mail` and `front` disable masquerading, so outbound packets keep their private source address and get no answer. `postgres` and `sentry` also set `dns: 127.0.0.1`, which stops Docker's resolver forwarding external names upstream; peer names still resolve.
- `egress` is the only network with NAT, and `smtp` is its only member. The relay keeps the default resolver, because MX lookups need it.
- The consequence of using bridges rather than `internal: true`: a sealed container still has a default route to the host, so its packets are emitted and then dropped for want of NAT. Add `internal: true` to `db` and `mail` if you want no route at all; the cost is that it cannot be mixed with a published port on the same network.
- `sentry` publishes the only port. Telemetry, `DO_NOT_TRACK` and metrics off; `/metrics` returns 404.
- `postgres` runs without `chown`/`fowner`/`dac_override`: the bind mount is pre-chowned by the `init` profile instead.

## Backup

```sh
docker compose exec -T postgres pg_dump -U sentry sentry | gzip > sentry-tracking-$(date +%F).sql.gz
```

Set `SENTRY_DATA_VOLUME` aside too (source maps). Restore with `psql -U sentry sentry < dump.sql`.

## Upgrade

Change the tag and digest of `sentry`, update the date in `compose.yml`, then `docker compose up -d`. Dump first. Migrations run at startup; 0.25 → 0.26 was tested in place on an existing cluster.

Breaking in 0.26:

- Sessions moved to the database, so everyone signs in once more.
- `GET /api/projects` answers `{"items": […]}` instead of a bare array; anything parsing the list needs updating.
- `/auth/register` is gone, and any idempotent client that read it needs a different check.
- `per_page` above 100 answers 400.
- Webhook and Slack alerts no longer reach private addresses, and proxy variables are ignored. Set `RUSTRAK_ALERTS_ALLOW_PRIVATE_NETWORKS=on` only if an alert target is internal. Email through `smtp` is unaffected.

## Sentry → now

| Sentry 9.1.2                        | Now                        |
|-------------------------------------|----------------------------|
| `sentry`, `cron`, `worker`, `redis` | one process                |
| `smtp` (exim)                       | kept                       |
| `POST /api/<id>/store/`             | `POST /api/<id>/envelope/` |
| `sentry-pg-data`                    | new cluster, no import     |

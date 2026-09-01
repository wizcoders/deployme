# wizcoders/deployme

Gitea push-to-deploy webhook for Laravel. One endpoint, two independent guards.

## Install

The package lives in this repository under `packages/wizcoders/deployme` and is wired
in through a Composer path repository, so it is already required by the root
`composer.json`. After pulling it for the first time:

```bash
composer update wizcoders/deployme
```

Laravel auto-discovers `DeploymeServiceProvider`; there is nothing to register by hand.

To publish the config for per-site overrides:

```bash
php artisan vendor:publish --tag=deployme-config
```

## Configure

Everything is per-site and belongs in that site's `.env`, never in the repo:

```
DEPLOY_WEBHOOK_TOKEN=      # the Authorization header value Gitea sends
DEPLOY_WEBHOOK_SECRET=     # the Gitea webhook Secret, used for the HMAC
DEPLOY_BRANCH=             # blank = deploy whatever branch is checked out
DEPLOY_REMOTE=origin
DEPLOY_PHP_BIN=php
DEPLOY_COMPOSER_BIN=composer
DEPLOY_TIMEOUT=600
DEPLOY_LOGS_DIRECTORY_PATH="../../deploylogs/"
```

**Both secrets are required.** An empty token or secret means "not set up", and the
endpoint answers 404. Set them *before* pointing a webhook at the site.

## Point Gitea at it

Repository → Settings → Webhooks → Gitea:

| Field | Value |
|---|---|
| Target URL | `https://<site>/api/deploy/gitea` |
| HTTP Method | `POST` |
| POST Content Type | `application/json` |
| Secret | `DEPLOY_WEBHOOK_SECRET` |
| Authorization Header | `DEPLOY_WEBHOOK_TOKEN` |
| Trigger | Push events |

## How the guard works

Two checks, both required, both constant-time:

1. **`Authorization` header** against `deployme.token` — proves who is calling.
2. **`X-Gitea-Signature`** against `hash_hmac('sha256', <raw body>, deployme.secret)`
   — proves the payload was not rewritten in transit.

Either one alone is not enough. Every rejection is a bare `404` so the endpoint does
not advertise itself to a scanner, and the route is rate limited.

## Responses

| Status | Meaning |
|---|---|
| `200 deployed` | All four steps ran and succeeded. |
| `200 ignored` | Authentic call, but not for this site — wrong branch, or not a push. Deliberately 2xx: a non-2xx makes Gitea mark the delivery failed and retry it. |
| `200 pong` | Gitea's `ping`, sent once when the webhook is saved. |
| `404` | Failed either guard. |
| `409 busy` | A deploy is already running. Concurrency is held off with a non-blocking `flock`. |
| `500 failed` | A step failed. The run **stops at the first failure** rather than pressing on. |

## What it runs

In the repository root, in order, stopping on the first non-zero exit:

```
git pull <remote> <branch>
composer install --no-interaction --prefer-dist
php artisan migrate --force
php artisan optimize
```

`composer` and `php` must be on the **web user's** PATH, which is often not the PATH
you get over SSH. Set `DEPLOY_COMPOSER_BIN` / `DEPLOY_PHP_BIN` to absolute paths if a
step exits non-zero with no output.

## Branch resolution

`DEPLOY_BRANCH` wins if set. Otherwise the branch is read from `.git/HEAD` directly —
no `exec()`, so it works on hosts where shell functions are disabled. A detached HEAD
yields no branch and the push is ignored rather than guessed at.

## Not a replacement for `deploy.php`

The estate's hand-fired `deploy.php` stays. That file is deliberately different on
every site and must never be synced; this package is identical everywhere and keeps
the differences in `.env`. Sites can run either, or both.

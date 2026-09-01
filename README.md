# wizcoders/deployme

Push-to-deploy for Laravel. Two endpoints over one deploy routine: a Gitea webhook
guarded by two independent checks, and a token-only trigger for deploys you fire
by hand or from CI.

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
DEPLOY_ACTION_TOKEN=       # bearer token for the manual POST api/deploy trigger
DEPLOY_BRANCH=             # blank = deploy whatever branch is checked out
DEPLOY_REMOTE=origin
DEPLOY_PATH=               # blank = the host app's base_path()
DEPLOY_PHP_BIN=php
DEPLOY_COMPOSER_BIN=composer
DEPLOY_TIMEOUT=600
DEPLOY_LOGS_DIRECTORY_PATH="../deploylogs/"
```

Each secret gates its own endpoint, and every one of them is required for that
endpoint to answer: empty means "not set up", which denies with a 404. Set them
*before* pointing anything at the site. A site that only wants the webhook can leave
`DEPLOY_ACTION_TOKEN` blank, and vice versa.

Routing is configurable too, for sites that need to move or silence an endpoint:

```
DEPLOYME_ROUTE_ENABLED=true          # false = installed but unroutable
DEPLOYME_ROUTE_PATH=api/deploy/gitea # the webhook
DEPLOYME_ACTION_ROUTE_PATH=api/deploy  # the manual trigger
```

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

## Fire a deploy by hand

`POST` to `api/deploy` with the bearer token. No body, no payload:

```bash
curl -X POST https://<site>/api/deploy \
     -H "Authorization: Bearer $DEPLOY_ACTION_TOKEN"
```

It runs the same four steps, against the same working copy, holding the same lock as
the webhook — the two endpoints cannot deploy over each other. The branch is resolved
the same way as well: `DEPLOY_BRANCH` if set, otherwise whatever is checked out.

The token is **deliberately not** `DEPLOY_WEBHOOK_TOKEN`. This endpoint has no payload
and therefore no HMAC to check, so that single token is the only thing standing in
front of a shell; it is a weaker guard than the webhook's and must be rotatable on its
own. Give it a long random value:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

## How the guard works

On the webhook (`api/deploy/gitea`), two checks, both required, both constant-time:

1. **`Authorization` header** against `deployme.token` — proves who is calling.
2. **`X-Gitea-Signature`** against `hash_hmac('sha256', <raw body>, deployme.secret)`
   — proves the payload was not rewritten in transit.

Either one alone is not enough. The manual trigger has only check 1, against
`deployme.action_token` — there is no body to sign.

Both routes reject with a bare `404` so they do not advertise themselves to a scanner,
both compare with `hash_equals()`, and both are rate limited to 10 requests a minute.

## Responses

Both endpoints answer with a `status` and, on a run, a `steps` array carrying each
command's exit code.

| Status | Endpoint | Meaning |
|---|---|---|
| `200 deployed` | both | All four steps ran and exited zero. |
| `200 ignored` | webhook | Authentic call, but not for this site — wrong branch, or not a push. Deliberately 2xx: a non-2xx makes Gitea mark the delivery failed and retry it. |
| `200 pong` | webhook | Gitea's `ping`, sent once when the webhook is saved. |
| `400 error` | trigger | Detached HEAD and no `DEPLOY_BRANCH`, so there is no branch to pull. |
| `404` | both | Failed a guard. |
| `409 busy` | both | A deploy is already running. Concurrency is held off across *both* endpoints with a single non-blocking `flock`. |
| `500 failed` | both | A step exited non-zero. The run **stops at the first failure** rather than pressing on; `steps` shows how far it got. |

## What it runs

Both endpoints run the same thing, in the repository root, in order, stopping on the
first non-zero exit:

```
git pull <remote> <branch>
composer install --no-interaction --prefer-dist
php artisan migrate --force
php artisan optimize
```

`--force` and `--no-interaction` are not optional: without them `migrate` refuses to
run unattended in production and `composer` can block on a prompt until the step times
out. `DEPLOY_TIMEOUT` caps each step individually.

`composer` and `php` must be on the **web user's** PATH, which is often not the PATH
you get over SSH. Set `DEPLOY_COMPOSER_BIN` / `DEPLOY_PHP_BIN` to absolute paths if a
step exits non-zero with no output.

## Branch resolution

`DEPLOY_BRANCH` wins if set. Otherwise the branch is read from `.git/HEAD` directly —
no `exec()`, so it works on hosts where shell functions are disabled. A detached HEAD
yields no branch: the push is ignored rather than guessed at, and the manual trigger
answers `400`.

## Not a replacement for `deploy.php`

The estate's hand-fired `deploy.php` stays. That file is deliberately different on
every site and must never be synced; this package is identical everywhere and keeps
the differences in `.env`. Sites can run either, or both.

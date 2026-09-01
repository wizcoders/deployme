<?php

/**
 * wizcoders/deployme — Gitea push-to-deploy webhook.
 *
 * Every value is per-site and belongs in that site's .env, never in the repo.
 *
 * The two secrets are separate on purpose: `token` is the literal string typed
 * into Gitea's "Authorization Header" field, `secret` is the webhook Secret that
 * Gitea HMACs the payload with. Either one alone is not enough to deploy.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | Set `enabled` to false to keep the package installed but the endpoint
    | unroutable — useful on sites that deploy by hand.
    |
    */

    'route' => [
        'enabled'    => (bool) env('DEPLOYME_ROUTE_ENABLED', true),
        'path'       => env('DEPLOYME_ROUTE_PATH', 'api/deploy/gitea'),

        // The token-only trigger. Configurable so it cannot collide with `path`
        // on a site that has moved the webhook.
        'action_path' => env('DEPLOYME_ACTION_ROUTE_PATH', 'api/deploy'),

        'middleware' => ['api', 'throttle:10,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Both are required. An empty value means "not set up", which denies.
    |
    */

    // Exact value Gitea sends in the Authorization header.
    'token' => env('DEPLOY_WEBHOOK_TOKEN', ''),

    // Gitea webhook "Secret" — drives the X-Gitea-Signature HMAC-SHA256.
    'secret' => env('DEPLOY_WEBHOOK_SECRET', ''),

    // Bearer token for the token-only trigger (POST api/deploy). Deliberately
    // separate from `token`: that endpoint has no HMAC behind it, so it is a
    // weaker guard and must be rotatable without touching the webhook. Empty
    // means "not set up", and the trigger answers 404.
    'deploy_token' => env('DEPLOY_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | What to deploy
    |--------------------------------------------------------------------------
    */

    // Branch this site deploys. Blank = whatever the working copy has checked out.
    'branch' => env('DEPLOY_BRANCH', ''),

    'remote' => env('DEPLOY_REMOTE', 'origin'),

    // Repository root. Defaults to the host application's base path.
    'path' => env('DEPLOY_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    |
    | Binaries as the *web user* sees them — `composer` must be on its PATH, which
    | is often not the same PATH you get over SSH. Use absolute paths if unsure.
    |
    */

    'php_bin'      => env('DEPLOY_PHP_BIN', 'php'),
    'composer_bin' => env('DEPLOY_COMPOSER_BIN', 'composer'),

    /*
    | php-fpm starts its workers with a scrubbed environment, so the web user
    | usually has no $HOME — and composer refuses to run at all without somewhere
    | to keep its cache. We therefore hand it one explicitly rather than relying
    | on the pool config, which differs on every host in the estate.
    |
    | Blank resolves to storage_path('app/.composer'), which the web user already
    | has to own for Laravel to boot.
    */
    'composer_home' => env('DEPLOY_COMPOSER_HOME', ''),

    /*
    | Composer is memory-hungry and inherits php-fpm's memory_limit, which is
    | normally far tighter than the CLI's. -1 is what composer's own docs
    | recommend; it applies to the composer subprocess only, not to the worker.
    */
    'composer_memory_limit' => env('DEPLOY_COMPOSER_MEMORY_LIMIT', '-1'),

    // Per-command timeout in seconds. composer install is the slow one.
    'timeout' => (int) env('DEPLOY_TIMEOUT', 600),

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | One run log per deploy. Relative paths resolve against the repository root,
    | not the CWD. Blank disables file logging (failures still hit the Laravel log).
    |
    */

    'logs_path' => env('DEPLOY_LOGS_DIRECTORY_PATH', '../deploylogs/'),
];

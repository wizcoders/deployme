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

    'logs_path' => env('DEPLOY_LOGS_DIRECTORY_PATH', '../../deploylogs/'),
];

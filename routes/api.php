<?php

use Illuminate\Support\Facades\Route;
use Wizcoders\Deployme\Http\Controllers\DeployController;
use Wizcoders\Deployme\Http\Controllers\DeploymentActionController;

/*
| The Gitea push-to-deploy webhook.
|
| Throttled because a bare 404 on failure is still a free guess otherwise. The
| route is only loaded when deployme.route.enabled is true — see the service
| provider.
*/

Route::post(config('deployme.route.path', 'api/deploy/gitea'), [DeployController::class, 'gitea'])
    ->middleware(config('deployme.route.middleware', ['api', 'throttle:10,1']))
    ->name('deployme.gitea');

/*
| Simple token-based deployment trigger, for deploys fired by hand or by CI.
|
| Requires an Authorization header carrying the bearer token configured in
| deployme.action_token (usually from the DEPLOY_ACTION_TOKEN env). That is a
| different secret from the webhook's — this endpoint has no HMAC behind it.
*/

Route::post(config('deployme.route.action_path', 'api/deploy'), [DeploymentActionController::class, 'deploy'])
    ->middleware(config('deployme.route.middleware', ['api', 'throttle:10,1']))
    ->name('deployme.action');

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
| Simple token-based deployment trigger.
|
| Requires Authorization header with Bearer token.
| Token is configured in deployme.token (usually from DEPLOY_WEBHOOK_TOKEN env).
*/

Route::post('api/deploy', [DeploymentActionController::class, 'deploy'])
    ->middleware(config('deployme.route.middleware', ['api', 'throttle:10,1']))
    ->name('deployme.action');

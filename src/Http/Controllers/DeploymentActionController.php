<?php

namespace Wizcoders\Deployme\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Wizcoders\Deployme\Support\DeployLogger;
use Wizcoders\Deployme\Support\Git;

/**
 * Token-only deploy trigger, for deploys fired by hand or by CI rather than by a
 * Gitea push.
 *
 * AUTH-CRITICAL — this endpoint runs shell commands. Read before changing:
 *   1) There is no payload here, so there is no HMAC to check: the bearer token
 *      is the *only* guard. It must therefore never be weakened. An empty
 *      configured token means "not set up", which denies.
 *   2) The comparison uses hash_equals(). Never === or !=; both leak through
 *      timing, and != additionally leaks through PHP type juggling.
 *   3) Every rejection returns a bare 404 so the endpoint does not advertise
 *      itself to a scanner.
 *   4) Never log the token. Log the outcome only.
 *
 * The steps, the lock and the timeout are deliberately identical to
 * DeployController's — both endpoints deploy the same working copy, so they must
 * agree about what a deploy is and must not run at the same time.
 */
class DeploymentActionController extends Controller
{
    /**
     * Simple token-based deployment trigger.
     * Authorization header should contain: "Bearer YOUR_TOKEN"
     */
    public function deploy(Request $request): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $branch = $this->targetBranch();

        if ($branch === null) {
            Log::error('deployme: could not determine the deploy branch');

            return response()->json([
                'status'  => 'error',
                'message' => 'Could not determine the deploy branch.',
            ], 400);
        }

        return $this->run($branch);
    }

    /**
     * Validate the Authorization Bearer token.
     */
    private function authorised(Request $request): bool
    {
        $token    = (string) config('deployme.deploy_token', '');
        $provided = $this->bearerToken($request);

        return $token !== '' && $provided !== '' && hash_equals($token, $provided);
    }

    /**
     * Extract the bearer token from the Authorization header.
     */
    private function bearerToken(Request $request): string
    {
        $header = trim((string) $request->header('Authorization', ''));

        if ($header === '') {
            return '';
        }

        // Handle "Bearer TOKEN" format.
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        // Fallback: treat the entire header as the token if there is no prefix.
        return $header;
    }

    /**
     * Configured branch wins; otherwise deploy whatever is checked out. Null on a
     * detached HEAD, because a short commit hash is not something you can pull.
     */
    private function targetBranch(): ?string
    {
        $configured = trim((string) config('deployme.branch', ''));

        return $configured !== ''
            ? $configured
            : Git::currentBranchName($this->repositoryPath());
    }

    /**
     * The repository root the deploy commands run in.
     */
    private function repositoryPath(): string
    {
        $configured = trim((string) config('deployme.path', ''));

        return $configured !== '' ? $configured : base_path();
    }

    /**
     * One deploy at a time, across *both* endpoints — hence the shared lock file.
     * A trigger fired while a push deploy is mid-composer would otherwise run
     * git pull over a half-installed vendor/.
     */
    private function run(string $branch): JsonResponse
    {
        $lock = @fopen(storage_path('framework/deployme.lock'), 'c');

        if ($lock === false) {
            Log::error('deployme: could not open the deploy lock file');

            return response()->json([
                'status'  => 'error',
                'message' => 'could not acquire the deploy lock',
            ], 500);
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            return response()->json([
                'status'  => 'busy',
                'message' => 'a deploy is already running',
            ], 409);
        }

        $path     = $this->repositoryPath();
        $php      = (string) config('deployme.php_bin', 'php');
        $composer = (string) config('deployme.composer_bin', 'composer');
        $remote   = (string) config('deployme.remote', 'origin');

        $steps = [
            'git pull ' . escapeshellarg($remote) . ' ' . escapeshellarg($branch),
            $composer . ' install --no-interaction --prefer-dist',
            $php . ' artisan migrate --force',
            $php . ' artisan optimize',
        ];

        $logger  = DeployLogger::open($path);
        $results = [];
        $failed  = null;

        if ($logger !== null) {
            $logger->write('Deploy started ' . date('Y-m-d H:i:s') . " — branch {$branch} (triggered)");
        }

        foreach ($steps as $step) {
            $result = Process::path($path)
                ->timeout((int) config('deployme.timeout', 600))
                ->run($step);

            $output = trim($result->output() . "\n" . $result->errorOutput());

            if ($logger !== null) {
                $logger->writeCommand($step, $output);
            }

            $results[] = [
                'command' => $step,
                'exit'    => $result->exitCode(),
                'ok'      => $result->successful(),
            ];

            // Stop on the first failure — running migrate over a pull that did
            // not land is how you get a half-deployed site. Note this tests the
            // exit code: a failing command still *prints*, so output is not a
            // usable success signal.
            if (! $result->successful()) {
                $failed = $step;
                break;
            }
        }

        if ($logger !== null) {
            $logger->write($failed === null ? 'Deploy finished OK' : "Deploy ABORTED at: {$failed}");
        }

        flock($lock, LOCK_UN);
        fclose($lock);

        if ($failed !== null) {
            Log::error('deployme: deploy failed', ['branch' => $branch, 'step' => $failed]);

            return response()->json([
                'status' => 'failed',
                'branch' => $branch,
                'steps'  => $results,
            ], 500);
        }

        Log::info('deployme: deployment completed successfully', ['branch' => $branch]);

        return response()->json([
            'status' => 'deployed',
            'branch' => $branch,
            'steps'  => $results,
        ]);
    }
}

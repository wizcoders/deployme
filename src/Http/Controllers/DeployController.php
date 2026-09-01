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
 * Gitea push-webhook deploy endpoint.
 *
 * Nothing site-specific is hardcoded here — branch, secrets, paths and binaries
 * all come from config/deployme.php, which reads the site's own .env. That is what
 * makes this package safe to install identically across an estate whose sites
 * deploy different branches.
 *
 * AUTH-CRITICAL — this endpoint runs shell commands. Read before changing:
 *   1) TWO independent checks must pass: the Authorization header (a shared
 *      string) and the X-Gitea-Signature HMAC over the raw request body. The
 *      header proves who is calling, the HMAC proves the payload was not
 *      rewritten in transit. Do not drop either.
 *   2) Both comparisons use hash_equals(). Never === or !=; both leak through
 *      timing, and != additionally leaks through PHP type juggling.
 *   3) An empty configured token or secret means "not set up", which must deny,
 *      not allow. An empty secret must never mean "skip the HMAC".
 *   4) Every rejection returns a bare 404 so the endpoint does not advertise
 *      itself to a scanner.
 *   5) Never log the token, the secret or the signature. Log the outcome only.
 */
class DeployController extends Controller
{
    public function gitea(Request $request): JsonResponse
    {
        if (! $this->authorised($request)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $event = (string) $request->header('X-Gitea-Event', '');

        // Gitea fires this once when the webhook is saved. Answer it, do nothing.
        if ($event === 'ping') {
            return response()->json(['status' => 'pong']);
        }

        if ($event !== 'push') {
            return $this->ignored("event '{$event}' is not a push");
        }

        $pushed = $this->branchFromRef((string) $request->input('ref', ''));
        $target = $this->targetBranch();

        if ($target === null) {
            return $this->ignored('could not determine the deploy branch');
        }

        if ($pushed !== $target) {
            return $this->ignored("push was to '{$pushed}', this site deploys '{$target}'");
        }

        return $this->run($target);
    }

    /**
     * Both checks, in order. Returns false the moment either fails.
     */
    private function authorised(Request $request): bool
    {
        $token    = (string) config('deployme.token', '');
        $provided = (string) $request->header('Authorization', '');

        if ($token === '' || $provided === '' || ! hash_equals($token, $provided)) {
            return false;
        }

        $secret    = (string) config('deployme.secret', '');
        $signature = (string) $request->header('X-Gitea-Signature', '');

        if ($secret === '' || $signature === '') {
            return false;
        }

        // Gitea signs the raw body, so read it before anything parses or mutates it.
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * "refs/heads/dev" -> "dev". Anything else (a tag, a branch delete) -> ''.
     */
    private function branchFromRef(string $ref): string
    {
        return str_starts_with($ref, 'refs/heads/')
            ? substr($ref, strlen('refs/heads/'))
            : '';
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
     * One deploy at a time. A second push while the first is mid-composer would
     * otherwise run git pull over a half-installed vendor/.
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
            $logger->write('Deploy started ' . date('Y-m-d H:i:s') . " — branch {$branch}");
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
            // not land is how you get a half-deployed site.
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

        return response()->json([
            'status' => 'deployed',
            'branch' => $branch,
            'steps'  => $results,
        ]);
    }

    /**
     * A push we deliberately do nothing with. Must be 2xx: a non-2xx makes Gitea
     * mark the delivery failed and retry it.
     */
    private function ignored(string $reason): JsonResponse
    {
        return response()->json(['status' => 'ignored', 'reason' => $reason]);
    }
}

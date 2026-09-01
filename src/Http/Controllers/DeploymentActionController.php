<?php

namespace Wizcoders\Deployme\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Wizcoders\Deployme\Support\DeployLogger;
use Wizcoders\Deployme\Support\Git;

class DeploymentActionController extends Controller
{
    /**
     * Simple token-based deployment trigger.
     * Authorization header should contain: "Bearer YOUR_TOKEN"
     */
    public function deploy(Request $request): JsonResponse
    {
        if (!$this->authorised($request)) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        $repositoryPath = $this->repositoryPath();
        $logger = DeployLogger::open($repositoryPath);

        $currentBranch = Git::currentBranchName($repositoryPath);

        if ($logger !== null) {
            $logger->write("Checked out branch: {$currentBranch}");
        }

        if ($currentBranch === null) {
            if ($logger !== null) {
                $logger->write('ABORTED: could not determine the checked-out branch; nothing pulled.');
            }
            Log::error('deployme: could not determine the checked-out branch');
            return response()->json([
                'status'  => 'error',
                'message' => 'Could not determine the checked-out branch.',
            ], 400);
        }

        $php = (string) config('deployme.php_bin', 'php');
        $composer = (string) config('deployme.composer_bin', 'composer');

        if ($logger !== null) {
            $logger->write('Deploy started ' . date('Y-m-d H:i:s') . " — branch {$currentBranch}");
        }

        $commands = [
            "git pull origin {$currentBranch}",
            "{$composer} install",
            "{$php} artisan migrate",
            "{$php} artisan optimize"
        ];

        $failed = null;

        foreach ($commands as $cmd) {
            $output = shell_exec("cd {$repositoryPath} && {$cmd} 2>&1");

            if ($output === null) {
                $failed = $cmd;
                if ($logger !== null) {
                    $logger->write('FAILED to execute command');
                }
                break;
            }

            if ($logger !== null) {
                $logger->writeCommand($cmd, $output);
            }
        }

        if ($failed === null) {
            if ($logger !== null) {
                $logger->write('Deploy finished OK');
            }
            Log::info('deployme: deployment completed successfully', ['branch' => $currentBranch]);

            return response()->json([
                'status'  => 'success',
                'message' => 'Deployment completed.',
                'branch'  => $currentBranch,
            ], 200);
        }

        if ($logger !== null) {
            $logger->write("Deploy ABORTED at: {$failed}");
        }
        Log::error('deployme: deployment failed', ['branch' => $currentBranch, 'failed_at' => $failed]);

        return response()->json([
            'status'     => 'error',
            'message'    => 'Deployment failed.',
            'branch'     => $currentBranch,
            'failed_at'  => $failed,
        ], 500);
    }

    /**
     * Validate the Authorization Bearer token.
     */
    private function authorised(Request $request): bool
    {
        $token = (string) config('deployme.token', '');
        $provided = $this->getAuthorizationToken($request);

        if ($token === '' || $provided === '' || !hash_equals($token, $provided)) {
            return false;
        }

        return true;
    }

    /**
     * Extract the bearer token from the Authorization header.
     */
    private function getAuthorizationToken(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');

        if (empty($header)) {
            return '';
        }

        // Handle "Bearer TOKEN" format
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        // Fallback: treat entire header as token if no Bearer prefix
        return trim($header);
    }

    /**
     * The repository root the deploy commands run in.
     */
    private function repositoryPath(): string
    {
        $configured = trim((string) config('deployme.path', ''));

        return $configured !== '' ? $configured : base_path();
    }
}

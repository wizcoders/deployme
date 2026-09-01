<?php

namespace Wizcoders\Deployme\Support;

use Illuminate\Support\Facades\Log;

/**
 * Centralized deployment logging.
 *
 * Handles log file creation, path resolution, and writing with consistent formatting.
 * Returns null rather than throwing on file errors — a missing or unwritable
 * log directory must not block a deploy.
 *
 * It must, however, *say* so. Failing silently means a deploy that logged nothing
 * looks exactly like a deploy that was never fired, which is the worst state to
 * debug from. Every failure here is therefore a Log::warning; only a blank
 * logs_path — a deliberate "off" — passes quietly.
 */
class DeployLogger
{
    /**
     * @var resource|null
     */
    private $handle = null;

    /**
     * Open a new log file for this deployment run.
     *
     * Relative paths are resolved against the repository root, not the CWD.
     * Returns null on error (file creation failed or logging disabled).
     */
    public static function open(string $repositoryPath): ?self
    {
        $configured = trim((string) config('deployme.logs_path', ''));

        // Blank is a deliberate "no file logging", not a failure. Say nothing.
        if ($configured === '') {
            return null;
        }

        // Relative paths in .env are relative to the repository root, not the CWD.
        $path = Git::isAbsolute($configured)
            ? $configured
            : $repositoryPath . DIRECTORY_SEPARATOR . $configured;

        // The trailing is_dir() covers the race where a concurrent run wins the mkdir.
        if (! is_dir($path) && ! @mkdir($path, 0755, true) && ! is_dir($path)) {
            self::warn('could not create the deploy log directory', $path, $configured);

            return null;
        }

        // Colons are legal in a POSIX filename but not on Windows, and they make
        // the log awkward to scp — so H-i-s, not H:i:s.
        $file = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . date('Y-m-d H-i-s') . '.txt';

        $handle = @fopen($file, 'w');

        if ($handle === false) {
            self::warn('could not open the deploy log file', $file, $configured);

            return null;
        }

        $logger = new self();
        $logger->handle = $handle;

        return $logger;
    }

    /**
     * Report a logging failure to the Laravel log, then let the deploy continue.
     *
     * Reports the resolved path as well as the configured one: '../deploylogs/'
     * looks harmless in .env but lands outside the application root, which is
     * usually where the permission denial comes from.
     */
    private static function warn(string $message, string $path, string $configured): void
    {
        $parent = dirname($path);

        Log::warning("deployme: {$message} — the deploy will still run, but unlogged", [
            'configured'      => $configured,
            'resolved'        => $path,
            'parent'          => $parent,
            'parent_exists'   => is_dir($parent),
            'parent_writable' => is_writable($parent),
            'error'           => error_get_last()['message'] ?? null,
        ]);
    }

    /**
     * Write a line to the log file.
     *
     * Safe to call if logging is disabled (returns silently).
     */
    public function write(string $line): void
    {
        if ($this->handle !== null) {
            fwrite($this->handle, $line . "\n");
        }
    }

    /**
     * Write a command and its output to the log.
     */
    public function writeCommand(string $command, string $output): void
    {
        $this->write("$ {$command}");
        $this->write(trim($output));
    }

    /**
     * Close the log file.
     *
     * Safe to call multiple times.
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Check if logging is enabled (file handle exists).
     */
    public function isEnabled(): bool
    {
        return $this->handle !== null;
    }

    /**
     * Destructor ensures the log file is closed.
     */
    public function __destruct()
    {
        $this->close();
    }
}

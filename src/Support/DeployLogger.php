<?php

namespace Wizcoders\Deployme\Support;

/**
 * Centralized deployment logging.
 *
 * Handles log file creation, path resolution, and writing with consistent formatting.
 * Returns null rather than throwing on file errors — a missing or unwritable
 * log directory must not block a deploy.
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
        $path = trim((string) config('deployme.logs_path', ''));

        if ($path === '') {
            return null;
        }

        // Relative paths in .env are relative to the repository root, not the CWD.
        if (!Git::isAbsolute($path)) {
            $path = $repositoryPath . DIRECTORY_SEPARATOR . $path;
        }

        // Create directory if it doesn't exist
        @mkdir($path, 0755, true);

        // Colons are legal in a POSIX filename but not on Windows, and they make
        // the log awkward to scp — so H-i-s, not H:i:s.
        $file = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . date('Y-m-d H-i-s') . '.txt';

        $handle = @fopen($file, 'w');

        if ($handle === false) {
            return null;
        }

        $logger = new self();
        $logger->handle = $handle;

        return $logger;
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

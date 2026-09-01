<?php

namespace Wizcoders\Deployme\Support;

/**
 * Deliberately dependency-free — no Laravel, no shelling out.
 *
 * Reading .git/HEAD directly means this still works on hosts where exec() and
 * shell_exec() are disabled, and it cannot be affected by the web user's PATH.
 */
class Git
{
    /**
     * The branch currently checked out, or null if it cannot be determined.
     *
     * Walks up from $startDir looking for a .git entry, so it works from any
     * subdirectory. Returns the short commit hash when HEAD is detached — callers
     * that need a *pullable* branch should use currentBranchName() instead.
     */
    public static function currentBranch(string $startDir): ?string
    {
        $gitDir = self::locateGitDir($startDir);

        if ($gitDir === null) {
            return null;
        }

        $head = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';

        if (! is_readable($head)) {
            return null;
        }

        $contents = trim((string) file_get_contents($head));

        // Attached HEAD: "ref: refs/heads/master"
        if (preg_match('#^ref:\s*refs/heads/(.+)$#', $contents, $m)) {
            return $m[1];
        }

        // Detached HEAD: a raw commit hash.
        return preg_match('/^[0-9a-f]{40}$/i', $contents) ? substr($contents, 0, 7) : null;
    }

    /**
     * As currentBranch(), but null on a detached HEAD rather than a short hash.
     * Use this anywhere the answer feeds a `git pull <remote> <branch>`.
     */
    public static function currentBranchName(string $startDir): ?string
    {
        $branch = self::currentBranch($startDir);

        if ($branch === null || preg_match('/^[0-9a-f]{7}$/i', $branch)) {
            return null;
        }

        return $branch;
    }

    /**
     * Find the real git directory. In a normal clone .git is a directory; in a
     * worktree or submodule it is a file containing "gitdir: /path/to/real".
     */
    private static function locateGitDir(string $dir): ?string
    {
        while (true) {
            $gitPath = $dir . DIRECTORY_SEPARATOR . '.git';

            if (is_dir($gitPath)) {
                return $gitPath;
            }

            if (is_file($gitPath)) {
                $line = trim((string) file_get_contents($gitPath));

                if (! preg_match('/^gitdir:\s*(.+)$/', $line, $m)) {
                    return null;
                }

                $gitDir = $m[1];

                return self::isAbsolute($gitDir)
                    ? $gitDir
                    : $dir . DIRECTORY_SEPARATOR . $gitDir;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                return null; // Hit the filesystem root without finding a repo.
            }

            $dir = $parent;
        }
    }

    /**
     * Handles both POSIX (/var/www) and Windows (C:\, \\share) absolute paths.
     */
    public static function isAbsolute(string $path): bool
    {
        return (bool) preg_match('#^([a-zA-Z]:[\\\\/]|[\\\\/])#', $path);
    }
}

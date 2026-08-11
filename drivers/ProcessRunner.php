<?php

/**
 * Shared launcher for the vendor fiscal-printer executables.
 *
 * Provides the two things a bare exec() did not:
 *
 *  1. A working directory. ecrprint.exe builds every path it uses from
 *     Directory.GetCurrentDirectory() — it contains no Assembly.Location,
 *     CodeBase or AppDomain.BaseDirectory call at all — so it only finds
 *     ecrprint.xml and ecrprint.in when started inside bin/accent.
 *     (Severec.exe and Razvigorec.exe use AppDomain.BaseDirectory instead and
 *     are therefore indifferent to the working directory; starting them in
 *     their own folder anyway costs nothing and keeps the files they write out
 *     of the project root.)
 *  2. Escaped arguments and a checked exit code, so a print that fails stops
 *     being reported to the caller as success.
 */
trait ProcessRunner
{
    /**
     * Runs an executable with its own directory as the working directory.
     *
     * Uses the array form of proc_open so the arguments are escaped by PHP —
     * a bare exec() string breaks as soon as the deploy path contains a space
     * (e.g. C:\Program Files\...).
     *
     * @param string   $execPath Absolute path to the executable.
     * @param string[] $args     Extra command-line arguments.
     *
     * @throws \RuntimeException if the process cannot be started or exits non-zero.
     */
    protected function runProcess(string $execPath, array $args = []): void
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$execPath, ...$args],
            $descriptors,
            $pipes,
            dirname($execPath)   // <-- the exe reads its config/input from here
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start ' . $execPath);
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                '%s exited with code %d: %s',
                basename($execPath),
                $exitCode,
                trim($stdout . ' ' . $stderr)
            ));
        }
    }

    /**
     * Deletes files if they exist, so that anything found after the run is
     * known to belong to that run. None of these are inputs, and the vendor
     * exes recreate the ones they use.
     */
    protected function clearFiles(string ...$paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }

            clearstatcache(true, $path);
        }
    }

    /**
     * Reads one of the exes' Windows-1251 output files as UTF-8.
     * Returns null when the file is missing or holds only whitespace.
     */
    protected function readResultFile(string $path): ?string
    {
        clearstatcache(true, $path);

        if (!is_file($path)) {
            return null;
        }

        $raw = trim((string) @file_get_contents($path));

        if ($raw === '') {
            return null;
        }

        return mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
    }

    /**
     * Maps filename => size for every file in a directory, or an empty array
     * when the directory does not exist yet.
     */
    protected function snapshotFiles(string $dir): array
    {
        clearstatcache();

        if (!is_dir($dir)) {
            return [];
        }

        $sizes = [];

        foreach ((array) @scandir($dir) as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_file($path)) {
                $sizes[$entry] = filesize($path);
            }
        }

        return $sizes;
    }

    /**
     * Names of files in $dir that appeared or grew since $before was taken.
     * Size is compared as well as presence, because the exes append to an
     * existing log rather than always creating a new file.
     *
     * @param array<string,int> $before
     * @return string[]
     */
    protected function changedSince(array $before, string $dir): array
    {
        $changed = [];

        foreach ($this->snapshotFiles($dir) as $name => $size) {
            if (!array_key_exists($name, $before) || $before[$name] !== $size) {
                $changed[] = $name;
            }
        }

        return $changed;
    }
}

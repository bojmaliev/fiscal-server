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
}

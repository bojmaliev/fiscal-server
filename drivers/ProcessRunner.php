<?php

/**
 * Shared launcher for the vendor fiscal-printer executables.
 *
 * Every one of these exes resolves its auxiliary files — config (ecrprint.xml,
 * FISKAL.INI, Razvigorec.ini), input, output, error and result files — relative
 * to the *working directory*, not to its own location. ecrprint.exe in
 * particular contains no Assembly.Location / CodeBase / BaseDirectory call at
 * all: it can only build paths from Directory.GetCurrentDirectory(). So each
 * exe must be started with its own folder as the working directory, otherwise
 * it silently finds nothing and prints nothing.
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

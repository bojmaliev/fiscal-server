<?php

/**
 * Failure detection shared by the two Duna executables (Severec, Razvigorec).
 *
 * Neither sets a process exit code, so on its own a failed print is
 * indistinguishable from a successful one. Both do leave evidence on disk,
 * next to the exe:
 *
 *   Result.out   Windows-1251, one fact per line. On success it carries the
 *                command, the fiscal memory serial and the receipt number:
 *
 *                    Команда: artikli.in
 *                    Сериски број на фискална меморија: DU330999004
 *                    Број на фискална сметка: 1
 *
 *                Failures are prefixed "ERROR: "; Razvigorec also emits
 *                "Fatal Error!".
 *
 *   Log\Error\   the exes' own structured error log (Modul / Metod / Line).
 *
 * The check is deliberately one-sided: a request fails only on positive
 * evidence of an error. A missing Result.out is NOT treated as a failure,
 * because reporting a receipt that did print as failed invites the operator to
 * print a duplicate — worse, for a fiscal device, than a missing receipt they
 * can plainly see never came out.
 */
trait DunaResult
{
    protected string $resultFile;
    protected string $errorLogDir;

    protected function initResultPaths(string $exeDir): void
    {
        $this->resultFile  = $exeDir . DIRECTORY_SEPARATOR . 'Result.out';
        $this->errorLogDir = $exeDir . DIRECTORY_SEPARATOR . 'Log' . DIRECTORY_SEPARATOR . 'Error';
    }

    /**
     * @param array<string,int> $logBefore Snapshot of the error-log directory
     *                                     taken immediately before the run.
     */
    protected function assertResultOk(array $logBefore): void
    {
        $exe    = basename($this->execPath);
        $result = $this->readResultFile($this->resultFile);

        if ($result !== null) {
            foreach (['ERROR:', 'Fatal Error!'] as $marker) {
                if (stripos($result, $marker) !== false) {
                    throw new \RuntimeException($exe . ' reported: ' . $result);
                }
            }
        }

        $logged = $this->changedSince($logBefore, $this->errorLogDir);

        if ($logged !== []) {
            $detail = $this->readResultFile($this->errorLogDir . DIRECTORY_SEPARATOR . $logged[0]);

            throw new \RuntimeException(sprintf(
                '%s wrote to its error log (%s): %s',
                $exe,
                implode(', ', $logged),
                $detail ?? '(unreadable)'
            ));
        }
    }

    /**
     * The receipt number and fiscal memory serial Result.out reports once a
     * receipt has printed (see the class docblock and docs/severec/Result.out,
     * docs/razvigorec/Result.out).
     *
     * Call it only once assertResultOk() has passed: the drivers clear
     * Result.out before every run, so whatever is there now belongs to this
     * receipt. A field comes back null when its line is missing, appears with
     * two different values, or holds anything but what the vendor samples
     * show — digits for the receipt number, one word for the serial. Never an
     * exception, since the receipt is already out.
     */
    protected function receiptFromResult(): ReceiptResult
    {
        return ReceiptResult::bestEffort(function (): ReceiptResult {
            $result = $this->readResultFile($this->resultFile) ?? '';

            return new ReceiptResult(
                $this->resultValue($result, 'Број на фискална сметка', '/^\d+$/'),
                $this->resultValue($result, 'Сериски број на фискална меморија', '/^\S+$/u')
            );
        });
    }

    /**
     * The value after "<label>:" on the lines of Result.out that start with
     * the label, provided they all agree and it matches $format.
     */
    private function resultValue(string $result, string $label, string $format): ?string
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $result) as $line) {
            $line = trim($line);

            if (str_starts_with($line, $label . ':')) {
                $values[] = trim(substr($line, strlen($label) + 1));
            }
        }

        $values = array_values(array_unique($values));

        return count($values) === 1 && preg_match($format, $values[0]) === 1 ? $values[0] : null;
    }
}

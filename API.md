# Fiscal Server API

A small HTTP wrapper around Macedonian fiscal printers. It runs on the same
machine as the printer, translates JSON requests into each vendor's file-based
command format, and invokes the vendor executable over the serial port.

```
POS / web app  ──HTTP──▶  fiscal-server (PHP)  ──file + exec──▶  vendor .exe  ──COM──▶  printer
```

- **Base URL** — `http://<till-host>:8000/index.php` (see [serve.bat](serve.bat))
- **Auth** — none. Bind it to the local network only.
- **CORS** — `Access-Control-Allow-Origin: *`, methods `GET, POST, OPTIONS`,
  headers `Content-Type, Authorization, X-Requested-With`. Preflight returns
  `200` with an empty body.

---

## Requests

The operation is selected with the **`q`** query parameter. Endpoints that need
data take a JSON body; the request method is not enforced, so `GET` works for
`q=ping` and the two report endpoints that take no input.

```
POST /index.php?q=fiscal
Content-Type: application/json
```

### Choosing the printer per request

Three optional keys let the calling application target any till without
anything being configured on the machine itself. Each may be sent **either as a
query parameter or as a field in the JSON body** — the query string wins when
both are present.

| Key | Values | Default |
|---|---|---|
| `driver` | `fp700`, `sy250`, `severec`, `razvigorec` | `fp700` |
| `port` | `COM1`–`COM999`, case-insensitive | leave the printer's existing setting |
| `speed` | digits only — see notes below | `fp700` 9600, `sy250` 115200, other drivers untouched |

```
?q=fiscal&driver=severec&port=COM4
```

Omitting `port` leaves the vendor config file **byte-for-byte untouched**, so a
till someone configured by hand keeps working. Supplying a value rewrites just
that field before the print (`ecrprint.xml`, `FISKAL.INI` or `Razvigorec.ini`
depending on the driver) — see
[Side effects](#side-effects-of-port-and-speed).

> **`speed` is not the same unit on every driver.** `fp700`/`sy250` take a
> literal baud rate. `severec` takes a vendor *code*, not a baud rate (its
> shipped config uses `5`). `razvigorec` has no baud setting at all and returns
> **400** if you send one.

> **The Accent drivers always write their own baud rate.** `fp700` and `sy250`
> share one `ecrprint.xml` but the two models do not run at the same rate, so
> omitting `speed` there does *not* mean "leave it alone": `fp700` writes
> `9600` and `sy250` writes `115200` on every run. Send an explicit `speed` to
> override either.

Defaults live in [index.php](index.php#L42-L45) as `PRINTER_DRIVER`,
`PRINTER_PORT` and `PRINTER_SPEED`.

---

## Endpoints

### `q=ping` — health check

```bash
curl 'http://localhost:8000/index.php?q=ping&driver=severec&port=COM4'
```

```json
{ "ok": true, "service": "fiscal-server", "version": "1.1.0", "driver": "severec" }
```

Never goes near the printer: no driver is constructed, so no vendor config
file is read or rewritten, and no vendor executable runs. `driver`, `port` and
`speed` are still validated exactly as on the printing routes, with the same
`400`s, so a client can check its settings before relying on them.

- `service` is always `fiscal-server` — proof the client reached this server
  and not some other program listening on the port.
- `version` is `VERSION` in [index.php](index.php).
- `driver` is the resolved driver name, also sent as `X-Fiscal-Driver`.

A `200` says nothing about the printer itself — whether one is attached,
switched on, or on that port.

### `q=fiscal` — print a fiscal receipt

```json
{
  "items": [
    { "name": "СМОКИ", "vat": "A", "price": 10.00, "quantity": 1, "mkd": false }
  ],
  "payments": [
    { "amount": 10.00, "cash": true }
  ]
}
```

Both arrays are required and must be non-empty (`400` otherwise).

**Item**

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | yes | UTF-8; upper-cased, then converted to Windows-1251 for the printer |
| `vat` | string | no (`A`) | `A`, `B`, `V` or `G`. Anything else → **400** |
| `price` | number | yes | Unit price, 2 decimals |
| `quantity` | number | no (`1`) | 3 decimals |
| `mkd` | boolean | no (`false`) | Marks a Macedonian-origin product. Ignored by `razvigorec` |

**Payment**

| Field | Type | Required | Notes |
|---|---|---|---|
| `amount` | number | yes | 2 decimals |
| `cash` | boolean | no (`true`) | `true` = cash, `false` = card |

Multiple payment entries are supported (e.g. part card, part cash). `razvigorec`
sums them into a single cash/card/mixed line and **rounds to whole denari**.

```bash
curl -X POST 'http://localhost:8000/index.php?q=fiscal&port=COM4' \
  -H 'Content-Type: application/json' \
  -d '{"items":[{"name":"СМОКИ","vat":"A","price":10,"quantity":1}],
       "payments":[{"amount":10,"cash":true}]}'
```

Answers `200` with the number the receipt was given, where the driver can read
it — see [Receipt number](#receipt-number):

```json
{ "receipt_number": "42", "fiscal_memory_serial": "DU330999004" }
```

### `q=storno` — print a storno (refund) receipt

Takes the `q=fiscal` body, validated the same way — `items` and `payments`
both required and non-empty, the same fields, the same `400`s — for the items
being handed back and the money paid out for them. Answers like `q=fiscal`,
with the [receipt number](#receipt-number) of the storno receipt.

```bash
curl -X POST 'http://localhost:8000/index.php?q=storno&driver=sy250&port=COM3' \
  -H 'Content-Type: application/json' \
  -d '{"items":[{"name":"СМОКИ","vat":"A","price":10,"quantity":1}],
       "payments":[{"amount":10,"cash":true}]}'
```

Each driver sends exactly the batch `q=fiscal` would, changed only where the
vendor's own storno sample differs from a sale:

| Driver | Change from a sale | Evidence in `docs/` |
|---|---|---|
| `fp700` | open command `0` (48) becomes `U` (85), close `8` (56) becomes `V` (86) | every sample in `accent/fp700/StorniSmetki` is its `FiskalniSmetki` twin with exactly those two bytes changed |
| `sy250` | command 48's `Storno` parameter `0` becomes `1` | the SY command reference ("When '1' open storno receipt"), and every sample in `accent/sy250/StorniSmetki` is its `FiskalniSmetki` twin with exactly that byte changed |
| `severec` | `,R` appended to the open command (`01,0001,1,R`) | `severec/STORNO.bat` runs `artikliREF.in`, whose open command is `artikli.in`'s (`SMETKA.bat`) plus `,R` |
| `razvigorec` | `#S` in place of `#F` | `razvigorec/Komandi.txt` (`#S - Storno smetka`) and `Primeri na smetki/Storna.txt` |

Two deliberate departures from the samples, both inherited from `q=fiscal`:

- **Payment lines.** The `fp700` and `severec` storno samples pay with a bare
  `5\t`, which settles the whole total in cash. The drivers send each
  payment's mode and amount instead, exactly as their sale receipts do, so a
  card refund is recorded as card. `sy250`'s `StornaSmetka_TipoviPlakanja`
  shows those very cash and card lines inside a storno; the `fp700` and
  `severec` samples show no storno paid any other way than in cash.
- **`severec` display lines.** `artikliREF.in` also carries ` #…` lines that
  look like customer-display text (one reads `ВКУПНО:` in the DOS code page,
  just before the 45 total). `q=fiscal` has never sent their counterpart in
  `artikli.in`, and `q=storno` does not either.

The server does not tie a storno to the sale it reverses — none of the vendor
samples carries a reference to one — nor check that the items were ever sold.
Both are up to the caller. Every storno format above is unverified on real
hardware; see [Not yet verified on a till](#not-yet-verified-on-a-till).

### `q=close-day-report` — Z report

Closes the fiscal day and resets daily totals. No body.

```bash
curl 'http://localhost:8000/index.php?q=close-day-report'
```

### `q=control-report` — X report

Non-resetting control report. No body.

```bash
curl 'http://localhost:8000/index.php?q=control-report'
```

### `q=deposit-withdraw-money` — cash in / out

```json
{ "amount": 500.00 }
```

Positive deposits, negative withdraws. Defaults to `0` if omitted.
**Not supported by `razvigorec`** → `500`.

### `q=period-short-report` — fiscal memory report

```json
{ "from": "2026-08-01", "to": "2026-08-11" }
```

Both dates required, `YYYY-MM-DD`, validated with `checkdate()` → `400` on a bad
or impossible date.

---

## Responses

| Status | Body | Meaning |
|---|---|---|
| `200` | `{"receipt_number": …, "fiscal_memory_serial": …}` | `q=fiscal`, `q=storno`: the receipt was sent and no failure was detected — see [Receipt number](#receipt-number) |
| `200` | *empty* | Every other printing route: the command was sent and no failure was detected |
| `200` | `{"ok": true, …}` | `q=ping` — see [above](#qping--health-check) |
| `400` | `{"error": "..."}` | Bad request — your input |
| `500` | `{"error": "..."}` | The printer or vendor executable failed |

Error bodies are JSON with the reason preserved, including Macedonian text from
the printer:

```json
{ "error": "ecrprint reported: ГРЕШКА: Не може да се отвори COM7 - портата е зафатена" }
```

### What `200` does and does not mean

Failures are detected by reading the files the vendor executables leave behind,
because **none of them set a process exit code** — on its own a failed print
looks identical to a successful one.

- `fp700` / `sy250` — two files, one line per command in each, because two
  different things can go wrong. `ecrprint.rs` covers the link (`1, OK`, or
  `NAK_RECEIVED`, `TIMEOUT_READING`, `WRONG_COMMAND_RESPONSE`, `GENERAL_ERROR`,
  `SYNTAX_ERROR`, `INVALID_RESPONSE`, `UNKNOWN`). A command that arrived intact
  and was then *refused* still reads `OK` there — the refusal is in
  `ecrprint.out`, which opens with a verdict field: `P` done, `R` a payment
  quoting the change, `F` a rejection with a code and a message
  (`F -1004 Bad input`). Either one fails the request, quoting the command.
  Anything ecrprint prints to the console beyond its two version banners fails
  it as well, since it reports the exceptions it swallows there and still
  exits 0. **`ecrprint.err` is not an error channel** despite the name: it
  holds the printer's six status bytes for *every* command, successful ones
  included, so a run that went perfectly still fills it.
- `severec` / `razvigorec` — `Result.out` is checked for `ERROR:` / `Fatal Error!`,
  and `Log\Error\` is checked for any file that appeared or grew.

Detection is deliberately **one-sided**: a request fails only on positive
evidence of an error. A missing result file is not treated as a failure, because
reporting a receipt that *did* print as failed would invite a duplicate fiscal
receipt. So `200` means "no error was reported", not "paper definitely came
out". Treat `500` as authoritative and `200` as optimistic.

### Receipt number

`q=fiscal` and `q=storno` answer `200` with two fields, each a string or
`null`:

```json
{ "receipt_number": "1634", "fiscal_memory_serial": null }
```

Both are read from what the vendor tooling leaves behind once the receipt has
printed, and each is `null` unless it came from output that is documented to
carry it — nothing is inferred, so a number that might be the receipt's is
never reported as the receipt's. Failing to read them never turns the response
into an error: the paper is already out, and a printed receipt reported as
failed invites a duplicate. So `null` means "unknown", not "not printed".

| Driver | `receipt_number` | `fiscal_memory_serial` |
|---|---|---|
| `fp700` | always `null` | always `null` |
| `sy250` | slip number from the close command's answer | always `null` |
| `severec` | `Број на фискална сметка:` line of `Result.out` | `Сериски број на фискална меморија:` line of `Result.out` |
| `razvigorec` | as `severec` | as `severec` |

- **`sy250`** — `ecrprint.out` holds the printer's answer to each command. The
  SY command reference,
  [Control_codes_cash_register-v2.md](docs/accent/sy250/Control_codes_cash_register-v2.md),
  documents the answer to command 56 (close receipt, `8`) as
  `{ErrorStatus}<SEP>{SlipNumber}<SEP>`, the slip number being the "current
  slip number (sales or storno)", and a live SY250 answered it `P\t1634`. It is
  reported only when the verdict is `P` and the field is all digits. Command 48
  (open receipt) answers the same field; only the close answer is used, being
  the one given once the receipt is complete. No command in that reference
  returns the fiscal memory serial.
- **`fp700`** — nothing in `docs/accent` describes what this model answers. The
  command reference there is the SY series' (tab-separated fields, `P`/`F`
  verdicts), not the comma-separated dialect the FP700 speaks, and the FP700
  samples are input only. Rather than guess, both fields stay `null`. Its
  answers are still logged to `bin/accent/ecrprint.log` (see
  [Knowing what was actually sent](#knowing-what-was-actually-sent)): a few
  logged receipts from a real FP700, set against the paper, would show whether
  any field is the receipt number.
- **`severec`, `razvigorec`** — `Result.out` (Windows-1251) reads, on success:

  ```
  Команда: severec.in
  Сериски број на фискална меморија: DU330999004
  Број на фискална сметка: 42
  ```

  A field is `null` when its line is missing, appears twice with different
  values, or holds anything but digits (receipt number) or a single word
  (serial).

For a storno, `sy250` reports the slip number its close answer carries, which
the reference describes as covering "sales or storno"; `severec` and
`razvigorec` report whatever `Result.out` states. Whether storno receipts are
numbered in a sequence of their own has not been checked on any model.

### Common errors

| Status | Message | Cause |
|---|---|---|
| 400 | `Missing route parameter` | no `q` |
| 400 | `Unknown route: x` | bad `q` |
| 400 | `Unknown printer driver: x` | bad `driver` |
| 400 | `Invalid port. Expected COM1 to COM999.` | bad `port` |
| 400 | `Invalid speed. Expected digits only.` | non-numeric `speed` |
| 400 | `The Razvigorec driver has no speed setting; omit "speed".` | `speed` + `razvigorec` |
| 400 | `items and payments are required` | missing or empty array on `q=fiscal` / `q=storno` |
| 400 | `Invalid VAT code: X` | `vat` outside `A`/`B`/`V`/`G` |
| 400 | `Invalid date. Expected YYYY-MM-DD format.` | bad `from`/`to` |
| 500 | `ecrprint: the printer rejected command N of M (…) — …` | the Accent till refused that command, or never answered it |
| 500 | `ecrprint reported: …` | ecrprint gave up before talking to the printer (missing `ecr.dll`, unreadable `ecrprint.xml`, no `ecrprint.in`) |
| 500 | `Severec.exe reported: …` | error line in `Result.out` |
| 500 | `… wrote to its error log (…): …` | Duna executable logged an error |
| 500 | `Failed to start …` / `… exited with code N` | executable missing or unrunnable |
| 500 | `depositWithdrawMoney is not supported by the Razvigorec driver` | unsupported operation |
| 500 | `Cannot read/write <config file>` | permissions on the vendor config |

---

## Driver support matrix

| | `fp700` | `sy250` | `severec` | `razvigorec` |
|---|---|---|---|---|
| `q=ping` | ✅ | ✅ | ✅ | ✅ |
| `q=fiscal` | ✅ | ✅ | ✅ | ✅ (whole denari) |
| `q=storno` | ⚠️ from samples, unverified | ⚠️ from samples, unverified | ⚠️ from samples, unverified | ⚠️ from samples, unverified (whole denari) |
| `q=close-day-report` | ✅ | ✅ | ✅ | ✅ |
| `q=control-report` | ✅ | ✅ | ✅ | ✅ |
| `q=deposit-withdraw-money` | ✅ | ✅ | ✅ | ❌ `500` |
| `q=period-short-report` | ✅ | ✅ | ✅ | ⚠️ date format unconfirmed |
| `receipt_number` | `null` | ✅ close answer | ✅ `Result.out` | ✅ `Result.out` |
| `fiscal_memory_serial` | `null` | `null` | ✅ `Result.out` | ✅ `Result.out` |
| `mkd` item flag | ✅ | ✅ | ✅ | ignored |
| `port` | ✅ | ✅ | ✅ | ✅ |
| `speed` | ✅ baud (default 9600) | ✅ baud (default 115200) | ✅ vendor code | ❌ `400` |

`fp700` and `sy250` share `ecrprint.exe`; `severec` and `razvigorec` are the two
Duna executables.

---

## Operational notes

### Side effects of `port` and `speed`

None of the vendor executables accept a serial port as a command-line argument,
so the only way to steer them is to rewrite their config file before the run.
When you send `port` or `speed` — and on `fp700`/`sy250` on every run, since
those two always write their own baud rate — the corresponding file is updated
in place, only that value and only when it actually differs:

| Driver | File | Field |
|---|---|---|
| `fp700`, `sy250` | `bin/accent/ecrprint.xml` | `<port>`, `<speed>` |
| `severec` | `bin/severec/FISKAL.INI` | `Port=`, `Speed=` |
| `razvigorec` | `bin/razvigorec/Razvigorec.ini` | first line |

Those three files are tracked in git, so per-request writes show up as local
modifications on the till. Either accept `git checkout --` on them when pulling,
or mark them `git update-index --skip-worktree` per install.

### Knowing what was actually sent

Every `fp700`/`sy250` run appends to `bin/accent/ecrprint.log` (gitignored):
the resolved driver, the port and baud the exe ran with, and then each command
paired with what the printer made of it. Unprintable bytes are escaped, so the
sequence byte and the tab-delimited fields stay countable:

```
[2026-09-20 19:15:42] SY250Driver port=COM3 speed=115200
  1 sent #01\t1\t\t0\t
    got  1, OK | status 128, 128, 136, 128, 134, 154 | returned P	1634
  2 sent $1\xC5\xF1\xEF\xF0\xE5\xF1\xEE\t4\t70.00\t1.000\t0\t\t\t
    got  1, OK | status 128, 128, 136, 128, 134, 154 | returned F	-1004	Bad input
  3 sent %50\t70.00\t
    got  1, OK | status 128, 128, 136, 128, 134, 154 | returned R	70.00
  4 sent &8
    got  1, OK | status 128, 128, 128, 128, 134, 154 | returned P	1634
```

`got` is the `ecrprint.rs` entry for that command, then the six status bytes
from `ecrprint.err`, then whatever the command returned in `ecrprint.out`. The
run above is a receipt whose item line was refused while the link stayed
healthy throughout — which is why all three are worth keeping. `not sent`
marks commands `<stopOnFail>` skipped after a failure, and `skipped` a line
too short for ecrprint to send at all.

Every response also carries **`X-Fiscal-Driver`** naming the driver that ran
(exposed to browsers via `Access-Control-Expose-Headers`). Both exist because
driving a till with the wrong model's dialect does not look like a
configuration mistake from the outside — the short report commands survive it
and only the receipt fails to appear.

### One request at a time

The serial port is exclusive, and a print is a multi-step sequence
(write config → write command file → run exe → read result). `serve.bat` uses
PHP's built-in server, which handles one request at a time, so this is currently
safe. **If you move to Apache or nginx + FPM, that sequence needs a lock** —
otherwise two concurrent requests can interleave and print to the wrong port.

### Requirements on the till

- Windows, 32-bit .NET Framework (Razvigorec requires 4.6.2)
- PHP 8+ with `mbstring` (Windows-1251), `dom` (config rewriting), and
  `proc_open` not in `disable_functions`
- Nothing else holding the COM port

### Diagnostics

| Driver | Where to look |
|---|---|
| `fp700`, `sy250` | `bin/accent/ecrprint.err`, `.out`, `.rs` |
| `severec` | `bin/severec/Result.out`, `bin/severec/Log/Error/` |
| `razvigorec` | `bin/razvigorec/Result.out`, `bin/razvigorec/Log/Error/` |

On success the Duna `Result.out` also carries the fiscal memory serial and the
receipt number, which is where `q=fiscal` and `q=storno` get them:

```
Команда: severec.in
Сериски број на фискална меморија: DU330999004
Број на фискална сметка: 42
```

`bin/accent/ecrprint.xml` ships with `<stopOnFail>true</stopOnFail>`, so an
Accent till abandons the rest of a batch once a command fails. **It only
covers the link**, though: ecrprint stops on a non-`OK` `ecrprint.rs` entry
and nothing else, so a command the printer answered and refused does not stop
it. On `q=fiscal` and `q=storno` that has teeth — a refused item line is
passed over while the payment and close commands still run, which issues a real
receipt with no items, a 0.00 total and the full tendered amount handed back as
change. The server detects that and returns 500, but the paper is already out.

Avoiding it altogether means not sending the payment and close commands until
the items are known to have registered, i.e. splitting `fiscal()` and
`storno()` across two ecrprint runs instead of one.

Both Duna executables also read two extra keys from `FISKAL.INI` that are not
exposed through the API: `BEZFISKALNA` (non-fiscal test receipts — useful for
testing without consuming fiscal memory) and `LOGPRINTER` (dumps serial traffic
to `Log\Printer\`).

### Not yet verified on a till

Built from the vendor documentation and samples in `docs/`, and tested against
fakes only:

- **`q=storno`, on every driver.** No storno has been printed on a till. Before
  relying on it, print one per model — paid in cash, then by card — and check
  the paper, the day's Z report and, on `fp700`/`sy250`,
  `bin/accent/ecrprint.log`. Most in doubt are the explicit payment lines
  inside an `fp700` or `severec` storno: the vendor samples only ever pay a
  storno in cash, with a bare `5\t`, so try a card storno on those two first.
  On the Duna models, `BEZFISKALNA` (see [Diagnostics](#diagnostics)) is the
  way to try without consuming fiscal memory.
- **`sy250` receipt number.** The shape of the close answer comes from a live
  run, but nobody has yet set the slip number against the number printed on
  the receipt.
- **`severec` / `razvigorec` receipt number and serial.** The line format comes
  from the vendor's sample `Result.out` files, not from a live run.
- **Receipt numbers on a storno**, on `sy250`, `severec` and `razvigorec` —
  see [Receipt number](#receipt-number).

### Tests

```
php tests/run.php          # everything
php tests/run.php sy250    # only the tests whose name contains "sy250"
```

Needs PHP 8 on macOS or Linux, nothing else. Each test drives a driver end to
end against a scratch copy of `bin/` in which the three vendor executables are
replaced by the fakes in [tests/fakes](tests/fakes) — PHP scripts started
through their `#!/usr/bin/env php` line, which is why Windows is out — that
record the command file they were given and leave behind the result files the
real ones would. The checkout's own `bin/` is never touched. The suite pins
the exact bytes `q=fiscal` sends on every driver, so a change to a sale receipt
cannot go unnoticed, and holds every `q=storno` batch to the vendor's storno
samples.

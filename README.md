# Concurrency Count for FreePBX 17

FreePBX/PBXact 17 only. DO NOT install on FreePBX/PBXact 16.

Maximum concurrent PJSIP calls per trunk, extension, or group across a date range. Normal report runs are read-only against `asteriskcdrdb`; demo mode temporarily writes tagged synthetic rows to CDR and removes them after the run.

This is the FreePBX module companion to the Concurrency Count CLI tool (`concurrency-count`) - NOT CURRENTLY SUITABLE FOR PRODUCTION. The web interface uses a wizard modal for trunk, extension, and group reports, with the same shorthand date entry (today, yesterday, month names, Y/YY/YYYY years), the same three-attempt retry behaviour, and the same runtime-overrun warning. Demo mode is launched separately through **Run Demo** because it writes temporary synthetic CDR rows.

## Requirements

- FreePBX 17 or later
- PJSIP channel driver (no chan_sip support)
- Asterisk CDR enabled and writing to `asteriskcdrdb`

## Installation

Pick whichever path fits. The module is currently unsigned/unsupported.

### Option 1: Existing module directory

Place the `concurrencycount` directory in `/var/www/html/admin/modules/`, then:

```
fwconsole ma install concurrencycount
fwconsole chown
fwconsole reload
```

The module appears under **Reports > Concurrency Count**.

### Option 2: Developer install from a local copy

From inside the module directory:

```
cd /var/www/html/admin/modules/concurrencycount
fwconsole ma installlocal
fwconsole chown
fwconsole reload
```

Use `installlocal` when installing from an unpacked local module directory.

### Option 3: Clean install from GitHub

For a clean first install from this GitHub repo on a PBX:

```
cd /root && rm -rf /var/www/html/admin/modules/concurrencycount && git clone https://github.com/kierknoby/concurrencycount.git /var/www/html/admin/modules/concurrencycount && fwconsole ma install concurrencycount; fwconsole ma list | grep -q "concurrencycount.*Not Installed" && rm -rf /var/www/html/admin/modules/concurrencycount; fwconsole chown && fwconsole reload
```

### Option 4: Clean reinstall from GitHub

For a clean reinstall from GitHub on a PBX:

```
cd /var/www/html/admin/modules && fwconsole ma uninstall concurrencycount && rm -rf concurrencycount && git clone https://github.com/kierknoby/concurrencycount.git && fwconsole ma install concurrencycount && fwconsole chown && fwconsole reload
```

## Architecture

Concurrency Count has three main paths:

1. **Normal report path** fetches answered PJSIP CDR rows from `asteriskcdrdb`, then passes the already-fetched rows to the selected calculation engine.
2. **Engine path** calculates the same result shape for every engine. `Original` is the reference implementation and default. `Sweep` is experimental and exists to compare a faster event-based strategy against the reference behaviour.
3. **Demo path** generates deterministic synthetic rows, inserts them with a unique `CCDEMO*` accountcode, runs the normal CDR-backed report query against those rows, compares the actual result against an independent expected calculation, and removes the rows.

The expected demo calculation deliberately does not share engine code. That keeps the accuracy check useful: if an engine is wrong, the demo harness can catch it instead of repeating the same mistake.

## Security model

- Normal trunk, extension, and group reports are read-only against CDR.
- AJAX commands use a fixed command allowlist rather than arbitrary method dispatch.
- User-entered modes, dates, engines, demo sizes, seeds, row counts, and email addresses are validated before use.
- SQL uses prepared statements for user-supplied values.
- The default engine is always `original`; experimental engines must be selected explicitly.
- Demo mode warns before use, writes only tagged synthetic rows with a `CCDEMO*` accountcode, and verifies cleanup at the end of the run.

Current limitation: demo mode is not yet protected by a dedicated FreePBX permission or feature flag. Treat it as an administrator/test-PBX workflow until that gate exists.

## Modes

**Trunk:** maximum concurrent calls per PJSIP trunk. Trunk names must contain alphabetic characters; numeric trunk names are counted as extensions.

**Extension:** maximum concurrent calls per PJSIP extension.

**Group:** overall maximum concurrent calls across all extensions, counting both legs of each call.

**Demo:** built-in synthetic fixture. It temporarily writes tagged rows to the live CDR table, runs the normal CDR-backed report path against those rows, verifies the result, then removes them.

## Engines

**Original** is the default and recommended engine. It preserves the original per-second occupancy calculation used by the bash tool.

**Sweep** is experimental. It calculates concurrency from call start/end events rather than walking every occupied second. It is intended to be faster on large demo fixtures, but should be treated as experimental unless its demo accuracy check passes.

## Wizard flow (mirrors the CLI)

1. **Mode.** Accepts trunks/extensions/group, plus abbreviations (t, ext, g, etc.). Demo runs use the separate **Run Demo** button.
2. **Date range.** Type a month name, `today`, `yesterday`, or leave blank for a custom range.
3a. **Year** if a month was given. Accepts YYYY, YY, or Y.
3b. **Start date** then **end date** if blank was given. Each accepts YYYY-MM-DD HH:MM:SS, YYYY-MM-DD, YYYY-MM, YYYY, YY, Y, or blank.

Three attempts per step before the session aborts. If estimated runtime exceeds 3600 seconds, a warning modal asks whether to continue.

## Output

After a run, three options:

- View the table inline.
- Download as CSV.
- Email the report with CSV attachment.

Normal report runs do not persist data to disk or database. Demo runs temporarily insert tagged synthetic CDR rows and remove them after the run.

## Command-line use

```
fwconsole concurrencycount --mode=trunk --start="2026-04-01 00:00:00" --end="2026-04-30 23:59:59"
fwconsole concurrencycount --mode=extension --start=today --end=today --engine=original
fwconsole concurrencycount --mode=group --start="2026-04-01 00:00:00" --end="2026-04-30 23:59:59" --csv
fwconsole concurrencycount --mode=demo
fwconsole concurrencycount --mode=demo --engine=sweep
fwconsole concurrencycount --mode=demo --compare=original,sweep
```

Same mode abbreviations and shorthand dates as the wizard.

## Demo mode

For a test PBX with no useful sample CDRs, click **Run Demo** on the module page. Move in the randomise box to vary the synthetic call pattern, then run a trunks, extensions, or group simulation. A fresh seed is created each time the demo window opens, and the randomiser chooses the date range and load automatically. Light creates a small smoke-test dataset, Medium creates a busy realistic dataset, and Heavy creates thousands of calls and may take several minutes.

```
fwconsole concurrencycount --mode=demo --demo-report=extension --demo-size=medium --demo-seed=12345
```

Demo mode temporarily inserts tagged synthetic CDR rows, calculates the expected output from those generated rows, runs the normal CDR-backed report path against those rows only, compares expected against actual, then removes the demo rows automatically. The result shows the demo run id, seed, accuracy status, rows inserted, rows removed, and cleanup remaining count so cleanup can be verified.

Demo rows use an accountcode beginning with `CCDEMO`. Cleanup is performed in a `finally` block and verified at the end of a normal run, but it is still best-effort: a PHP fatal error, web-server kill, database interruption, or host crash could leave tagged demo rows behind. Until a dedicated FreePBX permission/feature gate and orphan-cleanup command are added, demo mode should be treated as an administrator/test-PBX feature rather than a general user workflow.

## Future hardening

These are intentionally not hidden:

- Add a dedicated FreePBX permission or module setting that must be enabled before demo mode can write CDR rows.
- Add an orphan cleanup command for old `CCDEMO*` rows, with a dry-run preview.
- Consider wrapping demo insert/query/cleanup in a transaction if it proves safe with the deployed CDR engine and FreePBX environment.
- Add integration tests on a real FreePBX 17 system for email delivery, CDR schema variation, and module-page permissions.

## AI disclosure

This module has been developed with AI assistance for code generation, review, testing, and documentation. Changes should still be reviewed, tested, and accepted by a human maintainer before deployment.

## Tests

The engine parity harness can be run without a FreePBX install:

```
php -d xdebug.mode=off tests/EngineParityTest.php
```

If PHPUnit is available, run the full test directory:

```
./vendor/bin/phpunit tests/
```

## Notes

Only answered calls (`disposition = 'ANSWERED'`) are counted. A ringing call is not a concurrent call.

The standalone CLI tool (`concurrency-count` via IN1CLICK) remains the recommended option for SSH-based use. It has interactive prompts at the terminal, real-time progress reporting, and pause-on-overrun confirmation.

## Licence

GPLv3+. See LICENSE.

## Author

@kierknoby, Kieran Byrne // FreePBX UK

# Concurrency Count for FreePBX 17

Maximum concurrent PJSIP calls per trunk, extension, or group across a date range. Read-only against `asteriskcdrdb`.

This is the FreePBX module companion to the Concurrency Count CLI tool (`concurrency-count`) shipped via IN1CLICK by 20tele.com. The web interface uses a wizard modal that mirrors the CLI flow, including the same modes, the same shorthand date entry (today, yesterday, month names, Y/YY/YYYY years), the same three-attempt retry behaviour, and the same runtime-overrun warning.

## Requirements

- FreePBX 17 or later
- PJSIP channel driver (no chan_sip support)
- Asterisk CDR enabled and writing to `asteriskcdrdb`

## Installation

Place the `concurrencycount` directory in `/var/www/html/admin/modules/`, then:

```
fwconsole ma install concurrencycount
fwconsole chown
fwconsole reload
```

The module appears under **Reports > Concurrency Count**.

## Modes

**Trunk:** maximum concurrent calls per PJSIP trunk. Trunk names must contain alphabetic characters; numeric trunk names are counted as extensions.

**Extension:** maximum concurrent calls per PJSIP extension.

**Group:** overall maximum concurrent calls across all extensions, counting both legs of each call.

**Demo:** built-in synthetic fixture. Does not read or write CDR data.

## Wizard flow (mirrors the CLI)

1. **Mode.** Accepts trunks/extensions/group/demo, plus abbreviations (t, ext, g, d, etc.).
2. **Date range.** Type a month name, `today`, `yesterday`, or leave blank for a custom range.
3a. **Year** if a month was given. Accepts YYYY, YY, or Y.
3b. **Start date** then **end date** if blank was given. Each accepts YYYY-MM-DD HH:MM:SS, YYYY-MM-DD, YYYY-MM, YYYY, YY, Y, or blank.

Three attempts per step before the session aborts. If estimated runtime exceeds 3600 seconds, a warning modal asks whether to continue.

## Output

After a run, three options:

- View the table inline.
- Download as CSV.
- Email the report with CSV attachment.

No data is persisted to disk or database. Each run is fresh.

## Command-line use

```
fwconsole concurrencycount --mode=trunk --start="2026-04-01 00:00:00" --end="2026-04-30 23:59:59"
fwconsole concurrencycount --mode=group --start="2026-04-01 00:00:00" --end="2026-04-30 23:59:59" --csv
fwconsole concurrencycount --mode=demo
```

Same mode abbreviations and shorthand dates as the wizard.

## Demo mode

For a test PBX with no useful sample CDRs, click **Run Demo** on the module page. Move in the randomise box to vary the synthetic call pattern, then run a trunks, extensions, or group simulation. A fresh seed is created each time the demo window opens, and the randomiser chooses the date range and load automatically. Light creates a small smoke-test dataset, Medium creates a busy realistic dataset, and Heavy creates thousands of calls and may take several minutes.

```
fwconsole concurrencycount --mode=demo --demo-report=extension --demo-size=medium --demo-seed=12345
```

Demo mode temporarily inserts tagged synthetic CDR rows, calculates the expected output from those generated rows, runs the normal CDR-backed report path against those rows only, compares expected against actual, then removes the demo rows automatically. The result shows the demo run id, seed, accuracy status, rows inserted, rows removed, and cleanup remaining count so cleanup can be verified.

## Notes

Only answered calls (`disposition = 'ANSWERED'`) are counted. A ringing call is not a concurrent call.

The standalone CLI tool (`concurrency-count` via IN1CLICK) remains the recommended option for SSH-based use. It has interactive prompts at the terminal, real-time progress reporting, and pause-on-overrun confirmation.

## Licence

GPLv3+. See LICENSE.

## Author

20 Telecom Ltd, trading as 20tele.com. Wales, UK.

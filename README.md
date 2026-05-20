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

## Wizard flow (mirrors the CLI)

1. **Mode.** Accepts trunks/extensions/group, plus abbreviations (t, ext, g, etc.).
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
```

Same mode abbreviations and shorthand dates as the wizard.

## Notes

Only answered calls (`disposition = 'ANSWERED'`) are counted. A ringing call is not a concurrent call.

The standalone CLI tool (`concurrency-count` via IN1CLICK) remains the recommended option for SSH-based use. It has interactive prompts at the terminal, real-time progress reporting, and pause-on-overrun confirmation.

## Licence

GPLv3+. See LICENSE.

## Author

20 Telecom Ltd, trading as 20tele.com. Wales, UK.

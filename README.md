# Concurrency Count for FreePBX/PBXact 16 and 17

Supports FreePBX/PBXact 16 and 17.

Report peak PJSIP trunk use, overlapping CDRs assigned to individual extensions, or PBX-wide numeric extension-leg activity across a date range. Normal report runs are read-only against `asteriskcdrdb`; demo mode temporarily writes tagged synthetic rows to CDR and removes them after the run.

This is the FreePBX module companion to the Concurrency Count CLI tool (`concurrency-count`) - NOT CURRENTLY SUITABLE FOR PRODUCTION. The web interface provides presets, native custom date controls, optional time selection, and previous/next range navigation. Demo mode is launched separately through **Run Demo** because it writes temporary synthetic CDR rows.

## Requirements

- FreePBX 16 or 17
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

Concurrency Count has four main paths:

1. **Normal report path** fetches answered PJSIP CDR rows from `asteriskcdrdb`, then passes the already-fetched rows to the selected calculation engine.
2. **Engine path** calculates the same result shape for every engine. `Original` is the reference implementation and default. `Sweep` is experimental and exists to compare a faster event-based strategy against the reference behaviour.
3. **Demo path** generates deterministic synthetic rows, inserts them with a unique `CCDEMO*` accountcode, runs the normal CDR-backed report query against those rows, compares the actual result against an independent expected calculation, and removes the rows.
4. **Trunk detail path** runs after the engine. `PeakDetailAnalyser` receives only the same compact `calldate`, `duration`, and selected trunk-channel rows as the engines and groups the trunk's maximum into continuous occurrences. Full CDR fields are fetched by authenticated AJAX only when an occurrence is expanded.

The expected demo calculation deliberately does not share engine code. That keeps the accuracy check useful: if an engine is wrong, the demo harness can catch it instead of repeating the same mistake.

## Security model

- Normal trunk, extension, and group reports are read-only against CDR.
- AJAX commands use a fixed command allowlist rather than arbitrary method dispatch.
- Every AJAX command requires the module CSRF token, an authenticated FreePBX session, and `allowremote = false`.
- User-entered modes, dates, engines, demo sizes, seeds, row counts, and email addresses are validated before use.
- SQL uses prepared statements for user-supplied values.
- The default engine is always `original`; experimental engines must be selected explicitly.
- Demo mode warns before use, writes only tagged synthetic rows with a `CCDEMO*` accountcode, and verifies cleanup at the end of the run.

Current limitation: demo mode is not yet protected by a dedicated FreePBX permission or feature flag. Treat it as an administrator/test-PBX workflow until that gate exists.

## What concurrent means

Concurrency Count works from completed CDR records rather than live Asterisk channels. For an included CDR, the occupied interval runs from `calldate` through `calldate + duration`, including both boundary seconds. If one included call ends at exactly the second another begins, they overlap for that second.

Only CDRs with an `ANSWERED` disposition and a start time inside the selected range are included. A call that starts before the range is not included even if it continues into the range. Never-answered calls are excluded, but an answered CDR is counted using its full recorded duration, not `billsec`; setup, ringing or queue time contained in that ultimately answered CDR can therefore contribute.

## Reporting modes

### Trunk Concurrency

**What it measures:** Trunks reports the busiest simultaneous use of each discovered non-numeric PJSIP trunk endpoint. It counts matching trunk legs from both `channel` and `dstchannel`. A peak of 4 for a trunk means that, at the busiest instant in the selected period, four included CDR legs were occupying that trunk simultaneously. It is a trunk-capacity measure, not necessarily four answered conversations at that instant.

**How calls are identified:** The module discovers PJSIP endpoints from Asterisk and treats non-numeric endpoint names as trunks. It then accepts only CDR legs whose channel name exactly belongs to one of those discovered trunks. Purely numeric trunk names are treated as extensions by the established matching rules and should be avoided. If the same trunk appears on both sides of one CDR, both matching legs can count.

**What concurrent means here:** Matching legs for the same trunk have overlapping inclusive CDR intervals. Trunks are evaluated separately; activity on another trunk does not add to this trunk's peak.

**What the result means:** The table gives a separate maximum for every discovered trunk, including trunks with no matching calls. The global maximum is the largest of those per-trunk peaks. For example, suppose a trunk carries three inbound calls and two outbound calls during a day. At 14:32, two inbound and two outbound trunk legs overlap, while the fifth call occurs later. That trunk's peak is 4.

**Peak drill-down:** Each trunk has its own peak occurrences. One uninterrupted period at the trunk's maximum is one occurrence, not one row per second. If the peak is reached, drops, and is reached again, those are separate occurrences. Expanding an occurrence lazily loads the CDRs whose trunk legs overlap it. Direction is based on the actual trunk-leg position: trunk in `channel` is inbound, trunk in `dstchannel` is outbound, and ambiguous placement is shown as unknown rather than guessed from telephone numbers.

A peak of 4 always means four trunk legs were active at each instant represented as peak. The complete occurrence can nevertheless show five distinct CDR records: one call may end while another starts at the same boundary second, replacing it without the concurrency falling below 4. This is participation across the continuous occurrence, not a claim that all five were active together.

Where a CDR proves a FreePBX object, the drill-down links to its native administration page. **View in CDR Reports** opens the native CDR report narrowed to the call minute, caller number, destination and DID. Unresolved values remain ordinary text.

### Extension Concurrency

**What it measures:** Extensions reports a separate maximum for each numeric PJSIP extension selected from CDR data. A peak of 2 for extension 203 means two included CDR records assigned to extension 203 overlap at their busiest instant.

**How calls are identified:** Each answered CDR contributes to at most one extension. The destination PJSIP leg is preferred when it is numeric; otherwise the source PJSIP leg is used when numeric. This allows inbound, outbound and internal CDRs to contribute, but an internal CDR with numeric extensions at both ends is assigned only to its destination extension in this mode. The established query also excludes CDR destinations beginning with `1` or `9`.

**What concurrent means here:** CDR intervals assigned to the same extension overlap under the shared inclusive rule. Records assigned to different extensions are not added together in the per-extension peak.

**What the result means:** The table reports each selected extension separately; the global maximum is the highest peak reached by any one extension, not the total across the PBX. For example, extension 203 may have one inbound CDR and one outbound CDR whose recorded intervals overlap for 20 seconds. Extension 203 then has a peak of 2. Extension mode does not currently provide the trunk occurrence/CDR drill-down.

### Overall Extension Concurrency

**What it measures:** The internal mode name is `group`, but it does not mean a configured FreePBX Ring Group, queue, department or chosen member list. It is a PBX-wide total of all numeric PJSIP extension legs found in the selected answered CDRs.

**How calls are identified:** Every numeric PJSIP side in `channel` and `dstchannel` contributes independently. One CDR can therefore add two to the total when both sides are numeric extensions, as with an internal call. There is no configurable membership and no per-member result in this mode. To limit anomalously long data, each CDR contributes for at most 24 hours.

**What concurrent means here:** All overlapping numeric PJSIP legs are added into one PBX-wide total using the shared inclusive boundary rule. This is the only current mode that aggregates different extensions into one peak.

**What the result means:** The single reported maximum is the largest number of numeric extension legs active across the PBX at the same instant. Peak time ranges show when that overall maximum was sustained. For example, an internal call from 201 to 202 contributes two legs; at the same time extension 203 is on an external call and contributes one leg. Overall Extension Concurrency is 3, even though there are only two CDR conversations. This mode does not currently provide contributing-call drill-down.

### Trunk capacity versus overall extension activity

Trunk Concurrency and Overall Extension Concurrency are different views of activity and should not be added together. An inbound call may contribute a trunk leg to the selected trunk's external-capacity result and a numeric PJSIP extension leg to the PBX-wide extension-side result. Trunk mode asks how much external SIP capacity was in use; Overall mode asks how much numeric extension-side activity was occurring. The exact CDR topology determines which legs appear in each view.

### Demo and engine comparison

Demo is an accuracy and performance test, not a fourth live reporting scope. It creates isolated synthetic CDRs, runs the chosen Trunk, Extension or Group-total calculation through the normal CDR path, compares the result with an independently calculated expectation, and removes the synthetic rows. In the GUI, selecting more than one demo engine enables **Compare Engines**, which shows accuracy, wall time, peak memory and throughput for each selected engine. Comparison does not change what the selected reporting mode measures.

## Engines

Engines are calculation strategies, not reporting modes. Choosing an engine does not change which CDRs Trunk, Extension or Group mode measures or what its peak means.

**Original** is the default, recommended reference implementation. It walks every included second of every selected CDR leg. Administrators should normally leave Original selected because it is the established behaviour against which alternatives are checked.

**Sweep** is experimental. It processes start and end events instead of visiting every occupied second, while preserving the same inclusive boundaries and intended result. It can be faster and use less memory, but should be treated as experimental and checked through Demo comparison before operational use.

## GUI report flow

1. Choose Trunk Concurrency, Extension Concurrency or Overall Extension Concurrency, then choose an engine. Demo runs use the separate **Run Demo** button.
2. Choose a range preset:
	- **Today:** midnight through the current time.
	- **Yesterday:** the complete previous calendar day.
	- **Last 7 days:** today and the previous six calendar days.
	- **Last 30 days:** today and the previous 29 calendar days.
	- **This month:** the first day of the current month through now.
	- **Custom:** user-selected From and To dates.
3. Custom exposes native From/To date inputs. **Include time** optionally exposes From/To time inputs.
4. Previous/next moves by the displayed inclusive day span. Month ranges move by calendar month.
5. The browser resolves these controls to canonical `YYYY-MM-DD HH:MM:SS` start/end values before calling the existing report path. Date-only ranges start at `00:00:00`; past dates end at `23:59:59`; today ends at the current time.

The three reporting questions are presented as native radio choices with concise scope, use and peak examples. Trunk Concurrency remains the first and default choice because external capacity is the clearest operational starting point and Trunk mode provides the richest peak occurrence and CDR drill-down. Original remains the default calculation engine independently of that reporting choice.

If estimated runtime exceeds 3600 seconds, a warning modal asks whether to continue.

## Trunk peak drill-down

For each trunk, the initial result contains only peak occurrence metadata. An occurrence is one continuous period where concurrency equals that trunk's maximum. Calls occupy the inclusive interval from `calldate` through `calldate + duration`, matching both existing engines; removal occurs at the following second. A call ending exactly when another begins therefore overlaps at that timestamp.

Expanding an occurrence reruns a bounded, prepared CDR query for the selected trunk and report range, reconstructs the occurrence server-side, and rejects the request if its boundaries are no longer present. Direction comes from the actual selected trunk leg: trunk in `channel` is inbound, trunk in `dstchannel` is outbound, and ambiguous placement is shown as unknown. A continuous peak can include more distinct CDRs than the instantaneous peak when one call replaces another without the count dropping.

The call path is deliberately conservative. CDR alone proves the selected trunk leg, DID, source/destination, and directly recorded opposite PJSIP extension. Concurrency Count asks FreePBX's installed `*_getdestinfo` providers for labels and native `edit_url` values and accepts only local `config.php` links. This generic provider layer supports installed destination families including extensions/users, trunks, inbound routes, outbound routes, ring groups, queues, IVRs, announcements, time conditions/groups, conferences, Follow Me, call flow control, miscellaneous/custom applications and destinations, voicemail, and termination destinations when a proven destination string is available. Unresolved or malformed values remain plain text.

This version does not use CEL and does not infer a historic IVR/queue/announcement chain from the PBX's current configuration. CDR does not reliably prove those intermediate stages. Optional CEL enrichment remains a future enhancement and Concurrency Count continues to work from CDR alone.

**View in CDR Reports** uses the native report form shared by FreePBX CDR releases 16 and 17. There is no supported common single-CDR route: `action=cel_show&uid=...` is CEL-specific and only available when CEL is enabled. The module therefore POSTs `need_html=true`, the exact call minute, and exact standard caller-number/destination/DID filters to `config.php?display=cdr`. It does not invent a `uniqueid` query parameter.

## Reporting limitations

- Reports are reconstructed from stored CDR data, not a live channel counter. Missing, incomplete or unusual CDR records can change the result.
- Only answered CDRs whose start time is inside the requested range are selected. A call already in progress at the range start is not included.
- The module uses recorded `calldate` and `duration`. It does not claim that every counted second was billable or connected speech.
- CDR alone does not prove every intermediate IVR, announcement, queue or routing stage. The module shows only observed objects it can identify reliably and does not reconstruct a historical path from today's configuration.
- Native FreePBX links are emitted only when the destination provider resolves a known local object and safe administration route. Otherwise the value remains plain text.
- Trunk direction can be **unknown** when the CDR leg placement is ambiguous.
- CEL is neither required nor used. It may be considered later as optional enrichment, but current reports continue to work from CDR alone.
- Extension and Group-total modes do not currently provide the peak CDR drill-down available for trunks.

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

The CLI keeps its existing option names, accepted date syntax, textual output, engine behavior, and exit behavior. GUI date controls do not change CLI parsing, and drill-down output is not added to fwconsole.

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
- Add integration tests on real FreePBX 16 and 17 systems for email delivery, CDR schema variation, and module-page permissions.

## AI disclosure

This module has been developed with AI assistance for code generation, review, testing, and documentation. Changes should still be reviewed, tested, and accepted by a human maintainer before deployment.

## Tests

The engine parity harness can be run without a FreePBX install:

```
php -d xdebug.mode=off tests/EngineParityTest.php
php tests/PeakDetailAnalyserTest.php
php tests/FreepbxEntityResolverTest.php
php tests/concurrencycount_console_contract.php
node tests/DateRangeTest.js
```

If PHPUnit is available, run the full test directory:

```
./vendor/bin/phpunit tests/
```

## Notes

Only CDRs with `disposition = 'ANSWERED'` are selected. Their full recorded `duration` is counted from `calldate`, so setup, ringing or queue time inside an ultimately answered CDR may contribute; a never-answered CDR does not.

The standalone CLI tool (`concurrency-count` via IN1CLICK) remains the recommended option for SSH-based use. It has interactive prompts at the terminal, real-time progress reporting, and pause-on-overrun confirmation.

## Licence

GPLv3+. See LICENSE.

## Author

@kierknoby, Kieran Byrne // FreePBX UK

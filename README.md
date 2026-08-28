# Concurrency Count 2.1.1 for FreePBX/PBXact 16 and 17

**NOT CURRENTLY SUITABLE FOR PRODUCTION.**

## What Concurrency Count does

Concurrency Count shows how much PJSIP calling capacity is being used now and how much eligible activity was present historically.

- **Live View** asks how many attributable PJSIP trunk legs are active now.
- **Historical Reports** ask which eligible answered CDR activity overlapped during a selected period.
- **Live Wall** is a read-only large-screen presentation of the same current snapshot as Live View.

Historical Reports provide three measurements:

| Mode | Question answered |
| --- | --- |
| **Trunk Concurrency** | How much external SIP trunk capacity was simultaneously occupied? |
| **Extension Concurrency** | How many overlapping answered CDRs were assigned to one extension? |
| **Group Concurrency** | How many attributable extension-side legs were active across the PBX? |

The module also provides Live thresholds and unattended notifications, persistent Historic Report workspaces, PJSIP Endpoint Classifications, reversible Excluded Calls, graphs, CSV/email output, and GUI and CLI management.

## Requirements

- FreePBX 16 or 17
- PJSIP channel driver; there is no chan_sip support
- Asterisk CDR enabled and writing to `asteriskcdrdb`

## Installing

Concurrency Count is currently unsigned and is not available from the normal FreePBX online module repository. Its module path is `/var/www/html/admin/modules/concurrencycount/`.

### Option 1: Install from pre-staged module files

If the module files already exist at `/var/www/html/admin/modules/concurrencycount/`, run the FreePBX commands from a neutral directory:

```bash
cd ~
fwconsole ma install concurrencycount
fwconsole chown
fwconsole reload
```

The module appears under **Reports > Concurrency Count**.

### Option 2: Install from GitHub

Git must be installed before cloning the module.

On FreePBX 16 or PBXact 16 with CentOS 7, check for Git:

```bash
rpm -q git
```

If it is missing:

```bash
yum install -y git
```

On FreePBX 17 or PBXact 17 with Debian 12, check for Git:

```bash
dpkg -l git
```

If it is missing:

```bash
apt update
apt install -y git
```

Clone the repository's default branch, then leave the Git repository before running `fwconsole`:

```bash
cd /var/www/html/admin/modules
git clone https://github.com/kierknoby/concurrencycount.git concurrencycount
cd ~
fwconsole ma install concurrencycount
fwconsole chown
fwconsole reload
```

### Option 3: Install from a local copy

Copy or link a local `concurrencycount` directory so that the complete module is available at `/var/www/html/admin/modules/concurrencycount/`. Then use the normal module installation path from a neutral directory:

```bash
cd ~
fwconsole ma install concurrencycount
fwconsole chown
fwconsole reload
```

## Updating Concurrency Count

Do not uninstall during a normal update. Uninstalling stops and removes the module workers and deletes module-owned configuration and state; it is not an update mechanism.

Check the installed and staged versions before and after updating:

```bash
fwconsole ma list | grep -i concurrencycount
grep "<version>" /var/www/html/admin/modules/concurrencycount/module.xml
```

### Update from pre-staged files

After replacing the files under `/var/www/html/admin/modules/concurrencycount/`, run:

```bash
cd ~
fwconsole ma install concurrencycount
fwconsole chown
fwconsole reload
```

Run the version checks again after the update.

### Update from GitHub

After `fwconsole chown`, Git may report `detected dubious ownership` when root next accesses the repository because FreePBX has assigned the module directory to its web user. Explicitly trust this one repository for the current user:

```bash
git config --global --add safe.directory /var/www/html/admin/modules/concurrencycount
```

This changes Git's trust configuration only; it does not change module directory ownership. Do not use a wildcard safe-directory rule or recursively change the FreePBX-owned directory back to root.

The supported GitHub update workflow replaces tracked files with the current `main` branch and removes untracked files. Back up any intentional local changes first: `git reset --hard` discards tracked modifications, and `git clean -fd` deletes untracked files and directories inside the module repository.

```bash
cd /var/www/html/admin/modules/concurrencycount
git fetch origin main
git reset --hard origin/main
git clean -fd
cd ~
fwconsole ma install concurrencycount
fwconsole chown
fwconsole reload
```

Run the version checks again after the update.

### Update from a local copy

Replace the module files from the local copy, preserving any deployment-specific changes deliberately, then run:

```bash
cd ~
fwconsole ma install concurrencycount
fwconsole chown
fwconsole reload
```

Run the version checks again after the update.

### Git dubious-ownership troubleshooting

If Git reports `detected dubious ownership` for the module repository after `fwconsole chown`, explicitly trust only that repository for the current user:

```bash
git config --global --add safe.directory /var/www/html/admin/modules/concurrencycount
```

This changes Git's trust configuration only; it does not change module directory ownership.

## How the module thinks about concurrency

Live values come from the current Asterisk channel snapshot. Historical values are reconstructed from completed CDRs. For an included Historical CDR, the occupied interval runs from `calldate` through `calldate + duration`, including both boundary seconds. A CDR ending at exactly the second another begins overlaps with it at that timestamp.

Historical reporting includes only CDRs with an `ANSWERED` disposition whose start time is inside the selected range. A call already in progress when the range begins is not included. An included CDR uses its full recorded `duration`, not `billsec`; setup, ringing or queue time within an ultimately answered CDR can therefore contribute. This is not a claim about billable time or connected speech.

Historical presentation follows this rule:

| Exact calculated peak | Meaning |
| --- | --- |
| 0 | No relevant activity |
| 1 | Activity only; no eligible calls or legs overlapped |
| 2 or more | Concurrency |

The engines do not rewrite 1 as 0: an exact peak of 1 means activity occurred, and concurrency begins at 2. Graphs and raw output may therefore contain 1 even though the GUI does not call one active CDR or leg concurrent.

## PJSIP endpoint identity

Concurrency Count classifies actual PJSIP endpoint names rather than guessing from digits or letters:

- authoritative trunks come from FreePBX Core's configured PJSIP trunk inventory;
- authoritative devices/extensions come from FreePBX `devices` rows whose technology is PJSIP;
- numeric trunk channelids and alphanumeric PJSIP device IDs are supported.

Classification precedence is:

1. an endpoint configured as both a trunk and device is a conflict;
2. a configured FreePBX trunk is a trunk;
3. a configured FreePBX PJSIP device is an extension;
4. a remembered manual override applies to an otherwise unknown endpoint;
5. anything else remains unresolved.

Authoritative FreePBX configuration supersedes a remembered manual classification. A trunk/device collision remains a conflict and cannot be hidden by an override.

Historical endpoint identity comes from the PJSIP endpoint recorded in CDR `channel` and `dstchannel`. The `dst` field is dialled-number metadata and is never used to infer whether an endpoint is a trunk or extension. Dialled values such as 999, 911, 111 or another 1XX value therefore neither create an extension nor suppress a genuine configured extension leg.

Unknown or deleted endpoints seen in Historical CDRs appear as endpoint anomalies in the report and are excluded from classification-dependent totals until an administrator selects **Treat as Trunk**, **Treat as Extension** or **Ignore**. Remembered choices can be reviewed and reset under **PJSIP Endpoint Classifications**. These reversible choices affect Concurrency Count only; they do not change FreePBX, Asterisk or source CDRs. Administrators can reset one classification or all classifications.

## Historical reporting

Historical Reports query candidate answered PJSIP CDR rows, remove globally excluded logical calls, classify endpoint sides, apply the selected reporting mode, and pass the same eligible dataset to Original or Sweep.

### Trunk Concurrency

Trunk Concurrency measures matching trunk legs from both CDR `channel` and `dstchannel`. If the same trunk appears on both sides of one CDR, both matching legs can count. Trunks are evaluated separately.

- peak 0: no relevant activity; the trunk is absent from the displayed hierarchy;
- peak 1: the trunk appears under **Show activity-only results**;
- peak 2 or more: the trunk appears in the primary concurrency results.

An activity-only Trunk retains the complete Trunk detail model. Its **Activity occurrences**, lazy contributing-call detail, direction, source/destination, FreePBX entity links, **View in CDR Reports** and **Exclude Call** remain available. Collapsing the disclosure is presentation-only and does not discard loaded detail.

For a concurrency result, an occurrence is one continuous period during which that trunk remains at its exact maximum. If the maximum is reached, drops and is reached again, those are separate occurrences. A continuous occurrence can include more distinct CDRs than its instantaneous peak when one CDR ends as another begins without the count dropping.

Peak and Activity occurrence rows use a neutral white nested surface in both FreePBX and PBXact while the parent Trunk result retains its contextual highlighting. Occurrence headings use British-readable dates, repeat the ending date only for a cross-day range, and initially show at most five occurrences per Trunk. **Show N more** and **Show less** change presentation only: any contributing-call detail already loaded lazily remains available without being discarded or refetched.

Direction comes from actual trunk-leg placement: a matching trunk in `channel` is inbound, a matching trunk in `dstchannel` is outbound, and ambiguous placement is unknown.

### Extension Concurrency

Extension Concurrency assigns at most one extension to each eligible answered CDR. A classified destination endpoint is preferred; otherwise a classified source endpoint is used. Records assigned to different extensions are not combined.

- peak 0: no relevant activity for that extension;
- peak 1: the extension appears under **Show activity-only results**;
- peak 2 or more: the extension appears in the primary concurrency results.

Extension mode does not currently provide Trunk-style occurrence and contributing-CDR drill-down.

### Group Concurrency

The internal mode name is `group`, but it does not mean a configured FreePBX Ring Group, queue, department or selected member list. Group Concurrency is one PBX-wide count of attributable classified extension legs.

Each extension-classified side in CDR `channel` and `dstchannel` contributes independently. An internal CDR from 201 to 202 can therefore contribute two extension legs. Each CDR contributes for no more than 24 hours to limit anomalously long data.

A Group peak of 1 is activity detected with no concurrency, and its ranges use activity wording. A peak of 2 or more uses normal peak/concurrency wording. Group mode has no contributing-CDR drill-down.

Historical Group Concurrency is not the historical equivalent of Overall Live Concurrency. Group counts classified extension-side CDR legs only. Historical Trunk Concurrency is the closest historical equivalent because both measurements count trunk legs.

### Demo and engine comparison

Engines change how the same eligible Historical dataset is calculated, not what a mode measures.

- **Original** is the default reference implementation. It walks every occupied second.
- **Sweep** is experimental. It processes start and end events while preserving the same inclusive boundaries and intended result.

The GUI's **Compare Engines** Demo workflow checks Original and Sweep against an independently calculated expectation. Exact engine output, including peak 1, is unchanged by activity-only presentation. The dedicated Demo section below describes its CDR writes and cleanup.

### Creating a report

1. Choose Trunk Concurrency, Extension Concurrency or Group Concurrency, then an engine. Trunk and Original are the defaults.
2. Choose **Today**, **Yesterday**, **Last 7 days**, **Last 30 days**, **This month**, **This year**, **Last year** or **Custom**.
3. Custom exposes native From/To dates. **Include time** optionally exposes From/To times.
4. Previous/next moves by the displayed inclusive span; month ranges move by calendar month.
5. The browser resolves the selection to `YYYY-MM-DD HH:MM:SS`. Past date-only ranges end at `23:59:59`; today ends at the current time.

If estimated runtime exceeds 3,600 seconds, the GUI asks whether to continue. Demo uses the separate **Run Demo** workflow.

### Historic Report tabs and persistence

Historical Reports supports at most five open Historic Report tabs. **Start Historical Report** opens configuration without consuming a slot. A slot is allocated only after a validated **Run report** submission; a failed first calculation removes its unused definition. Stable internal IDs and slots are independent of editable names, and closing a tab frees its slot for reuse.

A sixth report is rejected without replacing an existing tab. The five-report limit and slot allocation are enforced atomically by the backend as well as presented in the GUI.

Persisted report-definition fields include:

- name, stable ID and slot;
- mode, engine and selected trunk/extension filter;
- date preset identity and resolved/custom date information;
- Include time and its From/To values;
- active report state where applicable.

Relative presets remain relative: **Last 7 days** is re-resolved against the current date on restore. **Custom** retains exact, valid calendar dates; impossible dates and reversed ranges are rejected.

Endpoint filtering is part of the shared Historical calculation, not a browser-only display filter. A filtered Trunk report calculates only the selected authoritative trunk, and a filtered Extension report calculates only the selected authoritative extension. An empty filter calculates all eligible endpoints for that mode. Group does not support endpoint filtering and does not retain an endpoint filter. If a saved endpoint is no longer authoritative for its report mode, the report remains visibly missing/unresolved and returns no endpoint result rather than silently falling back to all endpoints.

The same validated mode, engine, resolved range, endpoint filter, exclusions and endpoint classifications are used where applicable by initial GUI calculation, persisted regeneration, CSV, email, Historical graph, peak occurrence/detail and Excluded Calls relevance.

Not persisted:

- calculated result rows;
- graph points;
- lazy occurrence call detail;
- browser-only occurrence expansion and disclosure state after a full reload.

Reopening the module restores tab definitions, regenerates the previously active report first, and regenerates others on demand. Historic Report definitions, endpoint classifications, call exclusions, Live/module preferences, thresholds and alert state are persisted in module settings; Historical result payloads are not.

### Graphs, call detail and output

Historical graph points retain exact numeric counts, including 1. Graph state is derived at the selected start boundary, only changes in the displayed range affect that range, and the end-boundary state is explicit; the same inclusive call-interval rules apply. Trunk results expose occurrence timing and lazy contributing-call detail; activity-only Trunks use the same underlying result and detail data, not a reduced summary.

The detail path is conservative. CDR can prove the selected trunk leg, DID, source/destination and a directly recorded opposite PJSIP extension. Concurrency Count asks installed FreePBX `*_getdestinfo` providers for labels and safe local `config.php` edit links. Unresolved values remain plain text. It does not infer a historic IVR, queue or announcement chain from current configuration.

Where the CDR provides a destination that an installed provider can prove, this layer can resolve extensions/users, trunks, inbound and outbound routes, ring groups, queues, IVRs, announcements, time conditions/groups, conferences, Follow Me, call flow control, miscellaneous/custom applications and destinations, voicemail and termination destinations.

**View in CDR Reports** POSTs the supported `need_html=true` form fields with the call minute and standard caller-number, destination and DID filters. It does not invent a `uniqueid` query parameter or depend on the CEL-specific `action=cel_show` route.

Results can be viewed inline, downloaded as CSV or emailed with a CSV attachment. Raw values retain exact peaks; human-readable GUI, email and CLI wording distinguishes Activity only from concurrency. CLI option names remain stable, with 2.1.0 adding explicit date aliases, stricter argument validation and safer operation/health exit behaviour.

### Excluded Calls

**Exclude Call** creates a reversible module-level exclusion for a safely identified logical call. Exclusions are global across every current and future Historical Report, apply to Trunk, Extension and Group, and are honoured by Historical CLI calculations. Live View and Live Wall do not use them.

- Asterisk `linkedid` is preferred. Every row sharing that excluded `linkedid` is removed together.
- `uniqueid` is the fallback when `linkedid` is unavailable.
- Similar calls with different logical identities remain independent.
- **Exclude Call** is available only where a safe logical-call identity exists.
- **Restore** reverses one exclusion; **Restore All** reverses all exclusions.
- Demo calls cannot be persistently excluded.
- A defensive maximum of 5,000 valid exclusions is retained.

Excluding a logical call never deletes or updates source CDR rows. Its persisted informational summary remains visible if the source CDR is removed. Current-report relevance is calculated from actual source rows when available, using the same mode assignment rules as the report. If the source is unavailable, relevance is unavailable rather than guessed from the summary.

Excluded Calls remains a global list. When opened from a Historic Report, calls that would not otherwise be eligible for that report are shown as **Not in scope** and visually de-emphasised rather than hidden. Calls whose source CDR is unavailable show **Relevance unavailable**.

Exclusions and endpoint classifications are independent: classifying an endpoint does not exclude its calls, and excluding a logical call does not change endpoint identity.

### Historical limitations

- Results depend on CDR quality. Missing, incomplete or unusual records can change them.
- A CDR already in progress at the range start is not included.
- Recorded `duration` is not a billing or connected-speech measure.
- CDR does not reliably prove every intermediate IVR, announcement, queue or routing stage.
- CEL is neither required nor used. Optional CEL enrichment remains a possible future enhancement.
- Native FreePBX links are emitted only for safely resolved local objects.
- Trunk direction can be unknown when leg placement is ambiguous.
- Extension and Group modes lack Trunk-style contributing-CDR detail.

## Live View and Live Wall

Live View reads current Asterisk state through the backend; the browser does not access AMI directly. **Live View** and **Historical Reports** are workspace tabs. Switching changes presentation and browser polling, not the PM2 monitor.

### Overall Live Concurrency

Overall Live Concurrency counts current attributable configured or manually classified PJSIP trunk legs, not complete calls or conversations. Device/extension legs are not part of the Live product.

- an outbound external call counts as 1 trunk leg;
- an inbound external call counts as 1 trunk leg;
- a hairpin outbound plus inbound call counts as 2 trunk legs;
- an internal extension-to-extension call counts as 0 trunk legs.

Local and other non-PJSIP channels, unresolved endpoints, ignored endpoints, conflicts and device/extension endpoints are excluded. Hidden, monitoring-stopped and unfeatured trunks still contribute. Presentation and per-trunk monitoring state do not change Overall. Existing Overall thresholds are retained; administrators should review them if they were testing a pre-release build where extension legs were included.

### Live control semantics

These controls are independent and never alter SIP configuration:

| Control | What it changes | What it does not change |
| --- | --- | --- |
| **Hide Trunk** | Normal Live View card visibility | Counts, Overall, thresholds, alerts, monitoring, Historical Reports or SIP configuration |
| **Stop Monitoring** | Unattended per-trunk threshold/recovery evaluation | Current count, threshold configuration, visibility, Overall or SIP configuration |
| **Threshold enabled** | Whether the configured threshold is active | Its value, monitoring, alert preference, current count or SIP configuration |
| **Alert enabled** | Whether a threshold event can notify when master alerts are enabled | Threshold comparison, monitoring, current count, Overall or SIP configuration |

Stopping monitoring clears the trunk's active episode state. Restarting evaluates the next snapshot as a fresh episode. Hidden trunks remain available for Unhide and Start/Stop Monitoring. Visible cards can be reordered by drag or keyboard-operable Move earlier/Move later controls.

Unknown saved channelids are retained but ignored while unavailable, and newly discovered trunks append after the saved order.

### Live Wall

Live Wall is presentation-only: a read-only wallboard using the same latest browser snapshot, rolling history and polling path as Live View. Overall remains primary. Administrators can feature zero to three ordered trunks.

Hidden featured trunks are suppressed without deleting preference. Monitoring-stopped featured trunks still display current data and contribute to Overall. The desktop composition targets Overall plus three equal cards at conventional 1080p and scales or stacks elsewhere.

Launching requests the Fullscreen API when available. Denial leaves the full-page wall active. Browser Esc exits fullscreen but leaves Live Wall active; **Exit Live Wall** returns to Live View.

Preferences use the FreePBX Core PJSIP trunk `channelid`. Changing a trunk channelid can leave saved visibility, order, feature or monitoring preferences attached to the old identifier; automatic migration is not currently performed.

### Recent peak and refresh

**Recent peak** is the maximum in the current browser session's in-memory chart series. It is not the backend threshold-episode peak and resets on reload. A single sample may appear as a narrow spike.

Refresh can be 1, 5, 10, 15, 30 or 60 seconds; default is 5. Requests do not overlap and pause while hidden. The 1-second option is deliberately aggressive and requires load validation. Browser refresh does not control unattended alerts.

## Threshold monitoring and notifications

A persistent PHP worker supervised by FreePBX Process Management (`pm2`) keeps one AMI connection and reacts to `Newchannel`, `Newstate`, `Hangup`, `Rename` and `Masquerade`. Events trigger reconciliation; a full reconciliation also runs every five seconds. Each snapshot has a unique AMI ActionID and is accepted only after its matching `CoreShowChannelsComplete`. An incomplete snapshot is unavailable, never an empty PBX.

PM2 supervises this worker and a separate mail worker. Lifecycle hooks start, stop and restart them with FreePBX/Asterisk. Installation removes the obsolete minute cron line. Health degrades when no recent complete snapshot exists. CLI monitor status distinguishes combined health from the main PM2 process state, and `--restart-monitor` succeeds only when the combined monitor result is healthy rather than merely when the main monitor process is online.

Threshold comparison is `current >= threshold`; zero disables it. Master alerts, per-scope alerts, threshold enablement and recovery preference are distinct. Alert state and a stable outbox entry are persisted atomically before delivery, suppressing repeats through one episode and worker restart while retaining its peak. Stable event IDs prevent duplicate queue records. The mail worker retries with bounded exponential backoff.

Delivery is **at least once**. Failure after mail acceptance but before outbox removal can duplicate an email, although the episode is not forgotten. There is no hidden hysteresis: falling below completes an episode and a later crossing begins another.

AMI loss is not zero. The worker retains state, sends no false recovery, reconnects with exponential backoff up to 30 seconds, reseeds from a successful snapshot and resumes only when data is available.

## GUI and CLI shared capabilities

The GUI and `fwconsole concurrencycount` use the same calculations/services where applicable. Occurrence expansion, Exclude Call selection and Live Wall are GUI workflows; the CLI does not manage every browser preference. Historical CLI calculations honour persisted endpoint classifications and call exclusions.

| Capability | CLI example |
| --- | --- |
| Historical Trunk | `--mode=trunk --start="2026-04-01 00:00:00" --end="2026-04-30 23:59:59"` |
| Historical Extension | `--mode=extension --start=today --end=today --engine=original` |
| Historical Group CSV | `--mode=group --start="2026-04-01" --end="2026-04-30" --csv` |
| Live snapshot / JSON | `--live`, `--live --json` |
| Settings / refresh | `--settings`, `--set-refresh=5` |
| Threshold values | `--set-overall-threshold=30`, `--set-trunk-threshold='gamma=8'` |
| Threshold enablement | `--overall-threshold=on`, `--trunk-threshold='gamma=on'` |
| Alerts | `--alerts=on`, `--overall-alert=on`, `--trunk-alert='gamma=on'` |
| Per-trunk monitoring | `--start-monitoring='gamma'`, `--stop-monitoring='gamma'` |
| Recovery / email | `--recovery=on`, `--alert-email=admin@example.com` |
| Monitor diagnostics | `--monitor-status`, `--restart-monitor`, `--monitor` |
| Historical graph | `--historical-graph=trunk --graph-trunk=gamma --start='...' --end='...' --json` |
| Historic Report definitions | `--list-historical-reports`, `--show-historical-report=2`, `--delete-historical-report=2` |

Prefix examples with `fwconsole concurrencycount`. CLI date boundaries use PBX/server local time: `--start=today` is today at `00:00:00`, `--end=today` is the current time, `--start=yesterday` is yesterday at `00:00:00`, and `--end=yesterday` is yesterday at `23:59:59`.

Omitting `--engine` selects Original; explicit `original` and experimental `sweep` are valid. An explicitly unknown engine is rejected rather than silently falling back to Original. Incompatible management operation classes are rejected before mutation—for example, `--monitor-status --restart-monitor`, `--live --set-refresh=5` or `--list-historical-reports --alerts=off`. `--json` is a modifier, multiple supported settings mutations may be combined, and `--settings` may accompany settings mutations.

Live queries take one snapshot and exit; they do not poll or replace the PM2 worker. The standalone IN1CLICK `concurrency-count` tool remains available for terminal interaction, progress reporting and pause-on-overrun behaviour. Neither interface is universally preferable.

## Demo

Demo is an administrator/test-PBX accuracy and performance workflow. The GUI generates Light, Medium or Heavy Trunk, Extension or Group fixtures. CLI examples are:

```bash
fwconsole concurrencycount --mode=demo --demo-report=extension --demo-size=medium --demo-seed=12345
fwconsole concurrencycount --mode=demo --compare=original,sweep
```

Demo temporarily inserts deterministic CDR rows tagged with a unique `CCDEMO*` accountcode. It calculates an independent expectation, runs the normal CDR-backed path against those rows, compares results and removes them. It reports rows inserted, removed and remaining.

Omitted Demo arguments retain their documented defaults. Explicit invalid Demo report modes, sizes or comparison-engine values are rejected rather than silently replaced; Original remains the default comparison engine and Sweep remains experimental.

Cleanup runs in `finally` and is verified after a normal run, but is best-effort: a fatal error, server kill, database interruption or host crash could leave tagged rows. Demo calls cannot be persistently excluded, and Demo never consumes a Historic Report slot.

Demo lacks a dedicated FreePBX permission or feature flag. Treat it as an administrator/test-PBX feature until that gate and an orphan-cleanup command exist.

## Architecture at a glance

1. Live takes complete backend AMI snapshots and applies PJSIP identity before producing Overall and per-trunk state.
2. Historical queries candidate CDRs, filters exclusions, applies identity and mode assignment, then sends one dataset to Original or Sweep.
3. Graph and Trunk detail derive from Historical data; full detail is fetched lazily with bounded prepared queries.
4. The PM2 AMI worker evaluates thresholds and writes episode/outbox state; a mail worker delivers notifications.
5. Demo inserts isolated tagged CDRs, compares engine results with an independent expectation, then removes fixtures.

## Security model

- AJAX uses a fixed allowlist, not arbitrary method dispatch.
- AJAX requires an authenticated FreePBX session and module CSRF token, with `allowremote = false`.
- Modes, dates, engines, identities, fixture values, row counts and email addresses are validated.
- User-supplied SQL values use prepared statements.
- Normal Historical reporting is read-only against source CDR. Exclusions never update or delete CDR rows.
- Demo is the intentional exception: it temporarily inserts and removes tagged synthetic rows.
- Live reads Asterisk through backend AMI handling; the browser has no direct AMI access.
- Original is the default; experimental engines require explicit selection.

Demo's missing permission/feature gate remains a known limitation.

## Required PBX/browser validation

This is a pre-production checklist, not a claim that these checks have been completed. Exercise FreePBX 16 and 17 where available.

### Historical

- A normal outbound extension call.
- A numeric configured PJSIP trunk and alphanumeric configured PJSIP device.
- Existing or synthetic CDRs with dialled `dst` values 999, 911, 111 and another 1XX; do not place unsafe calls merely to create data.
- An unknown/deleted endpoint; Treat as Trunk, Treat as Extension, Ignore, reset one and reset all.
- Authoritative configuration superseding an override, and a trunk/device collision remaining a conflict.
- Peak 0, peak 1 Activity only and peak 2+ concurrency in all three modes.
- Activity-only Trunk occurrences, lazy detail, CDR Reports and Exclude Call.
- Exclude, Restore and Restore All, including multiple rows sharing one `linkedid` and an independent similar call.
- Source CDR removal after exclusion, retaining summary with relevance unavailable.
- Multiple Historic Report tabs, stable names/IDs, relative and Custom restoration, and lazy regeneration.
- Exclusion/classification changes causing recalculation and the expected presentation transition.

### Live and notifications

- `--monitor-status` remains `ONLINE` after install, `fwconsole restart`, Asterisk restart and upgrade.
- With the browser closed, test sub-minute and sub-30-second crossings and confirm one mail acceptance.
- Hold a crossing for minutes; confirm one initial alert, correct peak and one optional recovery.
- Restart the monitor during a crossing; confirm no duplicate initial alert.
- Interrupt AMI/Asterisk during an episode; confirm no false recovery and normal reconnect.
- Exercise idle, inbound, outbound, mixed and internal calls, multiple/similar trunks and simultaneous crossings.
- Compare GUI state with `fwconsole concurrencycount --live --json`.
- Verify bounded browser history, stale timestamps, hidden-tab pause and clean resume.
- Validate 1-second polling under varied load; retain only if PBX and browser load is acceptable.
- Verify mail content and acceptance without treating acceptance as proof of external delivery.

### Browser and accessibility

- Desktop, tablet and approximately 320px layouts.
- Keyboard operation and visible focus for tabs, date controls, Activity only, occurrences, call actions, trunk ordering, modals and Live Wall exit.
- Screen-reader names and expanded state for disclosures.

## Tests

Standalone tests and contracts include:

```bash
php tests/AlertMonitorCoordinatorTest.php
php tests/AlertOutboxServiceTest.php
php tests/AmiChannelSourceTest.php
php tests/EngineParityTest.php
php tests/FreepbxEntityResolverTest.php
php tests/HistoricalCallExclusionServiceTest.php
php tests/HistoricalEndpointFilterServiceTest.php
php tests/HistoricalReportsServiceTest.php
php tests/InputValidationTest.php
php tests/LiveServicesTest.php
php tests/PeakDetailAnalyserTest.php
php tests/PjsipIdentityServiceTest.php
php tests/SettingsRepositoryTest.php
php tests/concurrencycount_admin_contract.php
php tests/concurrencycount_console_contract.php
php tests/concurrencycount_release_contract.php
node tests/DateRangeTest.js
```

Source checks include:

```bash
node --check assets/js/concurrencycount.js
node --check assets/js/live-view.js
node --check assets/js/date-range.js
node --check assets/js/concurrency-charts.js
find . -path './.git' -prune -o -type f -name '*.php' -print | while IFS= read -r file; do php -l "$file"; done
git diff --check
```

These tests do not replace real PBX/browser validation.

## Future hardening

- Add a FreePBX permission or setting before Demo can write CDR rows.
- Add a dry-run orphan-cleanup command for old `CCDEMO*` rows.
- Consider a Demo transaction only if safe with deployed CDR engines and FreePBX environments.
- Consider event-burst coalescing only with guarantees for prompt first-event and trailing reconciliation so short threshold crossings cannot be missed.
- Bound or replace Original's per-second memory growth without changing its reference result contract.
- Define an automation-safe CLI runtime-overrun confirmation or `--force` policy and a consistent JSON success/error envelope.
- Add FreePBX backup/restore integration for module-owned persisted state.
- Add real FreePBX 16/17 integration coverage for mail, CDR schema variation, permissions and browsers.
- Decompose the main module class in a future minor release rather than during 2.1.0 release hardening.

## Uninstalling

Uninstalling is destructive to Concurrency Count's module-owned state. It stops and removes the alert-monitor and mail-worker processes, removes the legacy monitor cron entry, and drops the module settings table. This deletes saved thresholds and alert settings, Live presentation and monitoring preferences, Historic Report definitions, remembered endpoint classifications, global Historical call exclusions, and module worker state.

There is currently no built-in export for this configuration. Before uninstalling, record any settings, Historic Report definitions, classifications or exclusions that may be needed later. Report CSV output can be retained separately where useful.

Uninstalling does not delete or update source CDR rows. Historical exclusions are module records only; removing them does not remove calls from `asteriskcdrdb`.

When the loss of module-owned state is understood and intended, uninstall the module from a neutral directory and remove its files by absolute path:

```bash
cd ~
fwconsole ma uninstall concurrencycount --force
rm -rf /var/www/html/admin/modules/concurrencycount
fwconsole chown
fwconsole reload
```

## Licence

GPLv3+. See LICENSE.

## AI-assisted contributions and disclosure

AI assistance used for code, review, testing or documentation must be disclosed in each affected commit from 26 August 2026:

```text
Assisted-by: AGENT_NAME:MODEL_VERSION
```

For example: `Assisted-by: OpenAI-Codex:gpt-5.6-sol`

The human contributor remains solely responsible. AI tools must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne // [FreePBX UK](https://github.com/freepbxUK)

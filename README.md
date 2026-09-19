# Concurrency Count 2.3.0

Updated 19 September 2026.

> **Not currently suitable for production**

## Overview

Concurrency Count (`concurrencycount`) helps FreePBX and PBXact administrators understand how much simultaneous calling activity their system is handling.

It provides both a live view of current PJSIP trunk usage and Historical Reports built from Asterisk CDR data, making it easier to answer questions such as:

- How many PJSIP trunk legs are active right now?
- What was the highest simultaneous trunk usage last month?
- When did that peak occur, and which calls contributed to it?
- Are particular extensions regularly handling overlapping calls?
- How much extension-side activity was happening across the PBX at the busiest point?
- Is current trunk usage approaching a level where an administrator should be alerted?

The module has three main areas:

- **Live View** shows current attributable PJSIP trunk-leg concurrency, with per-trunk counts, Overall Live Concurrency, thresholds and unattended alerts.
- **Live Wall** provides a read-only windowed or browser-fullscreen presentation of the same live data for wallboards and monitoring displays.
- **Historical Reports** reconstruct past concurrency from answered CDRs and provide Trunk, Extension and Group measurements, graphs, occurrence detail, exclusions, CSV and email output.

Historical Reports provide three different measurements:

| Mode | What it measures |
| --- | --- |
| **Trunk Concurrency** | Simultaneous external PJSIP trunk legs, useful for understanding trunk capacity. |
| **Extension Concurrency** | Overlapping answered CDRs assigned to an individual extension. |
| **Group Concurrency** | PBX-wide simultaneous extension-side legs, independent of configured FreePBX Ring Groups. |

Concurrency Count does not alter SIP configuration or source CDR records during normal reporting. Historical exclusions and PJSIP Endpoint Classifications are module-owned and reversible. Demo is the deliberate exception: it can temporarily create tagged synthetic CDR rows for accuracy and performance testing, then removes them. Demo is optional and does not need to be enabled for normal Concurrency Count operation.

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

Use this option only when `/var/www/html/admin/modules/concurrencycount/` does not already exist. If the module directory already exists, do not clone over it; use **Update from GitHub** below.

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

After the first installation, `fwconsole chown` may cause Git to reject the module directory because it is owned by the FreePBX web user rather than root. Run the following commands as root:

```bash
cd

git config --global --get-all safe.directory | grep -Fxq '/var/www/html/admin/modules/concurrencycount' \
  || git config --global --add safe.directory /var/www/html/admin/modules/concurrencycount

cd /var/www/html/admin/modules/concurrencycount
git fetch origin
git reset --hard origin/main

grep -m1 '<version>' module.xml
```

Confirm that `module.xml` reports the release you expect before installing it. Then continue:

```bash
fwconsole ma install concurrencycount
cd
fwconsole chown
fwconsole reload
```

`git reset --hard` discards tracked local modifications in the module repository. Back up any intentional local changes before updating.

### Update from a local copy

Replace the module files from the local copy, preserving any deployment-specific changes deliberately, then run:

```bash
cd /var/www/html/admin/modules/concurrencycount
fwconsole ma install concurrencycount
cd
fwconsole chown
fwconsole reload
```

### Verify the update

After any update, confirm that the installed module and its files report the expected version:

```bash
fwconsole ma list | grep -i concurrencycount
grep -m1 '<version>' /var/www/html/admin/modules/concurrencycount/module.xml
```

If either command does not report the expected version, the module has not updated successfully. Correct the update source or module directory first, then rerun the update before troubleshooting anything else.

### If the GitHub update did not complete

If `module.xml` still reports an older version, the source update did not complete. Do not run `fwconsole ma install` repeatedly against the same old files.

First rerun the normal GitHub update:

```bash
cd /var/www/html/admin/modules/concurrencycount
git fetch origin
git reset --hard origin/main
grep -m1 '<version>' module.xml
```

If `git fetch origin` fails with an error such as `fatal: Couldn't find remote ref refs/heads/...`, an older local checkout may have `remote.origin.fetch` pinned to an obsolete development branch.

Inspect the configured fetch refspecs:

```bash
cd /var/www/html/admin/modules/concurrencycount
git config --get-all remote.origin.fetch
```

A normal checkout should include:

```text
+refs/heads/*:refs/remotes/origin/*
```

If the fetch configuration points to an obsolete branch, repair it with:

```bash
cd /var/www/html/admin/modules/concurrencycount

git remote set-url origin https://github.com/kierknoby/concurrencycount.git

git config --unset-all remote.origin.fetch
git config --add remote.origin.fetch '+refs/heads/*:refs/remotes/origin/*'

git fetch --prune origin
git branch -r
```

Confirm that `origin/main` is present, then rerun the stable update:

```bash
cd /var/www/html/admin/modules/concurrencycount
git reset --hard origin/main
grep -m1 '<version>' module.xml

fwconsole ma install concurrencycount
cd
fwconsole chown
fwconsole reload
```

If the fetch or reset fails, fix the Git checkout before running `fwconsole ma install`; otherwise FreePBX will simply reinstall whatever older module files are already on disk.

## Release highlights

### v2.3.0

**Demo security and administration**

- Demo remains visible in its normal GUI location but is **DISABLED by default** because Demo scenarios can write synthetic CDR records into a database that normally contains genuine call records. This default is intentional and does not indicate an incomplete or incorrectly installed module.
- A privileged system administrator must explicitly authorise Demo with `fwconsole concurrencycount demo --enable` before the GUI Demo workflow can operate. Enabling access authorises the GUI only; it does not start a scenario or generate synthetic data.
- `fwconsole concurrencycount demo --disable` rejects new Demo operations without deleting CDR data, and `fwconsole concurrencycount demo --status` reports the authoritative state. The FreePBX GUI cannot grant itself Demo permission.
- The server checks Demo authorisation independently for GUI, AJAX, direct requests, downloads, email, previews and legacy CLI Demo calculations. Normal Live monitoring, Historical reporting, thresholds and alerts do not require Demo and remain available while access is disabled.
- Every Concurrency Count installation or module update initialises Demo access to DISABLED. This is intentional: newly installed module code must not inherit authorisation to generate synthetic CDR records from an earlier module version. Reboots and ordinary service or monitor restarts do not revoke an explicit authorisation; an administrator must run `fwconsole concurrencycount demo --enable` again after an update if Demo is required.

### v2.2.2

**Historical presentation**

- Historic Reports now show a dedicated **Minimum concurrency not reached** empty state when no displayed data reaches the configured floor. Report metadata remains visible, while the normal graph, series controls, explanation and peak summary are suppressed without changing or discarding the complete underlying calculation or exact actual peak.

**Demo safety and deliberate-use gate**

- The previous module-wide pre-production warnings have been removed following release hardening. Demo now opens with a two-page safety warning; the administrator must acknowledge temporary synthetic CDR writes before the Demo controls are shown. Acknowledgement is required each time Demo is opened and is not a persistent setting or permission.
- Demo requires MariaDB. Quiet-period and uninterrupted-run guidance is shown before the controls, while tagged-row exclusion, cleanup and interrupted-run recovery protections remain in effect.
- Page 1 now also offers a **Year** selector (2001-2015, defaulting to 2001) that constrains the synthetic scenario generated for Page 2. The flow is deliberately one-way (Page 1 -> Proceed -> Page 2, no Back); Cancel and reopen Demo to change the initial setup. Year and acknowledgement are never persisted and reset on every fresh opening.

**Live and notification hardening**

- Live threshold values now accept only whole numbers from 0 to 10000; malformed numeric input is rejected.
- FreePBX Email "From:" Address values support both bare addresses and `Display Name <address@example.com>` configurations, including HTML-encoded angle brackets, while preserving the configured display name.
- PJSIP classification guidance now distinguishes configured endpoint identities from numbers that merely appeared in historical CDRs; manual classifications affect Concurrency Count only and do not modify FreePBX, Asterisk or source CDR data. Live Settings alignment is also improved.

### v2.2.1

**Release hardening and Historical reporting**

- Historical eligibility now explicitly requires `ANSWERED` CDRs with `duration > 0`. Zero-duration source rows remain untouched and visible in native FreePBX CDR Reports but cannot contribute to Concurrency Count peaks, graphs or evidence.
- Trunk CSV and email output now include qualifying peak occurrences and contributing logical-call evidence using the same range, endpoint identity, exclusions and exact peak semantics as the GUI. Extension and Group retain their supported summary formats.
- Historic Report tabs now use a dedicated visible grip for mouse drag and keyboard reordering. Presentation order persists independently of stable report identity, slot, calculation state and active selection.
- Historical graph selection now has explicit all-selected, none-selected and partial states, and tooltip hit-testing is restricted to rendered non-zero series segments rather than loose timestamp proximity.
- Excluded Calls now distinguishes individual **Restore**, grouped **Restore All** and destructive global **Reset**, with a wider dialog and clearer warning presentation.

**Demo**

- GUI Demo scenarios are ephemeral. The **Load** selector chooses Light, Medium or Heavy, **Randomise** creates a fresh deterministic scenario for that load, and GUI scenario state is not restored after reload.
- Demo preflight requests are debounced and stale responses are ignored without aborting the current valid request.
- Synthetic-call audit paging uses a dedicated audit token rather than the normal AJAX CSRF token, so later 100-row audit pages remain available after authoritative `CCDEMO` cleanup.
- Runtime disk protection now stops on loss of required live filesystem headroom. Observed filesystem growth is retained as telemetry rather than being an independent abort condition.
- Completed Demo PBX impact resolves to **Low**, **Moderate**, **High** or **Unable to assess** rather than remaining in an intermediate state.
- Extension Demo wording now makes clear that assigned-CDR peaks are synthetic overlapping-record values, not physical endpoint or simultaneous-call capacity.

**Alerts and notifications**

- Alert delivery now leases outbox events before sending and returns failed attempts to a deterministic retry state with bounded exponential backoff and retained diagnostics.
- The restricted mail-worker bootstrap includes FreePBX Core so current configured trunk information can be resolved when preparing notifications.
- Live settings adds **Test email**. Test and production delivery use the same live-settings reload and message-preparation path; Test email supplies an explicit recipient override.
- Test email feedback is shown only for an explicit **Test email** action, while production delivery and retry diagnostics remain internal; failed `CI_Email` sends retain bounded diagnostics for troubleshooting.
- The FreePBX Advanced Settings **Email "From:" Address** is validated before delivery and is used consistently for From, Reply-To and Return-Path handling where supported.

**Live Wall**

- Live Wall has explicit **Full Screen**, **Windowed** and **Exit Live Wall** states. Leaving browser fullscreen through **Windowed** or Esc keeps the wall active; **Exit Live Wall** alone returns to normal Live View.
- Live Wall launch and configuration are unavailable below 768px while normal Live View remains available.
- Windowed Live Wall is edge-to-edge with no decorative browser inset and uses the complete available visual viewport.
- Responsive wall height and density are updated with native `style.setProperty()` calls for compatibility with the jQuery version shipped on the tested FreePBX platform.

### v2.2.0

**Historical calculation safety and control**

- Historical and Demo now expose modelled engine progress rather than projecting completion from a small early sample. ETA remains gated for the first five minutes and is shown as High confidence only after stable forward-progress evidence.
- **PBX Protection** assesses sustained CPU, memory, swap, I/O and observed database-query pressure during the calculation. High or Critical impact can pause work for an administrator decision without discarding completed progress.
- Historic Reports now store a configurable **Maximum runtime** from 5 to 1,440 minutes. An active paused run can be given more time without resetting its calculation identity, elapsed time or completed work.
- **Minimum concurrency** provides a persisted output floor for Historical and Demo presentation while preserving the complete underlying calculation and exact peak.
- Historical CDR acquisition is bounded and database-aware: newer MariaDB/MySQL use supported server-side SELECT execution limits, while legacy MariaDB uses adaptive indexed ranges with explicit capability and index validation rather than unbounded full-table work.
- Worker-owned process telemetry, memory headroom protection and calculation-specific cadence/state handling strengthen long-running Historical and Demo operation without changing Original or Sweep counting semantics.

**Historical graphs and exclusions**

- Historical graphs now use one deterministic multi-series SVG image with a fixed report-window axis, deterministic colours across the complete series inventory, built-in legend and independently identified thresholds.
- Series selection is explicit: fresh graphs start with every available series selected, while **Select All**, **Unselect All** and individual series controls change the one shared graph without changing the underlying result.
- The currently selected graph series can be exported as **SVG, PDF, PNG or JPEG**. Single-series exports retain the series name; large multi-series selections use bounded filenames such as `15-series`.
- Historical peak detail adds **Exclude All** for every eligible logical call contributing to the exact displayed peak occurrence and **Restore Group** for the remaining members of that grouped exclusion, while retaining individual Restore and global Restore All.

**Demo**

- Demo now uses the pinned CDRgen 1.1.0 core to generate deterministic PJSIP-only synthetic traffic, with Light, Medium and Heavy profiles of 1,000, 5,000 and 20,000 calls over exact one-day ranges.
- GUI scenarios use cryptographically random 128-bit identities with deterministic generation numbers, while existing CLI `--demo-seed` workflows remain reproducible.
- Demo generation starts from authoritative FreePBX PJSIP trunk/device inventories, supplementing only the extension side with isolated synthetic fallback identities when too few configured extensions are available. Numeric configured trunks and configured extensions beginning with 1 or 9 retain their authoritative roles.
- The independent Demo expectation derives topology from observable PJSIP channel legs and the exact Demo inventory rather than trusting CDRgen direction/helper metadata.
- Completed Demo results expose generator provenance, dataset identity, traffic mix and generated/inserted/audited/removed/remaining integrity totals. Synthetic-call detail is retained in an authenticated transient server spool and fetched in bounded 100-row pages.
- Demo preflight and cleanup are fail-closed and database-aware, with conservative database/binlog filesystem headroom checks, bounded insertion and cleanup, stale-run recovery, a verified `accountcode` cleanup access path, an independent mandatory-cleanup allowance and a dedicated legacy MariaDB 5.5.65 InnoDB path.

**Live Wall**

- Live Wall adds persisted **Light** and **Dark** presentation with matching chart palettes.
- The wall now follows the visible viewport, retains a bounded inset outside browser fullscreen, consumes the full viewport in fullscreen, and reflows panels and charts after resize, orientation and fullscreen changes.

## Concurrency definitions

Live values come from the current Asterisk channel snapshot. Historical values are reconstructed from completed CDRs. For an included Historical CDR, the occupied interval runs from `calldate` through `calldate + duration`, including both boundary seconds. A CDR ending at exactly the second another begins overlaps with it at that timestamp.

Historical reporting includes only CDRs with an `ANSWERED` disposition, a `duration` greater than zero, and a start time inside the selected range. A call already in progress when the range begins is not included. An included CDR uses its full recorded `duration`, not `billsec`; setup, ringing or queue time within an ultimately answered CDR can therefore contribute. Zero-duration source rows remain untouched and available to native CDR Reports, but represent no occupied interval in Concurrency Count. This is not a claim about billable time or connected speech.

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

**Minimum concurrency** is an inclusive presentation and output floor for Trunk, Extension, Group and Demo reports. Historic Reports default to 2 and cannot be set below 2; a value of 4 shows only detailed entities or periods whose calculated concurrency is 4 or greater. The complete underlying calculation still runs, and its exact actual peak is calculated and preserved internally. If some data reaches the configured minimum, normal filtered Historical presentation continues and graph points below the floor are shown as gaps. If no displayed data reaches the configured minimum, the GUI keeps the report metadata visible and shows a dedicated **Minimum concurrency not reached** empty state instead of the normal graph, legend and series controls, **What this means** explanation or normal peak summary. The empty state explains that Concurrency Count reports observed successful concurrency only and that actual capacity may be limited upstream, by carrier or trunk limits, or by other PBX modules and configuration. This remains distinct from a report with no eligible Historical data. The same floor continues to apply to CSV/download, email, CLI and Demo output. The floor does not affect Live View, CDR acquisition, engine calculations, assessment, telemetry, pause decisions or cleanup.

**Maximum runtime** is stored with each Historic Report in whole minutes. It defaults to 60 minutes and accepts values from 5 through 1440. A GUI calculation starts with that saved allowance; any increase made from the administrator decision dialog applies only to the active run and does not change the report definition.

The floor is stored in the Historical Report definition and restored by **Edit Report**. Changing it reruns the exact calculation. Completed results cannot currently be reused safely because the browser receives only the transformed detail and the server does not retain a separate unfiltered completed result; adding such retention would create a larger result cache outside this scoped feature.

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

- **Original** is the default reference implementation. It walks every occupied second inclusively while discarding its per-second working map after each bounded one-hour window.
- **Sweep** is experimental. It processes start and end events while preserving the same inclusive boundaries and intended result.

The GUI's **Compare Engines** Demo workflow checks Original and Sweep against an independently calculated expectation. Exact engine output, including peak 1, is unchanged by activity-only presentation. The dedicated Demo section below describes its CDR writes and cleanup.

### Creating a report

1. Choose Trunk Concurrency, Extension Concurrency or Group Concurrency, then an engine. Trunk and Original are the defaults.
2. Choose **Today**, **Yesterday**, **Last 7 days**, **Last 30 days**, **This month**, **This year**, **Last year** or **Custom**.
3. Custom exposes native From/To dates. **Include time** optionally exposes From/To times.
4. Previous/next moves by the displayed inclusive span; month ranges move by calendar month.
5. The browser resolves the selection to `YYYY-MM-DD HH:MM:SS`. Past date-only ranges end at `23:59:59`; today ends at the current time.

Each logical run starts with the Maximum runtime saved in its Historic Report definition; new reports default to 60 minutes. A paused administrator may increase that active run's allowance, in whole minutes, to at most 1,440 minutes (24 hours), without changing the saved report definition. The calculation ID, original runtime start, elapsed time, ownership and completed work do not change, so extending a 60-minute run to 120 minutes after five minutes leaves about 115 minutes. A new workload, including a reduced date range, receives a new calculation identity and assessment while using the report's configured Maximum runtime.

### Historical runtime safety, cancellation and telemetry

The first five minutes are an assessment window. Engine completion is based on engine work rather than elapsed time. Before the engine total is known, acquisition and classification report their phase and processed row count separately instead of inventing a percentage. Original measures inclusive occupied seconds; Sweep models row construction, sorting and event traversal as separate stages so a cheap completed stage cannot imply that expensive work is almost finished. Engine completion is monotonic and reaches 100% only when engine work completes.

While a calculation is active, its engine appears in the panel heading opposite **Stop**. The calculation telemetry presents six values in three columns: **Engine completion** above **Elapsed**, **Estimated time remaining** above **ETA confidence**, and **Maximum runtime remaining** above **PBX impact**. The five-minute assessment continues internally without a separate visible countdown.

ETA remains **Calculating...** for at least 300 seconds. After that it appears only when at least ten recent forward-progress samples in one meaningful engine stage have throughput variation within the deterministic 15% stability limit, sufficient work has completed, and no pause, long stall, backwards movement or stage transition has contaminated the sample. Public confidence is **High**, **Calculating...** or **Insufficient**; a positive estimate below one second remains **< 1 second**.

The complete five-minute window samples lightweight system and calculation evidence for **PBX Protection**. Its persisted module-owned threshold applies to Historical and Demo, defaults to 90% and is restricted to 50–95%; it is a CPU or memory headroom input, independent of Live thresholds, and not the classifier by itself. High requires concerning evidence in at least three quarters of that complete window, so isolated transients do not dominate and early sustained pressure cannot disappear during a quiet final minute. A separate recent one-minute window supports Critical detection. The classifier considers sustained CPU pressure, change from the starting baseline while PHP or report database work is active, memory availability, swap activity, I/O wait/pressure and the report's observed query response. One transient CPU spike cannot produce High or Critical. Low and Moderate continue after five minutes. High pauses for an administrator decision. Acute memory exhaustion or sustained extreme CPU, I/O or swap pressure can cause a Critical protective pause at any point during an active calculation, including after the initial five-minute assessment window. Completed presentation remains Low, Moderate, High or Unable to assess. The wording describes correlation observed while the calculation ran and does not claim exclusive causation.

#### Runtime enforcement and memory

The runtime allowance protects acquisition, classification and engine work. On MariaDB 10.1.1 or later, Historical CDR acquisition uses `max_statement_time=2`; on MySQL 5.7.8 or later it uses the SELECT-only `max_execution_time=2000`. Acquisition normally starts with non-overlapping six-hour `calldate` ranges with exact inclusive final bounds, streams each statement, and bisects a timed-out range repeatedly down to a one-minute minimum. MariaDB versions without `max_statement_time`, including 5.5.65, keep ordinary Historical available through adaptive acquisition after verifying that `cdr.calldate` is the full leading column of an index; legacy range SELECTs then explicitly use that escaped index name with `FORCE INDEX`. The first range is 15 minutes. A successful query taking at least one second halves the next range; two consecutive queries completing within 250 milliseconds double it. Ranges remain between one minute and six hours and every statement has a preceding worker checkpoint. Fast long-range reports therefore converge to the normal six-hour ceiling instead of issuing one query per minute. No index is added automatically; missing or unavailable index metadata stops the legacy report clearly to avoid repeated full-table scans. This strategy limits submitted work without changing ANSWERED, PJSIP, exclusion, boundary or date semantics, but it is not a query timeout: PHP cannot run cancellation, runtime, memory or PBX Protection checkpoints while one legacy database statement is blocked, and cannot forcibly cancel that in-flight query. Unrelated database errors propagate unchanged. MySQL older than 5.7.8 remains unsupported. Original checks during long occupied-second loops and its later peak scan. Sweep checks during work modelling, construction, sorting and traversal in batches of 4,096 operations.

Every recognized MariaDB/MySQL session also sets `innodb_lock_wait_timeout=2`; this limits InnoDB lock waits rather than total statement execution. Unparseable or unknown database server versions are rejected instead of receiving guessed capabilities. The Historical SQL otherwise assumes the deployed FreePBX CDR schema supports prepared range comparisons, `TIMESTAMPADD`, `BINARY`, `REGEXP` and `LIMIT`. Demo additionally requires `@@datadir`, `@@hostname`, `@@log_bin` and, when binary logging is active, an absolute `@@log_bin_basename`, plus `information_schema.STATISTICS` (`INDEX_NAME`, `SEQ_IN_INDEX`, `COLUMN_NAME`, `SUB_PART`) and `information_schema.TABLES` (`DATA_LENGTH`, `INDEX_LENGTH`, `TABLE_ROWS`). Missing cleanup-index metadata or required filesystem/binlog information makes Demo fail closed; unavailable table-size statistics use the documented conservative estimate.

Original retains its straightforward inclusive per-second result contract but processes timestamp state in aligned 3,600-second windows. Calls crossing a window are clipped to each window's inclusive bounds, compact peak/range summaries are merged across boundaries, and the temporary seconds map is then discarded. This bounds the timestamp dimension of working memory without imposing a new duration cap on Trunk or Extension; Group retains its existing 86,400-second contribution cap. Estimator progress remains actual occupied-second work, not chunks completed.

At calculation checkpoints, Concurrency Count also observes its PHP process allocation without changing `memory_limit`. For a finite configured limit it reserves the larger of 16 MiB or 20 percent, capped at half the limit for unusually small limits, and stops at the resulting safe ceiling. This is preventive headroom for structured failure handling, serialization, cleanup and FreePBX; it does not claim that PHP hard memory exhaustion can always be recovered afterward. A soft-memory stop retains any previous completed report and suggests Sweep, a shorter range or a narrower endpoint filter. Unlimited or invalid memory-limit values disable this secondary guard rather than inventing a ceiling.

#### Stop and terminal behaviour

An active GUI Historical calculation has a cooperative **Stop** control tied to its validated, unique calculation ID. Stop is present only while that calculation is active or stopping; pressing it disables the button, records backend cancellation and lets the shared engine checkpoints stop work cleanly. Aborting the browser request alone is not treated as backend cancellation. After backend cancellation is acknowledged, explicit GUI Stop closes that Historic Report through the normal close path, removing its persisted definition and freeing its slot. Resource-limit, runtime and ordinary calculation failures remain visible and do not automatically close the report. Calculation ID plus browser sequence checks prevent stale or superseded responses from replacing or recreating a newer state.

High impact and projected runtime shortage pause cooperatively inside the active PHP request. **Continue Anyway** resumes the same in-memory calculation and suppresses another advisory for that logical run; hard runtime and memory protection remain active. **Recalculate** retains the calculation ID, runtime origin, allowance and progress, clears ETA confidence and starts a fresh five-minute impact window. Reproduced concern pauses again; a clean reassessment continues automatically. Recalculate is rejected when fewer than five runtime minutes remain. **Reduce Date Range** cancels safely and returns to the report controls; running the changed range starts a fresh workload without replacing an earlier completed result unless the new calculation succeeds.

#### Single active GUI calculation

Only one GUI Historical calculation is permitted per authenticated PHP-session ownership scope. While it is active or stopping, Concurrency Count stays locked to its calculating Historic Report: other report tabs, Historical landing/start controls, Live View and Live Wall entry are unavailable, Excluded Calls remains disabled, and **Stop** remains available. Mouse and keyboard activation are blocked before tab selection, persistence or lazy regeneration can occur. Success unlocks the workspace and leaves the completed report selected. Runtime, resource-limit and ordinary failures keep the failed report visible and unlock navigation; explicit Stop waits for validated backend cancellation acknowledgement, then closes that report normally.

This policy is enforced by both JavaScript and server admission control. Admission mutations use a module advisory lock and a server-side hash of the authenticated PHP session; raw session identifiers are never exposed. A replacement is rejected while an earlier registered calculation in that ownership scope has not yet unwound and removed its control record, even when cancellation or abandonment has already been requested. This prevents refreshes, duplicate requests and frontend races from intentionally overlapping expensive GUI Historical PHP work. A different authenticated PHP session has an independent GUI scope. CLI Historical calculations, the PM2 Live monitor, threshold monitoring, the alert mailer and ordinary Live backend workers do not participate in this GUI admission policy.

#### Leaving, refreshing or closing the browser

Navigating away from Concurrency Count abandons the active GUI calculation. The browser makes a best-effort CSRF-authenticated `sendBeacon` cancellation for its exact calculation ID without delaying FreePBX navigation. Refresh, tab/window close and page unload use the same fast path; calculations are not reattached after reload. Because unload delivery is not guaranteed, the browser also renews a calculation-specific lease every **5 seconds** and the backend expires it after **20 seconds** without renewal. This tolerates ordinary scheduling/network jitter while limiting one active calculation to at most **0.2 settings writes per second** for heartbeat renewal. Lease expiry is observed through the same bounded cooperative checkpoints as Stop, so no PID killing, shell command or PHP session lock is involved.

An abandoned calculation does not return or save a partial result. A newly created report definition already allocated before its first engine run remains available after browser abandonment because no browser response is present to perform the normal failed-first-run cleanup; it can be retried or closed explicitly. Regeneration abandonment likewise leaves the existing persisted report definition unchanged and does not replace a previously completed browser result before navigation. Immediately after refresh, a new run may briefly receive **A previous Historical calculation is still stopping. Please try again shortly.** until the old process observes cancellation/lease expiry and completes cleanup.

#### CLI cancellation

The CLI traps `SIGINT` (Ctrl+C) and `SIGTERM` when PHP PCNTL asynchronous signals are available. Those signals request the same cooperative checkpoint cancellation, allowing `finally` cleanup such as removal of temporary Demo CDR rows to run where possible; `SIGINT` exits 130 and `SIGTERM` exits 143. Without PCNTL, the previous OS-level interrupt behaviour remains, so graceful checkpoint cancellation and cleanup cannot be guaranteed.

#### Active calculation telemetry

While a GUI Historical calculation is active or paused, its temporary panel shows Stop, modelled engine completion, engine, assessment time, estimate confidence, PBX impact, elapsed time, maximum-runtime remaining and estimated time remaining. It remains visible while a decision modal is open. Per-second timer changes are outside the polite live region; only meaningful state changes are announced.

The actual calculation process publishes its current and peak PHP memory, process CPU time, phase, work and accumulated query timing to server-owned state at a bounded cadence. Optional `/proc` reads add CPU utilisation, logical CPUs, available memory, swap activity, load, I/O wait and Linux pressure data. Missing files or metrics are omitted and never represented as zero. The separate non-overlapping two-second AJAX poll reads that persisted worker state and FreePBX Dashboard context; it never presents its own PHP process as the calculation process.

During an active GUI Historical calculation, elapsed, maximum-runtime and reliable ETA clocks update locally once per second between the two-second backend telemetry synchronizations. Backend telemetry remains authoritative and resource values still change only when an actual telemetry response arrives.

#### AJAX scope and security

The `calculationtelemetry`, `calculationheartbeat` and `cancelcalculation` module actions are authenticated, CSRF-protected, explicitly allowlisted and non-remote. Calculation IDs must be exactly 32 hexadecimal characters. The actions expose neither ownership hashes, PIDs nor arbitrary process, filesystem, shell, `exec`, `kill` or `pkill` access. Telemetry, leases and GUI cancellation remain GUI-scoped; CLI signals remain local to the running CLI command.

### Historic Report tabs and persistence

The Historical Reports workspace supports at most five open Historic Report tabs. **Start Historical Report** opens configuration without consuming a slot. A slot is allocated only after a validated **Run report** submission; a failed first calculation removes its unused definition. Stable internal IDs and slots are independent of editable names, and closing a tab frees its slot for reuse. Saved report tabs are reordered only from their dedicated grip handle; keyboard users can focus the handle, press Space or Enter to grab, use Left/Right to move, then press Space or Enter to drop (or Escape to cancel). Historical Reports remains anchored first, saves are serialized so the last visible order wins, and presentation order persists without changing report identity or active state.

For a completed report, **Edit Report** reopens the same configuration with the submitted criteria. Cancelling leaves the displayed result unchanged; **Run Again** replaces it only after the revised calculation completes successfully. The completed result records the global Excluded Calls configuration used, and a rerun stops clearly if that configuration changed outside the normal invalidate-and-regenerate workflow.

A sixth report is rejected without replacing an existing tab. The five-report limit and slot allocation are enforced atomically by the backend as well as presented in the GUI.

Persisted report-definition fields include:

- name, stable ID and slot;
- mode, engine and selected trunk/extension filter;
- date preset identity and resolved/custom date information;
- Include time and its From/To values;
- Minimum concurrency;
- Maximum runtime in minutes;
- active report state where applicable.

Relative presets remain relative: **Last 7 days** is re-resolved against the current date on restore. **Custom** retains exact, valid calendar dates; impossible dates and reversed ranges are rejected.

Endpoint filtering is part of the shared Historical calculation, not a browser-only display filter. A filtered Trunk report calculates only the selected authoritative trunk, and a filtered Extension report calculates only the selected authoritative extension. An empty filter calculates all eligible endpoints for that mode. Group does not support endpoint filtering and does not retain an endpoint filter. If a saved endpoint is no longer authoritative for its report mode, the report remains visibly missing/unresolved and returns no endpoint result rather than silently falling back to all endpoints.

The same validated mode, engine, resolved range, endpoint filter, exclusions and endpoint classifications are used where applicable by initial GUI calculation, persisted regeneration, CSV, email, Historical graph, peak occurrence/detail and Excluded Calls relevance. Historical occupancy includes only `ANSWERED` CDRs whose duration is greater than zero; a zero-duration CDR remains untouched and visible in native FreePBX CDR Reports but cannot increase Trunk, Extension or Group concurrency or appear as peak evidence.

Not persisted:

- calculated result rows;
- graph points;
- lazy occurrence call detail;
- browser-only occurrence expansion and disclosure state after a full reload.

Reopening the module restores tab definitions without replaying result payloads; each report regenerates on demand when selected. Historic Report definitions, endpoint classifications, call exclusions, Live/module preferences, thresholds and alert state are persisted in module settings; Historical result payloads are not.

### Graphs, call detail and output

The complete Historical graph data is calculated with exact numeric counts, and the actual calculated peak remains unchanged by the Minimum concurrency floor. When at least one displayed value reaches the floor, lower points are presented as gaps and the X axis still spans the selected report window, so filtering or sparse activity cannot move qualifying buckets out of their true temporal position. When no displayed data reaches the floor, the GUI does not render the graph frame, legend or series controls and instead shows the dedicated **Minimum concurrency not reached** empty state; the underlying calculation and exact peak are still preserved. Graph state is derived at the selected start boundary, only changes in the displayed range affect that range, and the end-boundary state is explicit; the same inclusive call-interval rules apply. Trunk results expose occurrence timing and lazy contributing-call detail; activity-only Trunks use the same underlying result and detail data, not a reduced summary.

Historical series buttons are independent selections. Every available series is selected when a fresh graph result is first rendered. **Select All** restores every available series and **Unselect All** clears the graph until at least one series is selected. Selected series share one generated SVG graph image and report-window axis, with colours distributed deterministically across the complete available-series inventory, a built-in legend and separately identified thresholds. The same finished graph can be exported as SVG, PDF, PNG or JPEG and contains exactly the currently selected series. Single-series filenames use the series name, while multiple-series filenames use a bounded count such as `15-series`.

The detail path is conservative. CDR can prove the selected trunk leg, DID, source/destination and a directly recorded opposite PJSIP extension. Concurrency Count asks installed FreePBX `*_getdestinfo` providers for labels and safe local `config.php` edit links. Unresolved values remain plain text. It does not infer a historic IVR, queue or announcement chain from current configuration.

Where the CDR provides a destination that an installed provider can prove, this layer can resolve extensions/users, trunks, inbound and outbound routes, ring groups, queues, IVRs, announcements, time conditions/groups, conferences, Follow Me, call flow control, miscellaneous/custom applications and destinations, voicemail and termination destinations.

**View in CDR Reports** POSTs the supported `need_html=true` form fields with the call minute and standard caller-number, destination and DID filters. It does not invent a `uniqueid` query parameter or depend on the CEL-specific `action=cel_show` route.

Results can be viewed inline, downloaded as CSV or emailed with a CSV attachment. Trunk downloads and email attachments include qualifying peak occurrences and their contributing logical-call evidence using the same filtered report semantics as the GUI. Extension and Group exports retain their supported summary formats. Raw values retain exact peaks; human-readable GUI, email and CLI wording distinguishes Activity only from concurrency. CLI option names remain stable, with 2.1.0 adding explicit date aliases, stricter argument validation and safer operation/health exit behaviour.

### Excluded Calls

**Exclude Call** creates a reversible module-level exclusion for one safely identified logical call. **Exclude All** excludes every eligible logical call contributing to the exact displayed peak occurrence as one group. Group members remain individually inspectable and restorable; **Restore All** restores the group's still-excluded members, while **Reset** globally clears every exclusion. Exclusions are global across every current and future Historical Report, apply to Trunk, Extension and Group, and are honoured by Historical CLI calculations. Live View and Live Wall do not use them.

- Asterisk `linkedid` is preferred. Every row sharing that excluded `linkedid` is removed together.
- `uniqueid` is the fallback when `linkedid` is unavailable.
- Similar calls with different logical identities remain independent.
- **Exclude Call** excludes one safely identified logical call and is available only where a safe logical-call identity exists.
- **Exclude All** excludes every eligible logical call contributing to the exact displayed peak occurrence and records those exclusions as one group.
- **Restore** reverses one exclusion; restoring one member of a bulk peak group does not restore the others.
- **Restore All** restores every still-excluded member of that peak group without affecting unrelated exclusions.
- **Reset** reverses all exclusions globally.
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

Stopping monitoring clears the trunk's active episode state. Restarting evaluates the next snapshot as a fresh episode. Hidden trunks remain available for Unhide and Start/Stop Monitoring. Visible cards can be reordered by dragging the reorder handle; with keyboard focus on that handle, Left and Right Arrow move the card.

Unknown saved channelids are retained but ignored while unavailable, and newly discovered trunks append after the saved order.

### Live Wall

Live Wall is presentation-only: a read-only wallboard using the same latest browser snapshot, rolling history and polling path as Live View. Its persisted Light/Dark choice applies only to Live Wall, using FreePBX-style green accents in both themes. The wall follows and compresses to the visible desktop viewport, uses the complete available viewport in both windowed and browser-fullscreen presentation, and reflows its panels and charts after resize, visual-viewport, orientation and fullscreen changes. Overall remains primary. The required ordered selection depends on the configured PJSIP trunk inventory: no configured trunks permits Overall-only; one, two or three configured trunks require 1/1, 2/2 or 3/3 respectively; and more than three requires exactly three.

Live Wall launch opens **Configure Live Wall** when the saved selection is incomplete. No trunk is selected or substituted automatically, so deleting a selected trunk can require reconfiguration. Hidden featured trunks remain selected but are suppressed from presentation. Monitoring-stopped featured trunks remain valid and display current data. Saved left-to-right order remains authoritative. All configured trunks, including hidden, monitoring-stopped and unfeatured trunks, continue to contribute to Overall. The desktop composition targets Overall plus three equal cards at conventional 1080p and scales or stacks elsewhere.

An already-valid launch requests the Fullscreen API directly from the launch gesture when available, then revalidates the current inventory; an invalidated selection closes the Wall and opens configuration. A first-time configure-and-save continuation enters the full-page Wall without assuming the earlier gesture can still request fullscreen. Denial leaves the full-page Wall active. **Full Screen** is available whenever Live Wall is active, supported and outside browser fullscreen. While browser fullscreen is active, **Windowed** exits fullscreen without closing Live Wall. Browser Esc exits fullscreen but leaves Live Wall active and has the same effect. **Exit Live Wall** is the only Live Wall control that returns to Live View. Live Wall and its configuration controls are unavailable below 768px, where normal Live View remains available.

Preferences use the FreePBX Core PJSIP trunk `channelid`. Changing a trunk channelid can leave saved visibility, order, feature or monitoring preferences attached to the old identifier; automatic migration is not currently performed.

### Recent peak and refresh

**Recent peak** is the maximum in the current browser session's in-memory chart series. It is not the backend threshold-episode peak and resets on reload. A single sample may appear as a narrow spike.

Refresh can be 1, 5, 10, 15, 30 or 60 seconds; default is 5. Requests do not overlap and pause while hidden. The 1-second option is deliberately aggressive and requires load validation. Browser refresh does not control unattended alerts.

## Threshold monitoring and notifications

A persistent PHP worker supervised by FreePBX Process Management (`pm2`) keeps one AMI connection and reacts to `Newchannel`, `Newstate`, `Hangup`, `Rename` and `Masquerade`. Events trigger reconciliation; a full reconciliation also runs every five seconds. Each snapshot has a unique AMI ActionID and is accepted only after its matching `CoreShowChannelsComplete`. An incomplete snapshot is unavailable, never an empty PBX.

PM2 supervises this worker and a separate mail worker. Lifecycle hooks start, stop and restart them with FreePBX/Asterisk. Installation removes the obsolete minute cron line. Health degrades when no recent complete snapshot exists. CLI monitor status distinguishes combined health from the main PM2 process state, and `--restart-monitor` succeeds only when the combined monitor result is healthy rather than merely when the main monitor process is online.

Threshold comparison is `current >= threshold`; zero disables it. Master alerts, per-scope alerts, threshold enablement and recovery preference are distinct. Alert state and a stable outbox entry are persisted atomically before delivery, suppressing repeats through one episode and worker restart while retaining its peak. Stable event IDs prevent duplicate queue records. Before a send, the mail worker leases the ready event; a failed send is returned to a deterministic retry state with the error, attempt time and bounded exponential backoff retained.

Live settings provides **Test email** beside the configured Alert email. Test and production delivery both reload and validate current Live settings and use the same message-preparation path; Test email supplies an explicit recipient override. Live settings shows transient Test email feedback only; production delivery and retry diagnostics remain persisted internally rather than being shown as permanent status text. Alert, recovery and Test messages use concise plain-text copy and do not expose internal module paths or local-mailer acceptance wording. The sender comes from FreePBX Advanced Settings **Email "From:" Address**; a missing or invalid value stops delivery clearly. The same validated address is used for Reply-To and, where the installed `CI_Email` API supports it, Return-Path. Failed `CI_Email` sends retain bounded diagnostics for troubleshooting.

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
| Demo access | `demo --enable`, `demo --disable`, `demo --status` |

Prefix examples with `fwconsole concurrencycount`. CLI date boundaries use PBX/server local time: `--start=today` is today at `00:00:00`, `--end=today` is the current time, `--start=yesterday` is yesterday at `00:00:00`, and `--end=yesterday` is yesterday at `23:59:59`.

Omitting `--engine` selects Original; explicit `original` and experimental `sweep` are valid. An explicitly unknown engine is rejected rather than silently falling back to Original. Incompatible management operation classes are rejected before mutation—for example, `--monitor-status --restart-monitor`, `--live --set-refresh=5` or `--list-historical-reports --alerts=off`. `--json` is a modifier, multiple supported settings mutations may be combined, and `--settings` may accompany settings mutations.

The Demo access commands administer permission only. `demo --enable` authorises the existing GUI Demo functionality but does not run a scenario or generate synthetic CDRs. `demo --disable` revokes authorisation for new Demo operations and does not delete existing CDR data. `demo --status` performs no mutation and reports `Demo access: ENABLED` or `Demo access: DISABLED`.

Live queries take one snapshot and exit; they do not poll or replace the PM2 worker. The standalone IN1CLICK `concurrency-count` tool remains available for terminal interaction, progress reporting and pause-on-overrun behaviour. Neither interface is universally preferable.

## Demo

### Demo security and access

Demo is an optional testing and demonstration facility. Its scenarios can write synthetic CDR records into `asteriskcdrdb`, which is why Demo access is **DISABLED by default**. This is an intentional protection for systems that contain genuine call records; it does not mean that the module is incomplete or incorrectly installed. Normal Concurrency Count operation does not require Demo: Live monitoring, Historical analysis, thresholds and alerts continue to operate normally while Demo access is disabled.

Demo remains visible in the GUI while disabled so administrators can discover it and understand how to authorise it. The visible control is informational and locked; it is not a web permission control. Enabling Demo requires privileged shell access:

```text
Privileged CLI
  -> authorises or prohibits Demo capability

FreePBX GUI
  -> operates Demo only when authorised
```

Use these commands as a privileged system administrator:

```bash
fwconsole concurrencycount demo --enable
fwconsole concurrencycount demo --disable
fwconsole concurrencycount demo --status
```

The CLI authorises or prohibits capability only. It does not start a Demo scenario, generate synthetic calls, or write synthetic CDRs. The GUI cannot grant itself permission. Before any synthetic-data operation, the server independently verifies that privileged Demo access is enabled, including for AJAX, crafted requests, downloads, email, previews and the existing `--mode=demo` CLI calculation path.

Every installation and module update initialises Demo access to DISABLED. This deny-by-default reset is intentional because newly installed module code must not automatically inherit permission to generate synthetic CDR records from the previously installed version. `demo --enable` authorises the currently installed module version only; run it again after an update if Demo is required. Reboots and ordinary service or monitor restarts preserve an explicit authorisation. Disabling access rejects new Demo operations, does not delete CDR data, and allows an already-running Demo to complete its existing mandatory cleanup.

Page 1 of the Demo modal offers an administrator-selectable **Year** (2001-2015, defaulting to 2001 on every fresh opening) alongside the safety acknowledgement; the selection is never persisted and always resets when Demo is reopened. The GUI uses a **Load** dropdown on Page 2 to select Light, Medium or Heavy and uses an ephemeral cryptographically random 128-bit token to create a fresh deterministic scenario generated by the pinned CDRgen 1.1.0 reusable core for Trunk, Extension or Group runs, constrained to the selected Year. The current scenario remains stable until the Year, Load or profile changes or Randomise is pressed, and is not restored after reload. Light generates 1,000 mixed calls over one day, Medium 5,000 over one day and Heavy 20,000 over one day; the scenario start day falls within the selected year, which may be any full calendar year from 2001 to 2015. A 31 December start naturally uses 1 January of the following year as the exclusive end boundary, and every profile remains exactly one day. Demo Minimum concurrency defaults to 2. CLI examples are:

**Demo safety:** Demo temporarily writes tagged synthetic records to the CDR database and requires MariaDB. Reserved Demo rows are excluded from ordinary Historical reporting and are removed when the run completes, with recovery available for interrupted runs. Opening Demo always begins with a safety warning. The administrator must acknowledge that temporary synthetic records will be written before proceeding to the Demo controls; this acknowledgement is required every time Demo is opened and is not a persistent setting or permission. For best results, run Demo during a quiet period and allow calculation and cleanup to complete without interruption.

For **Extension Demo** results, an assigned-CDR peak is the number of overlapping synthetic CDR records attributed to an extension at the busiest calculated point. It is a Historical/CDR-processing test value, not a measure of simultaneous physical calls or endpoint capacity. Heavy deliberately creates dense overlap and may therefore produce high per-extension assigned-CDR peaks.

```bash
fwconsole concurrencycount --mode=demo --demo-report=extension --demo-size=medium --demo-seed=12345
fwconsole concurrencycount --mode=demo --compare=original,sweep
```

Demo uses the server-side `max_statement_time` limit for cleanup `DELETE` statements on MariaDB 10.1.1 or later. Legacy MariaDB, including 5.5.65, requires the actual CDR table to use InnoDB and uses exact-tag batches of at most 100 rows with conservative row and metadata lock waits and the same five-minute application cleanup deadline; an unknown or different storage engine fails before cleanup or synthetic writes. MySQL `max_execution_time` protects SELECT statements only, so Demo remains unavailable on supported MySQL while ordinary Historical remains available. Before stale recovery or any exact cleanup DELETE, Demo verifies from `information_schema.STATISTICS` that the CDR table has an index whose leading column is `accountcode`. This makes exact reserved-accountcode cleanup use an identifiable access path; absent, non-leading, insufficient-prefix or unavailable index metadata fails closed without changing the CDR schema or writing synthetic rows. For a new run, that access-path check precedes stale recovery; the separate disk/binlog capacity check then determines whether new rows may be inserted. Demo reads the MariaDB data directory and verifies its local backing filesystem. Its estimate uses four times the CDR table's allocated bytes per reported row for indexes, page churn and approximate table statistics and never falls below 16 KiB per requested row. When binary logging is enabled, Demo resolves `@@log_bin_basename`; the same filesystem receives another row allowance, while a separate binary-log filesystem receives its own requirement and larger-of-1-GiB-or-20% reserve. An unavailable binary-log location fails closed. Missing, remote or invalid filesystem information also fails closed. The GUI shows requested rows, estimated need, free space, reserve and safely available space before asking to run.

After preflight, Demo inserts deterministic CDR rows tagged with a unique `CCDEMO*` accountcode in bounded groups with cancellation and resource checkpoints every 100 rows. It rechecks current database/binlog filesystem headroom between groups and aborts if the required live safety allowance is no longer available; observed filesystem growth is reported as telemetry and is not, by itself, an abort condition. The completed result reports generated, inserted, audited, removed and remaining counts, verifies those totals against one another, and shows the generated traffic mix and synthetic-traffic-engine provenance. Only the first 100-row synthetic-call audit page is carried in the completed result; later detail pages are retrieved from a short-lived authenticated server spool in batches of at most 100 using a dedicated audit token separate from AJAX CSRF. Audit rows become eligible only after their database transaction commits, and failed or cancelled runs discard their audit spool rather than retaining partial detail.

Demo then uses the same five-minute progress, ETA, PBX impact, pause, reassessment and runtime framework as Historical. Cleanup runs in `finally`, deletes by the unique tag and verifies zero remaining rows after success, Stop, cancellation, runtime/resource/disk failure or ordinary exceptions. Mandatory cleanup does not reuse the calculation's terminal cancellation, runtime or memory checkpoint; it has a separate monotonic five-minute housekeeping allowance, a bounded 330-second PHP execution-time backstop, the verified exact indexed access path, bounded adaptive batches and database lock/statement protections. Its registry heartbeat remains active throughout housekeeping so concurrent recovery does not treat it as stale. Successful cleanup removes the registry afterwards; a genuine cleanup failure retains it for recovery after five minutes without a heartbeat.

Concurrency Count bundles the side-effect-free CDRgen 1.1.0 generation core from exact upstream revision `e8f45d82163b081196efb82219751ce66b65cca4` under `lib/cdrgen/`. `Services/CdrgenAdapter.php` is the only integration boundary and requests PJSIP-only traffic. CDRgen generates synthetic traffic; Original and Sweep remain Concurrency Count calculation engines. The module retains ownership of inventory adaptation, CDR schema mapping, insertion, calculation, preflight and cleanup.

Demo generation starts from the authoritative configured PJSIP trunk and device inventories used by the rest of Concurrency Count, supplementing the extension side with isolated synthetic fallback identities only when too few configured extensions are available to generate meaningful mixed traffic. Configured numeric trunks remain trunks even when their channelids look like extension numbers, configured extensions beginning with 1 or 9 remain extensions, and unknown numeric-looking endpoints are not promoted by number shape alone. The expected-value oracle uses the exact Demo inventory supplied to generation and derives topology independently from observable `channel` and `dstchannel` legs; it does not trust CDRgen's generated direction or helper metadata when checking engine accuracy.

To upgrade CDRgen, select and review an upstream release, replace the bundled core, update its import revision and hashes, run upstream and adapter compatibility tests plus the full regression suite, inspect the complete diff, and smoke-test representative PBXs before release.

Demo is a capacity assessment of this Historical/CDR processing workload on this PBX. Its summary includes only measurements obtained reliably. It does not test maximum simultaneous voice-call capacity.

Omitted Demo arguments retain their documented defaults. Explicit invalid Demo report modes, sizes or comparison-engine values are rejected rather than silently replaced; Original remains the default comparison engine and Sweep remains experimental.

`CCDEMO` followed by exactly eight lowercase hexadecimal characters is reserved for synthetic rows and is always excluded from ordinary Historical SQL and post-fetch processing. Cleanup runs in `finally`; it halves timed-out exact-tag delete batches from 1,000 rows and treats a successful batch shorter than its limit as verified exhaustion, avoiding a second full-table `COUNT(*)` scan. MariaDB `max_statement_time` covers cleanup statements. MySQL Demo is unavailable because `max_execution_time` does not cover `DELETE`; bounded batches and the two-second InnoDB lock-wait limit are retained as additional protections but are not treated as execution deadlines. A durable registry heartbeat is refreshed during an active Demo. Before each later Demo preflight or run, registry entries inactive for five minutes are recovered after fatal error, server kill, database interruption or host crash. A failed recovery remains registered for a later retry. Demo calls cannot be persistently excluded, and Demo never consumes a Historic Report slot.

The Demo acknowledgement gate is a client-side deliberate-use control in addition to the privileged access setting; it is not the security boundary. Server-side authorisation remains mandatory even when the GUI control is visible or a request is crafted directly.

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
- Demo access is disabled by default and can be changed only by the privileged CLI commands; the GUI cannot authorise itself.
- The server independently checks Demo access before every synthetic-data operation, regardless of GUI visibility or client-supplied state.
- Live reads Asterisk through backend AMI handling; the browser has no direct AMI access.
- Original is the default; experimental engines require explicit selection.

## Required PBX/browser validation

This is a release validation checklist, not a claim that every supported PBX/browser combination has been exercised. Exercise FreePBX 16 and 17 where available.

### Historical

- Complete a small report before five minutes and confirm progress reaches 100% while ETA remains Calculating until completion.
- Run a report longer than five minutes; confirm visible monotonic progress, the full five-minute ETA gate, and a High-confidence ETA only after stable evidence.
- Create sustained High impact and confirm cooperative pause, Continue Anyway with no repeated advisory, Stop while paused, and hard memory/runtime protection after continuation.
- Create an unrelated temporary load spike, choose Recalculate, and confirm work/progress/runtime origin survive; test both a clean reassessment that continues and repeated concern that pauses again.
- Increase a paused run from 60 minutes, confirm maximum runtime remaining changes without elapsed reset, and reject values above 1,440 minutes.
- Choose Reduce Date Range, alter the range and rerun; confirm a fresh calculation ID, progress and assessment while the report's configured Maximum runtime is retained and any earlier completed result remains until success.
- Stop while running and paused; refresh and close the browser while paused; confirm ownership lease cleanup and that no partial result replaces a completed result.
- Exercise Asterisk restart and unrelated system activity during calculation where applicable, plus the PHP memory guard.
- Run safe and unsafe Demo preflights. Confirm unsafe preflight inserts zero rows, including with the database filesystem nearly full in a controlled test environment.
- On legacy MariaDB 5.5.65 with an InnoDB CDR table and suitable `accountcode` index, run Demo through generation, calculation and cleanup; confirm bounded cleanup completes with zero `CCDEMO*` rows and no unsupported `@@log_bin_basename` access when binary logging does not require it.
- Run Light, Medium and Heavy Demo profiles from the **Load** dropdown and confirm they request exactly 1,000, 5,000 and 20,000 calls over one day. Select Heavy and press **Randomise** repeatedly; confirm Heavy remains selected, each click immediately changes the scenario, only the settled scenario is preflighted, and no temporary AJAX error banner appears. Reload the module and confirm the GUI scenario is not restored.
- Confirm Demo Page 1 defaults **Year** to 2001 and the acknowledgement is unchecked on every fresh opening, that Page 1 has no Run buttons and Page 2 has no Proceed or Back control. Select a Year and confirm Proceed produces a Page 2 scenario within that year; confirm changing **Load** and pressing **Randomise** on Page 2 keep the same selected Year. Cancel and reopen Demo and confirm Year and acknowledgement both reset.
- Run a Medium or Heavy GUI Demo to completion, inspect traffic mix and integrity totals, open the synthetic-call audit, fetch at least page 2, and confirm each audit page contains at most 100 calls.
- Cancel and abandon Demo during insertion and calculation, and confirm verified zero `CCDEMO*` rows and no retained partial audit spool. Run a large Demo workload and inspect the capacity-assessment summary.
- Run CLI Demo twice with the same explicit `--demo-seed` and confirm deterministic scenario generation remains compatible with the legacy CLI option.
- Test a host where one or more procfs metrics are unavailable and confirm omitted values do not appear as zero or stop the calculation.
- A normal outbound extension call.
- A numeric configured PJSIP trunk and alphanumeric configured PJSIP device.
- Configured extensions beginning with 1 and 9, including inbound, outbound and internal traffic, and confirm number shape does not change their authoritative role.
- Existing or synthetic CDRs with dialled `dst` values 999, 911, 111 and another 1XX; do not place unsafe calls merely to create data.
- An unknown/deleted endpoint; Treat as Trunk, Treat as Extension, Ignore, reset one and reset all.
- Authoritative configuration superseding an override, and a trunk/device collision remaining a conflict.
- Peak 0, peak 1 Activity only and peak 2+ concurrency in all three modes.
- Activity-only Trunk occurrences, lazy detail, CDR Reports and Exclude Call.
- Exclude one call and Restore it, including multiple rows sharing one `linkedid` and an independent similar call.
- On a displayed Trunk peak, use **Exclude All**, confirm every eligible contributing logical call is grouped and the report regenerates, restore one member individually, then use the grouped **Restore All** action and confirm only the remaining members of that group return.
- Confirm stale Exclude All state is rejected if the global exclusion configuration changes before submission, and **Reset** retains its global meaning.
- Source CDR removal after exclusion, retaining summary with relevance unavailable.
- Multiple Historic Report tabs, stable names/IDs, relative and Custom restoration, and lazy regeneration.
- Exclusion/classification changes causing recalculation and the expected presentation transition.
- Exercise a multi-series Historical graph: confirm all available series begin selected, individual selection and Select All/Unselect All update the one shared graph, colours remain stable for the same complete inventory, thresholds remain associated with the correct series, and the X axis stays fixed to the report window.
- Export the same selected graph as SVG, PDF, PNG and JPEG; confirm each export contains exactly the selected series and that single-series and large multi-series filenames remain sensible and bounded.
- Start a Historical calculation and confirm every other report tab, Start Historical Report, Live View and Live Wall entry are disabled while Stop remains usable; let it complete and confirm all controls unlock with the completed report still selected.
- Repeat and navigate to another FreePBX module; confirm navigation is not blocked and the calculation stops shortly afterward without a generic AJAX warning.
- Repeat with page refresh, then immediately try another run; confirm admission reports the previous calculation still stopping and no duplicate engine job survives.
- Close the browser tab during a heavy Original run and confirm the 20-second lease plus the next bounded checkpoint cleans up the abandoned job.
- Press Stop and confirm the report closes only after cancellation acknowledgement, its slot is released, and navigation unlocks.
- Run a CLI Historical calculation while exercising the GUI lock and confirm CLI admission, calculation and signal behaviour are unaffected.

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
- Verify mail content and acceptance without treating acceptance as proof of external delivery. Exercise **Test email**, production alert delivery, a missing/invalid FreePBX Email "From:" Address and a forced send failure; confirm Test feedback appears only after **Test email**, no persistent production-delivery status is shown in Live settings, and failed production events become retryable.

### Browser and accessibility

- Desktop, tablet and approximately 320px layouts.
- Keyboard operation and visible focus for tabs, date controls, Activity only, occurrences, call actions, trunk ordering, modals and Live Wall exit.
- Switch Live Wall between Light and Dark and confirm the preference survives reload without affecting the normal FreePBX/PBXact theme.
- Exercise Live Wall in windowed mode, in browser fullscreen, after **Windowed**, after Esc, after resize and after orientation/visual-viewport changes; confirm windowed and fullscreen presentation both reach the available viewport edges, leaving fullscreen keeps Live Wall active, and charts/panels reflow without clipping.
- Screen-reader names and expanded state for disclosures.

## Tests

Standalone tests and contracts include:

```bash
php tests/AlertMonitorCoordinatorTest.php
php tests/AlertOutboxServiceTest.php
php tests/AlertDeliveryPathTest.php
php tests/AmiChannelSourceTest.php
php tests/CdrgenAdapterTest.php
php tests/CdrgenBundleIntegrityTest.php
php tests/DemoAccessAuthorizationTest.php
php tests/DemoAccessLifecycleTest.php
php tests/CliCancellationControlTest.php
php tests/CsvFormulaSafetyTest.php
php tests/DemoCleanupServiceTest.php
php tests/DemoDiskGuardTest.php
php tests/DemoExpectedTrafficTest.php
php tests/DemoLegacyPreflightTest.php
php tests/DemoLifecycleCleanupTest.php
php tests/DemoSyntheticCallCollectionTest.php
php tests/DemoTerminalCleanupTest.php
php tests/EngineParityTest.php
php tests/EngineRuntimeCheckpointTest.php
php tests/FreepbxEntityResolverTest.php
php tests/HistoricalAssessmentTest.php
php tests/HistoricalCalculationControlTest.php
php tests/HistoricalCallExclusionServiceTest.php
php tests/HistoricalCdrAcquisitionTest.php
php tests/HistoricalCdrEligibilityTest.php
php tests/HistoricalCriticalWorkerTest.php
php tests/HistoricalDatabaseCapabilitiesTest.php
php tests/HistoricalEndpointFilterServiceTest.php
php tests/HistoricalFloorOutputTest.php
php tests/HistoricalImpactAssessmentTest.php
php tests/HistoricalMemoryGuardTest.php
php tests/HistoricalNoControllerTest.php
php tests/HistoricalReportsServiceTest.php
php tests/HistoricalResultFloorTest.php
php tests/HistoricalRuntimeEstimatorTest.php
php tests/HistoricalTelemetryCadenceTest.php
php tests/InputValidationTest.php
php tests/LiveServicesTest.php
php tests/OriginalMemoryBenchmarkTest.php
php tests/OriginalWindowingTest.php
php tests/PeakDetailAnalyserTest.php
php tests/PjsipIdentityServiceTest.php
php tests/SettingsRepositoryTest.php
php tests/SystemResourceTelemetryTest.php
php tests/ThresholdNotificationCopyTest.php
php tests/TrunkPeakEvidenceTest.php
php tests/concurrencycount_admin_contract.php
php tests/concurrencycount_console_contract.php
php tests/concurrencycount_release_contract.php
node tests/ConcurrencyChartLifecycleTest.js
node tests/DateRangeTest.js
node tests/DemoModalGateTest.js
node tests/DemoScenarioTest.js
node tests/HistoricalGraphExportTest.js
node tests/HistoricalReportOrderTest.js
node tests/HistoricalRunStateTest.js
node tests/HistoricalSvgChartTest.js
node tests/TelemetryFormatTest.js
node tests/TestEmailLifecycleTest.js
```

The release suite also runs any additional PHP and JavaScript test files present under `tests/`; the list above highlights the standalone contracts and the principal regression suites documented for this release.

Source checks include:

```bash
node --check assets/js/cc-historical-report-order.js
node --check assets/js/concurrencycount.js
node --check assets/js/live-view.js
node --check assets/js/date-range.js
node --check assets/js/concurrency-charts.js
node --check assets/js/demo-scenario.js
node --check assets/js/historical-graph-export.js
node --check assets/js/historical-run-state.js
node --check assets/js/historical-svg-chart.js
node --check assets/js/telemetry-format.js
find . -path './.git' -prune -o -type f -name '*.php' -print | while IFS= read -r file; do php -l "$file"; done
git diff --check
```

These tests do not replace real PBX/browser validation.

## Future hardening

- Add a dry-run orphan-cleanup command for old `CCDEMO*` rows.
- Consider a Demo transaction only if safe with deployed CDR engines and FreePBX environments.
- Consider event-burst coalescing only with guarantees for prompt first-event and trailing reconciliation so short threshold crossings cannot be missed.
- Define an automation-safe CLI runtime-overrun confirmation or `--force` policy and a consistent JSON success/error envelope.
- Add FreePBX backup/restore integration for module-owned persisted state.
- Add real FreePBX 16/17 integration coverage for mail, CDR schema variation, permissions and browsers.
- Decompose the main module class in a future minor release rather than during release hardening.

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

For example: `Assisted-by: Codex:gpt-5.6-sol`

The human contributor remains solely responsible. AI tools must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne // [FreePBX UK](https://github.com/freepbxUK)

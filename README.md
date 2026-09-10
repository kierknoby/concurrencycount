# Concurrency Count 2.2.0 for FreePBX/PBXact 16 and 17

**NOT CURRENTLY SUITABLE FOR PRODUCTION.**

## Overview

Concurrency Count helps FreePBX and PBXact administrators understand how much simultaneous calling activity their system is handling.

It provides both a live view of current PJSIP trunk usage and Historical Reports built from Asterisk CDR data, making it easier to answer questions such as:

- How many PJSIP trunk legs are active right now?
- What was the highest simultaneous trunk usage last month?
- When did that peak occur, and which calls contributed to it?
- Are particular extensions regularly handling overlapping calls?
- How much extension-side activity was happening across the PBX at the busiest point?
- Is current trunk usage approaching a level where an administrator should be alerted?

The module has three main areas:

- **Live View** shows current attributable PJSIP trunk-leg concurrency, with per-trunk counts, Overall Live Concurrency, thresholds and unattended alerts.
- **Live Wall** provides a read-only full-screen presentation of the same live data for wallboards and monitoring displays.
- **Historical Reports** reconstruct past concurrency from answered CDRs and provide Trunk, Extension and Group measurements, graphs, occurrence detail, exclusions, CSV and email output.

Historical Reports provide three different measurements:

| Mode | What it measures |
| --- | --- |
| **Trunk Concurrency** | Simultaneous external PJSIP trunk legs, useful for understanding trunk capacity. |
| **Extension Concurrency** | Overlapping answered CDRs assigned to an individual extension. |
| **Group Concurrency** | PBX-wide simultaneous extension-side legs, independent of configured FreePBX Ring Groups. |

Concurrency Count does not alter SIP configuration or source CDR records during normal reporting. Historical exclusions and PJSIP Endpoint Classifications are module-owned and reversible. Demo is the deliberate exception: it temporarily creates tagged synthetic CDR rows for accuracy and performance testing, then removes them.

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

## Concurrency definitions

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

**Minimum concurrency** is an optional, inclusive output floor for Trunk, Extension, Group and Demo reports. Blank shows every detailed result; a value of 4 shows only detailed entities or periods whose calculated concurrency is 4 or greater. The full calculation always completes first, and the report period, completion state, calculation summary and actual peak remain visible even when no detail reaches the floor. A distinct notice separates that state from a report with no eligible Historical data. The same floor is applied to the GUI, Historical graph presentation, CSV/download, email, CLI and Demo output. Below-floor graph points are presented as gaps; the complete underlying graph calculation and actual peak remain unchanged. The floor does not affect Live View, CDR acquisition, engine calculations, assessment, telemetry, pause decisions or cleanup.

The floor is temporary per open browser report and is not added to the persisted Historical Report definition. Changing it therefore reruns the exact calculation. Completed results cannot currently be reused safely because the browser receives only the transformed detail and the server does not retain a separate unfiltered completed result; adding such retention would create a larger result cache outside this scoped feature.

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

Each logical run starts with a server-owned 3,600-second (60-minute) allowance. A paused administrator may increase that run's allowance, in whole minutes, to at most 86,400 seconds (24 hours). The calculation ID, original runtime start, elapsed time, ownership and completed work do not change, so extending a run from 60 to 120 minutes after five minutes leaves about 115 minutes. A new workload, including a reduced date range, receives a new identity, assessment and default allowance.

### Historical runtime safety, cancellation and telemetry

The first five minutes are an assessment window. Engine completion is based on engine work rather than elapsed time. Before the engine total is known, acquisition and classification report their phase and processed row count separately instead of inventing a percentage. Original measures inclusive occupied seconds; Sweep models row construction, sorting and event traversal as separate stages so a cheap completed stage cannot imply that expensive work is almost finished. Engine completion is monotonic and reaches 100% only when engine work completes.

While a calculation is active, its engine appears in the panel heading opposite **Stop**. The calculation telemetry presents six values in three columns: **Engine completion** above **Elapsed**, **Estimated time remaining** above **ETA confidence**, and **Maximum runtime remaining** above **PBX impact**. The five-minute assessment continues internally without a separate visible countdown.

ETA remains **Calculating...** for at least 300 seconds. After that it appears only when at least ten recent forward-progress samples in one meaningful engine stage have throughput variation within the deterministic 15% stability limit, sufficient work has completed, and no pause, long stall, backwards movement or stage transition has contaminated the sample. Public confidence is **High**, **Calculating...** or **Insufficient**; a positive estimate below one second remains **< 1 second**.

The complete five-minute window samples lightweight system and calculation evidence for **PBX Protection**. Its persisted module-owned threshold applies to Historical and Demo, defaults to 90% and is restricted to 50–95%; it is a CPU or memory headroom input, independent of Live thresholds, and not the classifier by itself. High requires concerning evidence in at least three quarters of that complete window, so isolated transients do not dominate and early sustained pressure cannot disappear during a quiet final minute. A separate recent one-minute window supports early Critical detection. The classifier considers sustained CPU pressure, change from the starting baseline while PHP or report database work is active, memory availability, swap activity, I/O wait/pressure and the report's observed query response. One transient CPU spike cannot produce High or Critical. Low and Moderate continue after five minutes. High pauses for an administrator decision. Acute memory exhaustion or sustained extreme CPU, I/O or swap pressure can pause as Critical earlier. The wording describes correlation observed while the calculation ran and does not claim exclusive causation.

#### Runtime enforcement and memory

The runtime allowance protects acquisition, classification and engine work. On MariaDB 10.1.1 or later, Historical CDR acquisition uses `max_statement_time=2`; on MySQL 5.7.8 or later it uses the SELECT-only `max_execution_time=2000`. Acquisition normally starts with non-overlapping six-hour `calldate` ranges with exact inclusive final bounds, streams each statement, and bisects a timed-out range repeatedly down to a one-minute minimum. MariaDB versions without `max_statement_time`, including 5.5.65, keep ordinary Historical available through adaptive acquisition after verifying that `cdr.calldate` is the full leading column of an index; legacy range SELECTs then explicitly use that escaped index name with `FORCE INDEX`. The first range is 15 minutes. A successful query taking at least one second halves the next range; two consecutive queries completing within 250 milliseconds double it. Ranges remain between one minute and six hours and every statement has a preceding worker checkpoint. Fast long-range reports therefore converge to the normal six-hour ceiling instead of issuing one query per minute. No index is added automatically; missing or unavailable index metadata stops the legacy report clearly to avoid repeated full-table scans. This strategy limits submitted work without changing ANSWERED, PJSIP, exclusion, boundary or date semantics, but it is not a query timeout: PHP cannot run cancellation, runtime, memory or PBX Protection checkpoints while one legacy database statement is blocked, and cannot forcibly cancel that in-flight query. Unrelated database errors propagate unchanged. MySQL older than 5.7.8 remains unsupported. Original checks during long occupied-second loops and its later peak scan. Sweep checks during work modelling, construction, sorting and traversal in batches of 4,096 operations.

Every recognized MariaDB/MySQL session also sets `innodb_lock_wait_timeout=2`; this limits InnoDB lock waits rather than total statement execution. Unparseable or unknown database server versions are rejected instead of receiving guessed capabilities. The Historical SQL otherwise assumes the deployed FreePBX CDR schema supports prepared range comparisons, `TIMESTAMPADD`, `BINARY`, `REGEXP` and `LIMIT`. Demo additionally requires `@@datadir`, `@@hostname`, `@@log_bin` and, when binary logging is active, an absolute `@@log_bin_basename`, plus `information_schema.STATISTICS` (`INDEX_NAME`, `SEQ_IN_INDEX`, `COLUMN_NAME`, `SUB_PART`) and `information_schema.TABLES` (`DATA_LENGTH`, `INDEX_LENGTH`, `TABLE_ROWS`). Missing cleanup-index metadata or required filesystem/binlog information makes Demo fail closed; unavailable table-size statistics use the documented conservative estimate.

Original retains its straightforward inclusive per-second result contract but processes timestamp state in aligned 3,600-second windows. Calls crossing a window are clipped to each window's inclusive bounds, compact peak/range summaries are merged across boundaries, and the temporary seconds map is then discarded. This bounds the timestamp dimension of working memory without imposing a new duration cap on Trunk or Extension; Group retains its existing 86,400-second contribution cap. Estimator progress remains actual occupied-second work, not chunks completed.

At calculation checkpoints, Concurrency Count also observes its PHP process allocation without changing `memory_limit`. For a finite configured limit it reserves the larger of 16 MiB or 20 percent, capped at half the limit for unusually small limits, and stops at the resulting safe ceiling. This is preventive headroom for structured failure handling, serialization, cleanup and FreePBX; it does not claim that PHP hard memory exhaustion can always be recovered afterward. A soft-memory stop retains any previous completed report and suggests Sweep, a shorter range or a narrower endpoint filter. Unlimited or invalid memory-limit values disable this secondary guard rather than inventing a ceiling.

#### Stop and terminal behavior

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

The Historical Reports workspace supports at most five open Historic Report tabs. **Start Historical Report** opens configuration without consuming a slot. A slot is allocated only after a validated **Run report** submission; a failed first calculation removes its unused definition. Stable internal IDs and slots are independent of editable names, and closing a tab frees its slot for reuse.

For a completed report, **Edit Report** reopens the same configuration with the submitted criteria. Cancelling leaves the displayed result unchanged; **Run Again** replaces it only after the revised calculation completes successfully. The completed result records the global Excluded Calls configuration used, and a rerun stops clearly if that configuration changed outside the normal invalidate-and-regenerate workflow.

A sixth report is rejected without replacing an existing tab. The five-report limit and slot allocation are enforced atomically by the backend as well as presented in the GUI.

Persisted report-definition fields include:

- name, stable ID and slot;
- mode, engine and selected trunk/extension filter;
- date preset identity and resolved/custom date information;
- Include time and its From/To values;
- Minimum concurrency;
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

Without a Minimum concurrency floor, Historical graph points retain exact numeric counts, including 1. With a floor, the complete graph is still calculated exactly, below-floor points are presented as gaps, and the actual calculated peak remains unchanged. The X axis always spans the selected report window, so filtering or sparse activity cannot move qualifying buckets out of their true temporal position. Graph state is derived at the selected start boundary, only changes in the displayed range affect that range, and the end-boundary state is explicit; the same inclusive call-interval rules apply. Trunk results expose occurrence timing and lazy contributing-call detail; activity-only Trunks use the same underlying result and detail data, not a reduced summary.

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

Stopping monitoring clears the trunk's active episode state. Restarting evaluates the next snapshot as a fresh episode. Hidden trunks remain available for Unhide and Start/Stop Monitoring. Visible cards can be reordered by dragging the reorder handle; with keyboard focus on that handle, Left and Right Arrow move the card.

Unknown saved channelids are retained but ignored while unavailable, and newly discovered trunks append after the saved order.

### Live Wall

Live Wall is presentation-only: a read-only wallboard using the same latest browser snapshot, rolling history and polling path as Live View. Overall remains primary. The required ordered selection depends on the configured PJSIP trunk inventory: no configured trunks permits Overall-only; one, two or three configured trunks require 1/1, 2/2 or 3/3 respectively; and more than three requires exactly three.

Live Wall launch opens **Configure Live Wall** when the saved selection is incomplete. No trunk is selected or substituted automatically, so deleting a selected trunk can require reconfiguration. Hidden featured trunks remain selected but are suppressed from presentation. Monitoring-stopped featured trunks remain valid and display current data. Saved left-to-right order remains authoritative. All configured trunks, including hidden, monitoring-stopped and unfeatured trunks, continue to contribute to Overall. The desktop composition targets Overall plus three equal cards at conventional 1080p and scales or stacks elsewhere.

An already-valid launch requests the Fullscreen API directly from the launch gesture when available, then revalidates the current inventory; an invalidated selection closes the Wall and opens configuration. A first-time configure-and-save continuation enters the full-page Wall without assuming the earlier gesture can still request fullscreen. Denial leaves the full-page Wall active. **Full Screen** is available whenever Live Wall is active, supported and outside browser fullscreen. Browser Esc exits fullscreen but leaves Live Wall active and makes **Full Screen** available again; **Exit Live Wall** returns to Live View.

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

Demo requires a server-side execution timeout that applies to cleanup `DELETE` statements. It is available on MariaDB 10.1.1 or later through `max_statement_time` and unavailable on legacy MariaDB and all supported MySQL versions because MySQL `max_execution_time` protects SELECT statements only. Ordinary Historical remains available on supported MySQL and through the adaptive legacy-MariaDB path above. Demo rejection occurs before stale recovery, cleanup, disk inspection, insertion or other Demo CDR work because an individual unprotected cleanup `DELETE` could remain inside the database beyond the five-minute logical cleanup allowance. Before stale recovery or any exact cleanup DELETE, supported Demo sessions verify from `information_schema.STATISTICS` that the CDR table has an index whose leading column is `accountcode`. This makes exact reserved-accountcode cleanup use an identifiable access path; absent, non-leading, insufficient-prefix or unavailable index metadata fails closed without changing the CDR schema. For a new run, that access-path check precedes stale recovery; the separate disk/binlog capacity check then determines whether new rows may be inserted. Demo reads the MariaDB data directory and verifies its local backing filesystem. Its estimate uses four times the CDR table's allocated bytes per reported row for indexes, page churn and approximate table statistics and never falls below 16 KiB per requested row. When binary logging is enabled, Demo resolves `@@log_bin_basename`; the same filesystem receives another row allowance, while a separate binary-log filesystem receives its own requirement and larger-of-1-GiB-or-20% reserve. An unavailable binary-log location fails closed. Missing, remote or invalid filesystem information also fails closed. The GUI shows requested rows, estimated need, free space, reserve and safely available space before asking to run.

After preflight, Demo inserts deterministic CDR rows tagged with a unique `CCDEMO*` accountcode in bounded groups with cancellation and resource checkpoints every 100 rows. It rechecks filesystem headroom between groups and aborts if remaining safety or actual growth invalidates the plan. It then uses the same five-minute progress, ETA, PBX impact, pause, reassessment and runtime framework as Historical. Cleanup runs in `finally`, deletes by the unique tag and verifies zero remaining rows after success, Stop, cancellation, runtime/resource/disk failure or ordinary exceptions. Mandatory cleanup does not reuse the calculation's terminal cancellation, runtime or memory checkpoint; it has a separate monotonic five-minute housekeeping allowance, a bounded 330-second PHP execution-time backstop, the verified exact indexed access path, bounded adaptive batches and database lock/statement protections. Its registry heartbeat remains active throughout housekeeping so concurrent recovery does not treat it as stale. Successful cleanup removes the registry afterwards; a genuine cleanup failure retains it for recovery after five minutes without a heartbeat.

Demo is a capacity assessment of this Historical/CDR processing workload on this PBX. Its summary includes only measurements obtained reliably. It does not test maximum simultaneous voice-call capacity.

Omitted Demo arguments retain their documented defaults. Explicit invalid Demo report modes, sizes or comparison-engine values are rejected rather than silently replaced; Original remains the default comparison engine and Sweep remains experimental.

`CCDEMO` followed by exactly eight lowercase hexadecimal characters is reserved for synthetic rows and is always excluded from ordinary Historical SQL and post-fetch processing. Cleanup runs in `finally`; it halves timed-out exact-tag delete batches from 1,000 rows and treats a successful batch shorter than its limit as verified exhaustion, avoiding a second full-table `COUNT(*)` scan. MariaDB `max_statement_time` covers cleanup statements. MySQL Demo is unavailable because `max_execution_time` does not cover `DELETE`; bounded batches and the two-second InnoDB lock-wait limit are retained as additional protections but are not treated as execution deadlines. A durable registry heartbeat is refreshed during an active Demo. Before each later Demo preflight or run, registry entries inactive for five minutes are recovered after fatal error, server kill, database interruption or host crash. A failed recovery remains registered for a later retry. Demo calls cannot be persistently excluded, and Demo never consumes a Historic Report slot.

Demo lacks a dedicated FreePBX permission or feature flag. Treat it as an administrator/test-PBX feature.

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

- Complete a small report before five minutes and confirm progress reaches 100% while ETA remains Calculating until completion.
- Run a report longer than five minutes; confirm visible monotonic progress, the full five-minute ETA gate, and a High-confidence ETA only after stable evidence.
- Create sustained High impact and confirm cooperative pause, Continue Anyway with no repeated advisory, Stop while paused, and hard memory/runtime protection after continuation.
- Create an unrelated temporary load spike, choose Recalculate, and confirm work/progress/runtime origin survive; test both a clean reassessment that continues and repeated concern that pauses again.
- Increase a paused run from 60 minutes, confirm maximum runtime remaining changes without elapsed reset, and reject values above 1,440 minutes.
- Choose Reduce Date Range, alter the range and rerun; confirm a fresh calculation ID, progress, assessment and 60-minute allowance while any earlier completed result remains until success.
- Stop while running and paused; refresh and close the browser while paused; confirm ownership lease cleanup and that no partial result replaces a completed result.
- Exercise Asterisk restart and unrelated system activity during calculation where applicable, plus the PHP memory guard.
- Run safe and unsafe Demo preflights. Confirm unsafe preflight inserts zero rows, including with the database filesystem nearly full in a controlled test environment.
- Cancel and abandon Demo during insertion and calculation, and confirm verified zero `CCDEMO*` rows. Run a large Demo workload and inspect the capacity-assessment summary.
- Test a host where one or more procfs metrics are unavailable and confirm omitted values do not appear as zero or stop the calculation.
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
php tests/CliCancellationControlTest.php
php tests/DemoDiskGuardTest.php
php tests/DemoCleanupServiceTest.php
php tests/DemoTerminalCleanupTest.php
php tests/EngineParityTest.php
php tests/EngineRuntimeCheckpointTest.php
php tests/FreepbxEntityResolverTest.php
php tests/HistoricalCalculationControlTest.php
php tests/HistoricalAssessmentTest.php
php tests/HistoricalCallExclusionServiceTest.php
php tests/HistoricalCdrAcquisitionTest.php
php tests/HistoricalDatabaseCapabilitiesTest.php
php tests/HistoricalEndpointFilterServiceTest.php
php tests/HistoricalImpactAssessmentTest.php
php tests/HistoricalMemoryGuardTest.php
php tests/HistoricalNoControllerTest.php
php tests/HistoricalReportsServiceTest.php
php tests/HistoricalRuntimeEstimatorTest.php
php tests/HistoricalResultFloorTest.php
php tests/HistoricalFloorOutputTest.php
php tests/HistoricalTelemetryCadenceTest.php
php tests/InputValidationTest.php
php tests/LiveServicesTest.php
php tests/PeakDetailAnalyserTest.php
php tests/PjsipIdentityServiceTest.php
php tests/OriginalWindowingTest.php
php tests/OriginalMemoryBenchmarkTest.php
php tests/SettingsRepositoryTest.php
php tests/SystemResourceTelemetryTest.php
php tests/concurrencycount_admin_contract.php
php tests/concurrencycount_console_contract.php
php tests/concurrencycount_release_contract.php
node tests/DateRangeTest.js
node tests/HistoricalRunStateTest.js
node tests/TelemetryFormatTest.js
```

Source checks include:

```bash
node --check assets/js/concurrencycount.js
node --check assets/js/live-view.js
node --check assets/js/date-range.js
node --check assets/js/concurrency-charts.js
node --check assets/js/historical-run-state.js
node --check assets/js/telemetry-format.js
find . -path './.git' -prune -o -type f -name '*.php' -print | while IFS= read -r file; do php -l "$file"; done
git diff --check
```

These tests do not replace real PBX/browser validation.

## Future hardening

- Add a FreePBX permission or setting before Demo can write CDR rows.
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

For example: `Assisted-by: OpenAI-Codex:gpt-5.6-sol`

The human contributor remains solely responsible. AI tools must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne // [FreePBX UK](https://github.com/freepbxUK)

# Concurrency Count 2.1.0 for FreePBX/PBXact 16 and 17

**NOT CURRENTLY SUITABLE FOR PRODUCTION.**

Concurrency Count provides live and historical visibility into PJSIP concurrency on FreePBX and PBXact. It can monitor current trunk and extension-side activity from Asterisk, track Overall Live Concurrency, apply per-trunk and global thresholds with unattended alerting and recovery notifications, and reconstruct historical concurrency from CDR data for trunks, individual extensions, or PBX-wide extension-leg activity.

Historical reporting includes persistent report workspaces, flexible date and time ranges, concurrency graphs, inline trunk peak occurrences, lazy contributing-call drill-down, FreePBX entity resolution, and CDR Reports integration. Normal reporting is read-only against `asteriskcdrdb`; the separate Demo mode temporarily writes tagged synthetic CDR rows, validates calculated results, and removes the test data after the run.

Individual logical calls shown in Historical Trunk peak detail can be globally excluded from Concurrency Count reporting without deleting or modifying their source CDRs. Exclusions apply to Trunk, Extension and Group calculations in every current and future Historic Report, and are also honoured by CLI historical calculations. Every Historic Report provides an **Excluded Calls** view where administrators can see whether a call would otherwise be eligible for that report, restore one call, or restore all exclusions. Exclusions use Asterisk `linkedid` where available and `uniqueid` as the safe fallback; Demo calls cannot be persistently excluded. Live View and Live Wall are unaffected.

Concurrency Count provides both GUI and CLI access to its reporting and operational features. Live View is the configurable everyday dashboard, while Live Wall provides a read-only large-screen presentation from the same live snapshot. The module supports FreePBX/PBXact 16 and 17 and includes supervised background workers for live threshold monitoring and notification delivery.

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

Historical presentation distinguishes activity from true concurrency: an exact peak of 1 means activity occurred but no eligible calls or legs overlapped, while concurrency begins at 2. The engines retain the exact calculated value, and activity-only Trunk and Extension results remain available through a restrained disclosure with existing Trunk occurrence and call drill-down intact. Graphs and raw exports may therefore still contain the numeric value 1, but the GUI does not describe one active call as concurrent.

## Reporting modes

### Trunk Concurrency

**What it measures:** Trunks reports the busiest simultaneous use of each configured or manually classified PJSIP trunk endpoint. It counts matching trunk legs from both `channel` and `dstchannel`. A peak of 4 for a trunk means that, at the busiest instant in the selected period, four included CDR legs were occupying that trunk simultaneously. It is a trunk-capacity measure, not necessarily four answered conversations at that instant.

**How calls are identified:** The module uses FreePBX Core's configured PJSIP trunk inventory and matches the endpoint in each actual CDR `channel`/`dstchannel` leg. Numeric trunk channelids are supported. If the same trunk appears on both sides of one CDR, both matching legs can count.

**What concurrent means here:** Matching legs for the same trunk have overlapping inclusive CDR intervals. Trunks are evaluated separately; activity on another trunk does not add to this trunk's peak.

**What the result means:** The table gives a separate maximum for every discovered trunk, including trunks with no matching calls. The global maximum is the largest of those per-trunk peaks. For example, suppose a trunk carries three inbound calls and two outbound calls during a day. At 14:32, two inbound and two outbound trunk legs overlap, while the fifth call occurs later. That trunk's peak is 4.

**Peak drill-down:** Each trunk displays its compact peak occurrences directly beneath its summary. One uninterrupted period at the trunk's maximum is one occurrence, not one row per second. If the peak is reached, drops, and is reached again, those are separate occurrences. Expanding any occurrence in place lazily loads the CDRs whose trunk legs overlap it; multiple occurrences can remain open, and their loaded details are cached independently within the current Historic Report tab. Direction is based on the actual trunk-leg position: trunk in `channel` is inbound, trunk in `dstchannel` is outbound, and ambiguous placement is shown as unknown rather than guessed from telephone numbers.

A peak of 4 always means four trunk legs were active at each instant represented as peak. The complete occurrence can nevertheless show five distinct CDR records: one call may end while another starts at the same boundary second, replacing it without the concurrency falling below 4. This is participation across the continuous occurrence, not a claim that all five were active together.

Where a CDR proves a FreePBX object, the drill-down links to its native administration page. **View in CDR Reports** opens the native CDR report narrowed to the call minute, caller number, destination and DID. Unresolved values remain ordinary text.

### Extension Concurrency

**What it measures:** Extensions reports a separate maximum for each configured FreePBX PJSIP device endpoint selected from CDR channel data. A peak of 2 for extension 203 means two included extension legs assigned to 203 overlap at their busiest instant.

**How calls are identified:** Each answered CDR `channel` and `dstchannel` is parsed as an actual PJSIP endpoint and classified against FreePBX's configured PJSIP devices. Preserving established per-extension semantics, at most one extension is assigned per CDR: a classified destination endpoint is preferred, otherwise a classified source endpoint is used. Numeric and alphanumeric device IDs are supported. The dialled `dst` value is metadata, not endpoint identity, so destinations such as 999, 911, 111 or other 1XX values neither create an extension nor suppress a genuine configured extension leg.

**What concurrent means here:** CDR intervals assigned to the same extension overlap under the shared inclusive rule. Records assigned to different extensions are not added together in the per-extension peak.

**What the result means:** The table reports each selected extension separately; the global maximum is the highest peak reached by any one extension, not the total across the PBX. For example, extension 203 may have one inbound CDR and one outbound CDR whose recorded intervals overlap for 20 seconds. Extension 203 then has a peak of 2. Extension mode does not currently provide the trunk occurrence/CDR drill-down.

### Group Concurrency

**What it measures:** The internal mode name is `group`, but it does not mean a configured FreePBX Ring Group, queue, department or chosen member list. It is a PBX-wide total of all attributable configured or manually classified PJSIP extension legs found in the selected answered CDRs.

**How calls are identified:** Every PJSIP side in `channel` and `dstchannel` classified as an extension contributes independently. One CDR can therefore add two to the total when both sides are configured extensions, as with an internal call. There is no configurable membership and no per-member result in this mode. To limit anomalously long data, each CDR contributes for at most 24 hours.

**What concurrent means here:** All overlapping attributable, classified PJSIP extension legs are added into one PBX-wide total using the shared inclusive boundary rule. This is the only current mode that aggregates different extensions into one peak.

**What the result means:** The single reported maximum is the largest number of attributable extension legs active across the PBX at the same instant. Peak time ranges show when that overall maximum was sustained. For example, an internal call from 201 to 202 contributes two legs; at the same time extension 203 is on an external call and contributes one leg. Group Concurrency is 3, even though there are only two CDR conversations. This mode does not currently provide contributing-call drill-down.

### Trunk capacity versus overall extension activity

Trunk Concurrency and Group Concurrency are different views of activity and should not be added together. An inbound call may contribute a trunk leg to the selected trunk's external-capacity result and a PJSIP extension leg to the PBX-wide extension-side result. Trunk mode asks how much external SIP capacity was in use; Group mode asks how much extension-side activity was occurring. The exact CDR topology determines which legs appear in each view.

### Demo and engine comparison

Demo is an accuracy and performance test, not a fourth live reporting scope. It creates isolated synthetic CDRs, runs the chosen Trunk, Extension or Group-total calculation through the normal CDR path, compares the result with an independently calculated expectation, and removes the synthetic rows. In the GUI, selecting more than one demo engine enables **Compare Engines**, which shows accuracy, wall time, peak memory and throughput for each selected engine. Comparison does not change what the selected reporting mode measures.

## Engines

Engines are calculation strategies, not reporting modes. Choosing an engine does not change which CDRs Trunk, Extension or Group mode measures or what its peak means.

**Original** is the default, recommended reference implementation. It walks every included second of every selected CDR leg. Administrators should normally leave Original selected because it is the established behaviour against which alternatives are checked.

**Sweep** is experimental. It processes start and end events instead of visiting every occupied second, while preserving the same inclusive boundaries and intended result. It can be faster and use less memory, but should be treated as experimental and checked through Demo comparison before operational use.

## Live View and Live Wall

Live View is a separate current-state dashboard backed by Asterisk Manager Interface channel data. It does not derive live values from CDRs and does not store browser samples as historical records.

The **Live View** / **Historical Reports** controls at the top of the page are workspace tabs, not enable/disable switches. Selecting one changes which view is shown and whether the browser polls for live updates; it does not control the supervised PM2 worker.

Each configured Live trunk can be hidden from the dashboard without changing its current snapshot, thresholds, alerts, monitoring state, SIP configuration, historical reporting, or contribution to Overall Live Concurrency. Hidden trunks remain available in a compact section for Unhide and Start/Stop Monitoring. Visible cards can be reordered by drag handle or keyboard-operable Move earlier/Move later controls. `hidden_trunks` and `trunk_order` are persisted in the existing module settings table. Unknown saved channelids are retained but ignored while unavailable; newly discovered trunks append after the saved order.

Start/Stop Monitoring is an independent operational gate stored as `live_settings.trunks[channelid].monitored`. Stopping monitoring skips unattended per-trunk threshold episode and notification evaluation while retaining current counts and threshold configuration. Overall evaluation continues. Stopping clears that trunk's active episode state; restarting evaluates the next current snapshot as a fresh episode, avoiding stale recovery transitions. The established threshold `enabled` and `alert_enabled` controls retain their existing meanings.

**Live Wall** is a read-only, responsive wallboard using the same latest browser snapshot, rolling history and single polling path as Live View. Administrators use **Configure Live Wall** in normal Live View to choose zero to three featured trunks and arrange their left-to-right order; Overall Live Concurrency remains primary even when none are selected. Hidden featured trunks are suppressed without losing their saved position, while monitoring-stopped featured trunks still display current data and continue contributing to Overall. The desktop composition targets Overall plus three equal trunk cards within a conventional 1080p viewport and scales or stacks for other displays. Its launch action requests the standard Fullscreen API when available; denial simply leaves the full-page wall presentation active. Browser Esc exits fullscreen while leaving Live Wall active, and **Exit Live Wall** always returns to Live View.

These preferences use the current FreePBX Core PJSIP trunk `channelid`. Changing a trunk channelid can leave saved visibility, ordering, featured-wall or monitoring preferences associated with the old identifier; 2.1.0 does not attempt automatic identity migration.

**Overall Live Concurrency** counts active attributable PJSIP call legs: every current `PJSIP/<trunk>-<channel-id>` channel matching a configured or manually classified trunk, plus every current PJSIP channel matching a configured or manually classified device/extension. It is leg-based, consistent with how Trunk Concurrency and historical Group counting already work:

- a call using only a configured trunk leg (no concurrent extension leg) counts as **1**;
- an inbound or outbound call with both a trunk leg and an extension leg counts as **2**;
- an internal call between two extensions counts as **2** (both extension legs);
- Local channels, other non-PJSIP technologies, ignored endpoints, unresolved endpoints and configuration conflicts are excluded and never inflate the total.

It is not a total of every Asterisk channel — only active attributable PJSIP legs are included: configured or manually classified PJSIP trunk legs plus configured or manually classified PJSIP device legs. Hidden trunks and monitoring-stopped trunks still contribute; dashboard presentation and per-trunk monitoring state do not change Overall Live Concurrency.

**Trunk Concurrency** counts current `PJSIP/<trunk>-<channel-id>` channels which exactly match configured or manually classified PJSIP trunk endpoints. Similar names remain separate. Trunk direction uses observed AMI context where it is reliable and otherwise remains unknown.

Live and historical values answer related capacity questions but are not semantically identical. Historical **Group Concurrency** (`group` mode) deliberately counts classified extension-side legs only and excludes trunks; it has no exact equivalent to Live "Overall Live Concurrency", which deliberately includes attributable PJSIP trunk legs. Live sees channels before their calls finish, while Historical reports reconstruct answered CDR intervals afterwards. These figures should not be added together or treated as the same measurement under different names.

### PJSIP endpoint identity and anomalies

Concurrency Count identifies trunks from FreePBX Core trunk configuration and devices/extensions from `devices` rows whose technology is PJSIP. It does not infer type from digits or letters: numeric trunk channelids and alphanumeric PJSIP device IDs are supported. Unrecognised or deleted endpoints seen in historical CDRs are shown as anomalies and excluded until an administrator chooses Trunk, Extension or Ignore. These choices are stored only by Concurrency Count under `pjsip_identity_overrides`, can be reset individually or together, never alter source CDRs or FreePBX/Asterisk configuration, and are superseded whenever current authoritative FreePBX configuration identifies the endpoint. A trunk/device collision is reported as a conflict and cannot be hidden by an override.

Call exclusions are independent from endpoint classifications. Classifying an endpoint never excludes its calls, and excluding a logical call never changes how any endpoint is classified. Historical processing queries candidate CDR rows, removes globally excluded linked calls, classifies remaining PJSIP endpoints, applies report semantics, and then sends the same eligible dataset to Original or Sweep.

"Recent peak" shown under each Live metric is a rolling maximum kept only in the current browser session's in-memory series (the same series drawn on that metric's chart); it is not the backend threshold-episode peak and resets when the page is reloaded. A value recorded for a single browser sample can appear as a very narrow spike on the chart rather than a visibly sustained rise.

The browser refresh interval can be 1, 5, 10, 15, 30 or 60 seconds, with 5 seconds as the default. Requests never overlap and pause while the tab is hidden. The 1-second option is labelled aggressive and still requires the live-PBX load validation listed below. Browser refresh does not control unattended alerts.

## Unattended threshold alerts

Alerts are evaluated by one persistent PHP worker supervised by the standard FreePBX Process Management (`pm2`) module. This follows the same PM2 and AMI event-loop pattern used by FreePBX Core on releases 16 and 17.

The worker bootstraps FreePBX once, keeps one AMI connection open, and reacts to `Newchannel`, `Newstate`, `Hangup`, `Rename` and `Masquerade` lifecycle events. Relevant events trigger an immediate full channel reconciliation. A full reconciliation also runs every 5 seconds to protect against missed or reordered events. Every snapshot uses a unique AMI ActionID and is accepted only after its matching `CoreShowChannelsComplete`; incomplete or interrupted responses are unavailable, never an empty PBX. Detection is normally limited by AMI event delivery and otherwise by the approximately 5-second reconciliation interval. It does not launch a new `fwconsole` process every few seconds.

PM2 supervises two workers: the AMI event/reconciliation worker and a separate mail-delivery worker. It starts them at module installation and after Asterisk starts, stops them before Asterisk stops, restarts them after process failure, and provides status/restart operations in both GUI and CLI. Installation removes the obsolete once-per-minute cron line if it exists from an earlier development build. Monitor health is degraded when PM2 is online but no complete successful AMI snapshot has been recorded recently.

Threshold comparison remains `current >= threshold`; zero disables that threshold. Master alert enablement, per-scope alert enablement, visual thresholds and recovery notification preference remain separate settings. Alert state and a stable notification outbox record are persisted atomically before delivery, suppressing duplicates during a sustained crossing and after worker restart while tracking the episode peak. The AMI worker never blocks on SMTP. The mail worker retries failures with bounded exponential backoff.

Email delivery is **at least once**. Stable event IDs prevent duplicate queue records, but SMTP/local-mailer acceptance cannot be made atomic with the module database. A process failure in the narrow interval after mail acceptance but before outbox removal can result in a duplicate delivery; it cannot cause the threshold episode itself to be forgotten.

Version 1 does not add hidden hysteresis: dropping below the threshold completes an episode, and a later `>=` crossing starts a new one. Rapid oscillation can therefore produce one alert/recovery pair per genuine episode, but never repeated alerts while a scope remains continuously above threshold.

AMI loss is never interpreted as zero concurrency. The worker retains persisted alert state, sends no false recovery, reconnects with exponential backoff up to 30 seconds, reseeds from a successful snapshot, and resumes evaluation only after live data is available again.

## GUI and CLI parity

The GUI and `fwconsole concurrencycount` call the same live snapshot, settings, validation, threshold and monitor lifecycle methods. Existing historical CLI syntax remains valid. New operations are additive:

| GUI capability | CLI equivalent |
| --- | --- |
| Current live snapshot and active channels | `fwconsole concurrencycount --live` |
| Machine-readable live snapshot | `fwconsole concurrencycount --live --json` |
| View settings and thresholds | `fwconsole concurrencycount --settings` |
| Browser refresh interval | `fwconsole concurrencycount --set-refresh=5` |
| Overall threshold value | `fwconsole concurrencycount --set-overall-threshold=30` |
| Overall visual threshold enabled | `fwconsole concurrencycount --overall-threshold=on` |
| Trunk threshold value | `fwconsole concurrencycount --set-trunk-threshold='gamma=8'` |
| Trunk visual threshold enabled | `fwconsole concurrencycount --trunk-threshold='gamma=on'` |
| Master alerts enabled | `fwconsole concurrencycount --alerts=on` |
| Overall alert enabled | `fwconsole concurrencycount --overall-alert=on` |
| Per-trunk alert enabled | `fwconsole concurrencycount --trunk-alert='gamma=on'` |
| Start per-trunk unattended monitoring | `fwconsole concurrencycount --start-monitoring='gamma'` |
| Stop per-trunk unattended monitoring | `fwconsole concurrencycount --stop-monitoring='gamma'` |
| Recovery notifications | `fwconsole concurrencycount --recovery=on` |
| Alert email | `fwconsole concurrencycount --alert-email=admin@example.com` |
| Alert monitor status | `fwconsole concurrencycount --monitor-status` |
| Restart alert monitor | `fwconsole concurrencycount --restart-monitor` |
| One manual threshold evaluation for diagnostics | `fwconsole concurrencycount --monitor` |
| Historical graph data | `fwconsole concurrencycount --historical-graph=trunk --graph-trunk=gamma --start='...' --end='...' --json` |
| List persisted historical report tabs | `fwconsole concurrencycount --list-historical-reports` |
| Show one persisted historical report tab | `fwconsole concurrencycount --show-historical-report=2` |
| Close/delete one persisted historical report tab | `fwconsole concurrencycount --delete-historical-report=2` |

Ordinary live CLI queries take one snapshot and exit. They do not poll continuously and do not replace the unattended PM2 monitor.

## GUI report flow

1. Choose Trunk Concurrency, Extension Concurrency or Group Concurrency, then choose an engine. Demo runs use the separate **Run Demo** button.
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

## Historical report tabs

Historical Reports supports up to five open report tabs inside the workspace, each with its own name, mode, engine, date preset/range, Include time setting and generated result. Clicking **Start Historical Report** opens the shared configuration modal without creating a tab or consuming a slot. Only a validated **Run report** submission creates the persisted definition, allocates the lowest free slot and selects the new tab; cancelling the modal changes nothing. The suggested editable name is `Historic Report N` for the next locally available slot, while the locked server allocation remains authoritative if availability changes before submission. A failed first calculation removes its unused definition so it does not strand one of the five slots.

Report names are trimmed, limited to 80 plain-text characters, escaped when rendered, and persisted independently of the stable internal id and slot number. Custom names survive reloads and appear in GUI tabs and CLI list/show output. Older saved definitions without a name automatically use `Historic Report <slot>`. Closing a tab (its `x` button) removes only that report; the freed slot number is reused by the next new report rather than counting upward forever. A sixth attempt shows "Maximum of 5 historical reports can be open at once." and does not replace an existing tab; the limit is enforced both in the GUI and, atomically, in the backend.

**Persistence.** Each tab's *definition* - name, mode, engine, date preset identity, resolved/custom dates, Include time, and any selected trunk/extension filter - is saved in Concurrency Count's existing settings table (the same key/value store used for Live thresholds), not in browser storage, so tabs survive reload, logout and FreePBX navigation. CDR results and graph points are never persisted: reopening the module recreates the tab headers immediately and regenerates each report's data on demand (the previously active tab first, others when you switch to them), so a page load never fires five CDR queries at once. A relative preset such as **Last 7 days** is stored as that preset identity and is re-resolved against the current date on every restore; **Custom** stores and reuses its exact chosen dates. If a saved report's trunk/extension filter no longer matches current configuration it is marked in its tab rather than silently retargeted or discarded.

**Demo** is unaffected by report tabs: it remains a synthetic accuracy/performance check that renders into the same shared results view but never reads or writes a saved report tab's state, so it can never overwrite one of your open reports.

Saved report tabs are inspectable and manageable from the CLI: `--list-historical-reports`, `--show-historical-report=<number-or-id>` and `--delete-historical-report=<number-or-id>` (numbers 1-5, or the stable internal id). These are additive management operations; they do not run a report or replace existing CLI syntax.



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

## Required live-PBX validation

Before production use, perform these checks on both FreePBX 16 and 17:

1. Confirm `fwconsole concurrencycount --monitor-status` reports `ONLINE` after install, `fwconsole restart`, Asterisk restart and module upgrade.
2. With the browser closed, hold an overall or trunk threshold above its boundary for less than one minute, then for less than 30 seconds. Confirm one alert is accepted by the local mailer.
3. Hold a threshold above for several minutes. Confirm only one initial alert, correct peak tracking and one optional recovery.
4. Restart the monitor while still above threshold. Confirm persisted state prevents a duplicate initial alert.
5. Interrupt AMI or restart Asterisk while an alert episode is active. Confirm no false recovery, then confirm normal reconciliation after reconnect.
6. Exercise multiple trunks, similarly named trunks and simultaneous overall/trunk threshold crossings.
7. Verify idle, inbound, outbound, mixed inbound/outbound and internal extension calls against `fwconsole concurrencycount --live --json` and the GUI.
8. Leave the dashboard open for an extended period and verify bounded browser history, stale timestamps, hidden-tab pause and clean resume.
9. Test 1-second browser polling with idle, light and heavy channel counts, several trunks, one dashboard, and multiple tabs. Observe AJAX/AMI latency, PHP and Asterisk CPU, request overlap and browser responsiveness. Retain it only if operational load is acceptable.
10. Verify alert email content and local-mailer acceptance; do not treat that as proof of external delivery.
11. Verify desktop, tablet and approximately 320px layouts.

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

## AI-Assisted Contributions and Disclosure

This module has been developed with AI assistance for code generation, review, testing, and documentation. From 26 August 2026, generative AI assistance must be disclosed in every commit containing AI-assisted changes:

```text
Assisted-by: AGENT_NAME:MODEL_VERSION
```

For example: `Assisted-by: GitHub-Copilot:gpt-5.6-sol`

The human contributor remains solely responsible for the contribution. AI tools must not be listed as co-authors.

## Author

[@kierknoby](https://github.com/kierknoby), Kieran Knowles-Byrne // [FreePBX UK](https://github.com/freepbxUK)

=== Update Doctor ===
Contributors: csmcneill
Tags: updates, automatic updates, diagnostics, troubleshooting, maintenance
Requires at least: 5.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Diagnoses why WordPress automatic updates aren't running. Inspects constants, filter callbacks, cron, filesystem, options, and per-item state.

== Description ==

Update Doctor is a diagnostic plugin for WordPress site owners whose automatic plugin, theme, or core updates have stopped working — even though everything looks correctly configured.

WordPress's auto-update decision involves a long chain: PHP constants in `wp-config.php`, callbacks attached to half a dozen filter hooks, scheduled cron events, filesystem permissions, database options and transients, and per-item opt-ins. There's no single place that says *"X is the reason updates aren't running for plugin Y."* Update Doctor walks every layer of that chain and reports its findings in plain language, including the source file and line number for each filter callback so you can find third-party code that's interfering.

= What it checks =

- **Constants** — `AUTOMATIC_UPDATER_DISABLED`, `DISALLOW_FILE_MODS`, `WP_AUTO_UPDATE_CORE`, `DISABLE_WP_CRON`, `ALTERNATE_WP_CRON`, `FS_METHOD`, `WP_DEBUG_LOG`.
- **Filters and Hooks** — every callback registered to `automatic_updater_disabled`, `auto_update_plugin`, `auto_update_theme`, `auto_update_core`, `auto_update_translation`, `file_mod_allowed`, and the update transient filters, with file path and line number.
- **Cron Schedule** — whether `wp_maybe_auto_update`, `wp_update_plugins`, `wp_update_themes`, and `wp_version_check` are scheduled and not overdue.
- **Filesystem** — writability of `wp-content/upgrade/`, `upgrade-temp-backup/`, plugin and theme directories. Free disk space. WP_Filesystem method.
- **Options and Transients** — stale `auto_updater.lock`, age of update transients, per-item opt-ins.
- **Per-Plugin and Per-Theme Decisions** — uses `WP_Automatic_Updater::should_update()` to ask WordPress directly whether each item would auto-update right now.
- **String Scanner** — searches `wp-content/plugins/`, `mu-plugins/`, and `uploads/` for code referencing the auto-update kill-switches, including cached snippet libraries that aren't currently loaded as filters.
- **Error Log** — tails recent entries from the WordPress debug log and surfaces anything related to background updates.

= What you can do with it =

- **Run Background Update Now** — manually trigger `wp_maybe_auto_update()` and capture the output without waiting 12 hours.
- **Copy Markdown Report** — share findings with your host's support team or paste them into a GitHub issue.
- **Opt-in failure notifications** — when enabled, Update Doctor sends one email per 24 hours (max) if it detects a failed or silently skipped auto-update. The email is intentionally minimal and points back to this page for details.

= What it does NOT do =

- It does not apply fixes automatically — diagnostics only.
- It does not phone home or send any data outside your site.
- It does not depend on any external service.

== Installation ==

1. Upload the `update-doctor` folder to `/wp-content/plugins/`.
2. Activate the plugin from the Plugins screen in wp-admin.
3. Go to **Tools → Update Doctor**.

== Frequently Asked Questions ==

= Will this fix my updates? =

No. It diagnoses and reports. The findings tell you what's blocking updates so you can fix the underlying cause (or hand the report to your host's support team).

= Is it safe to run on a production site? =

Yes. The diagnostics are read-only — they inspect existing state without changing anything. The "Run Background Update Now" button does run real updates if any are pending; that's the same `wp_maybe_auto_update()` call WordPress runs on its own cron schedule.

= Why is the email opt-in and disabled by default? =

Email notifications add a side-effect that some site owners may not want (for example, if `wp_mail` isn't reliably configured). Disabled-by-default is the safer choice; you can enable it in **Tools → Update Doctor** if you want it.

= Does it conflict with WordPress's built-in update emails? =

WordPress core has sent auto-update result emails since 5.5. Update Doctor's email is additive: it covers silent skips (which core does not email about) and gives you a uniform "open the diagnostic page" call to action. You may receive both emails if you enable Update Doctor's notifications.

== Changelog ==

= 1.1.7 =
* Fix: the Last Update Attempt check is now opt-in aware. When the auto_update filter returns false for an item, it cross-references the auto_update_plugins / auto_update_themes options. A "false" for an item that simply isn't opted in is now reported as expected (INFO), not as an issue. Only a "false" for an item that IS opted in is flagged as a fault — that means a filter callback is overriding the opt-in, and the affected items are named. Previously every false-return was reported as a runtime anomaly, which was misleading for not-opted-in plugins.
* New: auto_updater.lock lifecycle probe. The trigger now observes (without touching the lock) whether auto_updater.lock is written and released during the run, via the added_option / updated_option / deleted_option actions. WP_Upgrader::release_lock() deletes the option, so a delete during the run proves run() acquired and released the lock — i.e. it got past the create_lock() gate. When iteration never starts and no lock write/release is observed (and the lock is free before and after), the diagnosis now identifies likely lock contention: another process (on managed hosts, the platform's own update runner) held the lock when the manual trigger fired. This is the signature that best fits a manual trigger doing nothing while the host's scheduled updates still work.

= 1.1.6 =
* Fix: the Unattended Update Gate check no longer reimplements WordPress's gate conditions (the v1.1.5 ownership comparison produced a false-positive "[MISMATCH]" warning on a healthy site). It now calls WordPress's own functions directly and reports their actual return values: `request_filesystem_credentials( '', '', false, WP_PLUGIN_DIR, null, false )` for the filesystem branch, and the public `WP_Automatic_Updater::is_vcs_checkout()` for both the plugin and ABSPATH contexts. These are exactly the calls should_update() makes, so there are no false positives.
* New: when a VCS checkout is detected, the check locates the offending `.git`/`.svn`/`.hg`/`.bzr` directory by scanning the same trees WordPress inspects, and names the path so it can be removed.
* New: FS_METHOD awareness. When FS_METHOD is defined as "direct" (common on managed hosts such as Pressable and WordPress.com Atomic), the filesystem branch of the gate is forced to succeed, so it cannot be the cause — the check says so explicitly, which points the diagnosis squarely at the VCS-checkout condition.
* Change: the Last Update Attempt diagnosis for the "read returned items but zero iterations" case no longer leans on file-ownership as the likely cause; it now notes that on FS_METHOD=direct sites the culprit is a VCS checkout, and defers to the Unattended Update Gate section which reports the exact failing condition.

= 1.1.5 =
* New: **Unattended Update Gate check.** This is the big one. WP_Automatic_Updater::should_update() has a gate that runs BEFORE the auto_update_{$type} filter: it returns false if it cannot obtain direct filesystem access (request_filesystem_credentials with strict ownership) or if it detects a VCS checkout. When this gate fails, every plugin and theme is silently skipped during automatic updates — even with auto-updates enabled and the update transient fully populated — and nothing in the wp-admin UI reveals why. The new check replicates this gate exactly: it resolves the filesystem method for the plugin and theme contexts with strict ownership (distinct from the relaxed, context-free check the Filesystem section reports), compares file ownership against the PHP process uid, and scans for VCS metadata directories up to the install root.
* Fix: the Last Update Attempt check no longer misreports the "read returned items but zero iterations" case. Previously it fell through to a generic "if response_count=0..." message even when the read clearly returned items. It now recognizes that an iteration with zero auto_update filter invocations means should_update() bailed at the pre-filter gate, and points to the Unattended Update Gate section for the specific cause.
* Context: this release was built after tracing WP 7.0's class-wp-automatic-updater.php source directly on a live Pressable site. The pre-filter gate at the top of should_update() is the most common reason a site shows "auto-updates enabled" while nothing ever updates, and it is frequently triggered by file-ownership mismatches introduced during a host migration.

= 1.1.4 =
* New: Filters and Hooks check now inspects four additional auto-update filter points: `option_auto_update_plugins`, `option_auto_update_themes`, `pre_update_option_auto_update_plugins`, `pre_update_option_auto_update_themes`. Managed-host mu-plugins use these to strip platform-managed plugins from the user's auto-update opt-in list. With these inspected, Update Doctor now covers all eight known Atomic Platform / Pressable interception points.
* New: Per-Item check classifies each plugin as host-managed (symlinked from a shared `/wordpress/` store, updated externally by the host) or user-installed (real directory, eligible for WordPress's normal auto-update). Host-managed plugins are tagged `[host-managed]` and surface in a separate "Host-managed plugins detected" callout explaining that they're updated externally and not a bug.
* Note on architecture: this release was informed by reading the actual Atomic Platform mu-plugin source on a real Pressable site. Pressable maintains a shared `/wordpress/plugins/{slug}/{version}/` store and symlinks managed plugins (Jetpack, WooCommerce, Akismet, etc.) into each site. The mu-plugin's `is_managed_plugin` test checks whether a plugin's directory is a symlink whose realpath lives under `/wordpress/`. Update Doctor now applies the same test for accurate per-plugin classification.

= 1.1.3 =
* New: Filters and Hooks check now inspects the read-side transient filters — `pre_site_transient_update_plugins`, `pre_site_transient_update_themes`, `pre_site_transient_update_core`, `site_transient_update_plugins`, `site_transient_update_themes`, `site_transient_update_core`. A callback on these can return a stripped value when WP reads the transient, causing `WP_Automatic_Updater::run()` to iterate nothing even when the DB still contains pending items.
* New: trigger now captures transient state and `auto_updater.lock` state immediately before and after `wp_maybe_auto_update()`. Compared against what the read filters returned during run(), this isolates "transient genuinely empty" from "transient was full but the read-filter chain stripped it."
* New: Last Update Attempt check identifies "transient read interception" as a distinct failure mode when the pre-run snapshot shows pending items but the read-filter breadcrumbs show run() saw zero. This is the smoking-gun signature for managed-host mu-plugins that adjust transient visibility based on request context.

= 1.1.2 =
* New: per-item filter breadcrumbs on `auto_update_plugin`, `auto_update_theme`, and `auto_update_core` (captured at PHP_INT_MAX so the final consensus value after every other callback is recorded). This counts one invocation per item iterated by `WP_Automatic_Updater::run()` regardless of `should_update()` outcome — which distinguishes "iteration loop never executed" from "iterated N items and should_update returned false for each."
* New: `is_main_network` and `is_main_site` snapshots captured at trigger time, plus `is_multisite` context. The Last Run check now identifies multisite-context exits explicitly when applicable.
* Improved: Last Run check has five distinct diagnoses for the zero-attempts case instead of three, including per-item filter chain values when iteration ran but should_update returned false. The most informative case names exactly which filter callback to investigate.

= 1.1.1 =
* New: lifecycle breadcrumbs in the Last Update Attempt check. The trigger now hooks `automatic_updater_disabled`, `pre_auto_update`, `upgrader_pre_install`, `upgrader_pre_download`, and `upgrader_post_install` during a manual run, capturing exactly which lifecycle events fired. The Last Run check uses these breadcrumbs to distinguish "run() never started" from "ran-but-skipped-everything" from "ran-and-upgrader-aborted" — three very different failure modes that previously all reported as "nothing to do."
* New: `.maintenance` file check in the Filesystem section. A stuck maintenance flag at ABSPATH silently disables auto-updates via `WP_Automatic_Updater::is_disabled()`. Stuck flags after a failed update are a known WordPress gotcha; the check now flags this prominently.
* Improved: when the updater attempts zero items despite pending updates, the diagnostic now identifies the most likely cause based on which breadcrumbs fired, with specific suggestions for what to investigate.

= 1.1.0 =
* New: **Upgrader Hooks check.** Inspects callbacks on the WP_Upgrader hooks (`upgrader_pre_install`, `upgrader_pre_download`, `upgrader_source_selection`, `upgrader_install_package_result`, `upgrader_post_install`, `upgrader_clear_destination`, `upgrader_process_complete`, `automatic_updates_complete`). These can silently abort or modify an update mid-process even when the auto-update decision layer has cleared the update to run.
* New: **Last Update Attempt check.** Surfaces results from the most recent update run — manual or automatic — including any PHP fatal errors captured during the attempt. Fatal errors are hoisted to a top-level FAIL so they appear prominently. Also tells the user to run a live update test when pending updates exist with no recent run captured.
* New: **PHP error log tailing.** The Error Log check now also tails the PHP global error log (from `ini_get('error_log')`) in addition to WP_DEBUG_LOG, since fatal errors during updates often land in the PHP log on managed hosts. Fatal/parse errors are surfaced as FAIL even if they aren't directly tagged as update-related — they can still abort an update silently.
* New: **Live update test banner.** When pending updates exist and no recent live test is captured, a banner at the top of the page makes the next step obvious.
* Change: "Run Background Update Now" renamed to "Run Live Update Test" — clearer about what the action does. The button is now larger and placed first in the action bar.
* Change: Last-run results now persist for a week (was 30 minutes) so they remain visible across diagnostic sessions.

= 1.0.2 =
* Fix: per-item check now distinguishes between updates that will actually run and updates that are gated by a missing license or subscription. Premium plugins distributed through systems like WooCommerce.com Update Manager, Freemius, or EDD Software Licensing leave a version entry in the update transient but no package download URL when the site has no active subscription. v1.0.1 reported these as "would auto-update on next cron run," which is misleading. v1.0.2 inspects the package URL and reports them as license-gated, with a section-level summary noting how many were detected.

= 1.0.1 =
* Fix: `wp_maybe_auto_update` is no longer reported as a critical issue when it isn't scheduled. WordPress only schedules this event on demand (when an update-check finds new versions); its absence is normal on a fully up-to-date site or when the host runs auto-updates outside of WP-Cron. The check now reads `update_plugins`, `update_themes`, and `update_core` transients to decide whether the absence is meaningful.
* New: managed-host detection. Update Doctor recognises Pressable / WordPress.com Atomic, WP Engine, Kinsta, Pantheon, and Flywheel and surfaces the context in the Cron section. On those hosts, "wp_maybe_auto_update not scheduled" is downgraded to an informational note rather than a failure.
* Misc: clearer wording in the Cron section explaining which events are recurring vs. ad-hoc.

= 1.0.0 =
* Initial public release.
* Eight diagnostic checks across Constants, Filters and Hooks, Cron, Filesystem, Options and Transients, Per-Plugin/Theme decisions, String Scanner, and Error Log.
* Hook inspector resolves each filter callback to its source file and line number.
* "Run Background Update Now" button triggers `wp_maybe_auto_update()` on demand with output and error capture.
* Markdown report exporter for sharing diagnostics with hosts or support teams.
* Opt-in failure notification email (disabled by default, capped at one email per 24 hours).

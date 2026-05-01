=== Update Doctor ===
Contributors: csmcneill
Tags: updates, automatic updates, diagnostics, troubleshooting, maintenance
Requires at least: 5.5
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.1
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

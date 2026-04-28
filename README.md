## Update Doctor

A WordPress diagnostic plugin that tells you why your automatic updates aren't running.

WordPress's auto-update decision involves a long chain — PHP constants in `wp-config.php`, callbacks attached to half a dozen filter hooks, scheduled cron events, filesystem permissions, database options and transients, and per-item opt-ins. There's no single place that says *"X is the reason updates aren't running for plugin Y."* Update Doctor walks every layer of that chain and reports its findings in plain language, including the source file and line number for each filter callback so you can find third-party code that's interfering.

### What it checks

- **Constants** — `AUTOMATIC_UPDATER_DISABLED`, `DISALLOW_FILE_MODS`, `WP_AUTO_UPDATE_CORE`, `DISABLE_WP_CRON`, `ALTERNATE_WP_CRON`, `FS_METHOD`, `WP_DEBUG_LOG`.
- **Filters and Hooks** — every callback registered to `automatic_updater_disabled`, `auto_update_plugin`, `auto_update_theme`, `auto_update_core`, `auto_update_translation`, `file_mod_allowed`, and the update transient filters, with file path and line number.
- **Cron Schedule** — whether `wp_maybe_auto_update`, `wp_update_plugins`, `wp_update_themes`, and `wp_version_check` are scheduled and not overdue.
- **Filesystem** — writability of `wp-content/upgrade/`, `upgrade-temp-backup/`, plugin and theme directories. Free disk space. WP_Filesystem method.
- **Options and Transients** — stale `auto_updater.lock`, age of update transients, per-item opt-ins.
- **Per-Plugin and Per-Theme Decisions** — uses `WP_Automatic_Updater::should_update()` to ask WordPress directly whether each item would auto-update right now.
- **String Scanner** — searches `wp-content/plugins/`, `mu-plugins/`, and `uploads/` for code referencing the auto-update kill-switches, including cached snippet libraries that aren't currently loaded as filters.
- **Error Log** — tails recent entries from the WordPress debug log and surfaces anything related to background updates.

### What you can do with it

- **Run Background Update Now** — manually trigger `wp_maybe_auto_update()` and capture the output without waiting 12 hours.
- **Copy Markdown Report** — share findings with your host's support team or paste them into a GitHub issue.
- **Opt-in failure notifications** — when enabled, Update Doctor sends one email per 24 hours (max) if it detects a failed or silently skipped auto-update. The email is intentionally minimal and points back to the diagnostic page for details.

### What it does *not* do

- It does not apply fixes automatically — diagnostics only.
- It does not phone home or send any data outside your site.
- It does not depend on any external service.

### Installation

1. Clone or download this repository.
2. Place the `update-doctor` directory in your site's `wp-content/plugins/` folder.
3. Activate **Update Doctor** from the Plugins screen in wp-admin.
4. Open **Tools → Update Doctor**.

Alternatively, package the directory as a zip and upload it via Plugins → Add New → Upload Plugin.

### Requirements

- WordPress 5.5 or newer (for the modern auto-update infrastructure).
- PHP 7.4 or newer.

### Contributing

Issues and pull requests welcome at <https://github.com/csmcneill/update-doctor>. Please include:

- WordPress version
- PHP version
- The full Markdown diagnostic report (use the "Copy Markdown Report" button)
- A description of what you expected to happen and what actually happened.

### License

GPL-2.0-or-later. See [LICENSE](LICENSE).

### Author

Built by [Chris McNeill](https://csmcneill.com/).

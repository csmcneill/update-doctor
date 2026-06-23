<?php
/**
 * Inspects database options and transients that affect auto-updates.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Options_Check extends Update_Doctor_Check {

	public function id() {
		return 'options';
	}

	public function label() {
		return __( 'Options and Transients', 'update-doctor' );
	}

	public function description() {
		return __( 'Stale state in the database can prevent updates from running. This check looks for the most common offenders.', 'update-doctor' );
	}

	public function run() {
		$results = array();
		$now     = time();

		// auto_updater.lock — set when an auto-update batch is in progress.
		// WP_Upgrader::create_lock() reads/writes this row with raw SQL that bypasses the
		// object cache, while get_option() reads the cache. On a persistent object cache a
		// stale row can therefore be INVISIBLE to get_option() yet still block every run
		// (create_lock() sees the DB row, returns false, and bails before should_update).
		// So we compare the cached value against the database directly.
		global $wpdb;
		$lock     = get_option( 'auto_updater.lock' );
		$lock_db  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'auto_updater.lock' ) );
		$db_has   = ( null !== $lock_db );
		$cache_has = ! empty( $lock );

		if ( $db_has && ! $cache_has ) {
			// The masked stale lock — the smoking gun.
			$results[] = Update_Doctor_Diagnostic::fail(
				'auto_updater.lock',
				sprintf(
					__( 'A stale auto_updater.lock row exists in the database (value: %1$s, age: %2$s) but the object cache reports NO lock. This is the trap: WP_Upgrader::create_lock() reads the database row and bails, so automatic updates silently never run — while get_option() (and every UI) reads the cache and shows nothing wrong. It does not self-heal because the cache hides the row from the expiry path. This is almost certainly the root cause. Use the "Clear stuck update lock" button at the top of the page to remove it (or run: wp option delete auto_updater.lock).', 'update-doctor' ),
					(string) $lock_db,
					is_numeric( $lock_db ) ? human_time_diff( (int) $lock_db, $now ) : __( 'unknown', 'update-doctor' )
				)
			);
		} elseif ( ! $db_has && ! $cache_has ) {
			$results[] = Update_Doctor_Diagnostic::pass(
				'auto_updater.lock',
				__( 'No active auto-update lock in either the database or the cache. New updates can begin.', 'update-doctor' )
			);
		} else {
			// Lock present (in DB and/or cache). Use the DB value for age when available.
			$lock_ts = $db_has && is_numeric( $lock_db ) ? (int) $lock_db : (int) $lock;
			$age     = $now - $lock_ts;
			if ( $age > HOUR_IN_SECONDS ) {
				$results[] = Update_Doctor_Diagnostic::fail(
					'auto_updater.lock',
					sprintf(
						__( 'Lock has been held for %s. This is almost certainly stale and is preventing new auto-update runs. Use the "Clear stuck update lock" button at the top of the page, or run: wp option delete auto_updater.lock.', 'update-doctor' ),
						human_time_diff( $lock_ts, $now )
					)
				);
			} else {
				$results[] = Update_Doctor_Diagnostic::info(
					'auto_updater.lock',
					sprintf( __( 'An auto-update is currently running (lock age: %s).', 'update-doctor' ), human_time_diff( $lock_ts, $now ) )
				);
			}
		}

		// Per-item opt-ins.
		$auto_plugins = get_option( 'auto_update_plugins', array() );
		$auto_themes  = get_option( 'auto_update_themes', array() );

		$results[] = Update_Doctor_Diagnostic::info(
			'auto_update_plugins',
			sprintf(
				_n( '%d plugin opted in to auto-updates via the wp-admin UI.', '%d plugins opted in to auto-updates via the wp-admin UI.', count( (array) $auto_plugins ), 'update-doctor' ),
				count( (array) $auto_plugins )
			),
			(array) $auto_plugins
		);

		$results[] = Update_Doctor_Diagnostic::info(
			'auto_update_themes',
			sprintf(
				_n( '%d theme opted in to auto-updates via the wp-admin UI.', '%d themes opted in to auto-updates via the wp-admin UI.', count( (array) $auto_themes ), 'update-doctor' ),
				count( (array) $auto_themes )
			),
			(array) $auto_themes
		);

		// Available-update transient freshness.
		$plugin_transient = get_site_transient( 'update_plugins' );
		if ( $plugin_transient && isset( $plugin_transient->last_checked ) ) {
			$age = $now - (int) $plugin_transient->last_checked;
			if ( $age > DAY_IN_SECONDS ) {
				$results[] = Update_Doctor_Diagnostic::warn(
					'_site_transient_update_plugins',
					sprintf(
						__( 'Last refreshed %s ago. WordPress may not know which plugins have updates available. Force a refresh from Dashboard → Updates.', 'update-doctor' ),
						human_time_diff( (int) $plugin_transient->last_checked, $now )
					)
				);
			} else {
				$results[] = Update_Doctor_Diagnostic::pass(
					'_site_transient_update_plugins',
					sprintf( __( 'Last refreshed %s ago.', 'update-doctor' ), human_time_diff( (int) $plugin_transient->last_checked, $now ) )
				);
			}
		} else {
			$results[] = Update_Doctor_Diagnostic::warn(
				'_site_transient_update_plugins',
				__( 'Missing or empty. WordPress has no record of available plugin updates.', 'update-doctor' )
			);
		}

		$theme_transient = get_site_transient( 'update_themes' );
		if ( $theme_transient && isset( $theme_transient->last_checked ) ) {
			$age = $now - (int) $theme_transient->last_checked;
			if ( $age > DAY_IN_SECONDS ) {
				$results[] = Update_Doctor_Diagnostic::warn(
					'_site_transient_update_themes',
					sprintf( __( 'Last refreshed %s ago.', 'update-doctor' ), human_time_diff( (int) $theme_transient->last_checked, $now ) )
				);
			} else {
				$results[] = Update_Doctor_Diagnostic::pass(
					'_site_transient_update_themes',
					sprintf( __( 'Last refreshed %s ago.', 'update-doctor' ), human_time_diff( (int) $theme_transient->last_checked, $now ) )
				);
			}
		} else {
			$results[] = Update_Doctor_Diagnostic::warn(
				'_site_transient_update_themes',
				__( 'Missing or empty.', 'update-doctor' )
			);
		}

		return $results;
	}
}

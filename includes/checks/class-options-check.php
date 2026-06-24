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

		// Active probe: actually exercise create_lock() the way run() does, plus a raw
		// write test, so we can see WHY it fails even when no lock row is visible.
		$results[] = $this->probe_lock_acquisition();

		// Callbacks on the `query` filter can rewrite or block any SQL statement —
		// including create_lock()'s INSERT into wp_options. List them so a blocked
		// lock write can be traced to its source.
		$results[] = $this->inspect_query_filter();

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

	/**
	 * Actively exercise WP_Upgrader::create_lock('auto_updater') — the exact call that
	 * gates the per-item loop in WP_Automatic_Updater::run() — and report whether it
	 * succeeds, plus the raw write/cache state that determines the outcome. This turns
	 * "run() bailed at the lock" (an inference) into a direct, reproducible measurement,
	 * and shows WHY create_lock() fails even when no lock row is visible.
	 *
	 * @return Update_Doctor_Diagnostic
	 */
	private function probe_lock_acquisition() {
		global $wpdb;

		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$details = array();

		// Cache state, read directly (bypassing get_option's own logic).
		$details[] = 'get_option(): ' . var_export( get_option( 'auto_updater.lock' ), true );
		$details[] = 'wp_cache_get(auto_updater.lock, options): ' . var_export( wp_cache_get( 'auto_updater.lock', 'options' ), true );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		$details[] = 'in notoptions cache: ' . ( ( is_array( $notoptions ) && isset( $notoptions['auto_updater.lock'] ) ) ? 'yes' : 'no' );

		// Raw write test: can we INSERT IGNORE into wp_options at all (create_lock's mechanism)?
		$probe_name = 'update_doctor_write_probe';
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $probe_name, '1' ) );
		$write_err = $wpdb->last_error;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $probe_name ) );
		$details[] = 'raw INSERT IGNORE write test: ' . ( $write_err ? ( 'ERROR — ' . $write_err ) : 'ok' );

		// The decisive probe: actually call create_lock(), then release if it acquired.
		$acquired = WP_Upgrader::create_lock( 'auto_updater' );
		$lock_err = $wpdb->last_error;
		if ( $acquired ) {
			WP_Upgrader::release_lock( 'auto_updater' );
		}
		$details[] = "WP_Upgrader::create_lock('auto_updater') returned: " . ( $acquired ? 'true (acquired — released immediately)' : 'FALSE (could not acquire)' );
		if ( $lock_err ) {
			$details[] = 'create_lock SQL error: ' . $lock_err;
		}

		if ( ! $acquired ) {
			return Update_Doctor_Diagnostic::fail(
				__( 'create_lock() probe: the updater cannot acquire its lock', 'update-doctor' ),
				__( "Update Doctor called WP_Upgrader::create_lock('auto_updater') directly and it returned false — the same call that gates the per-item loop in WP_Automatic_Updater::run(). This reproduces the stall in isolation, so it is not a one-off timing collision. The details below show why: if the raw INSERT IGNORE write test errored, something is rejecting the lock write (a query filter from a security/host plugin, or a database write restriction). If the write test is ok yet create_lock still fails, a lock row is present at the instant of the call even though get_option reports none — inspect the cache values and SQL error above.", 'update-doctor' ),
				$details
			);
		}

		return Update_Doctor_Diagnostic::pass(
			__( 'create_lock() probe: the updater can acquire its lock', 'update-doctor' ),
			__( "WP_Upgrader::create_lock('auto_updater') succeeded on demand and was released immediately. If the live update test still bails at the lock, the cause is transient contention — another process holding the lock at the exact moment run() fires — rather than a persistently stuck lock.", 'update-doctor' ),
			$details
		);
	}

	/**
	 * List callbacks on the `query` filter. A plugin that hooks it can modify or block
	 * any SQL statement, which is one way create_lock()'s INSERT could fail with no
	 * visible lock row.
	 *
	 * @return Update_Doctor_Diagnostic
	 */
	private function inspect_query_filter() {
		$inspector = new Update_Doctor_Hook_Inspector();
		$callbacks = $inspector->inspect( 'query' );

		if ( empty( $callbacks ) ) {
			return Update_Doctor_Diagnostic::pass(
				__( 'Database query filter', 'update-doctor' ),
				__( 'No callbacks are registered on the `query` filter, so nothing is rewriting or blocking SQL statements (including the lock INSERT).', 'update-doctor' )
			);
		}

		$lines = array();
		foreach ( $callbacks as $cb ) {
			$location = $cb['file'] ? $cb['file'] . ( $cb['line'] ? ':' . $cb['line'] : '' ) : '';
			$lines[]  = sprintf( 'priority %d — %s%s', $cb['priority'], $cb['callback_label'], $location ? ' (' . $location . ')' : '' );
		}

		return Update_Doctor_Diagnostic::warn(
			__( 'Database query filter has callbacks', 'update-doctor' ),
			sprintf(
				_n(
					'%d callback is registered on the `query` filter. Code here can rewrite or reject any SQL statement, including create_lock()\'s INSERT into wp_options — if the lock write is failing, inspect this callback.',
					'%d callbacks are registered on the `query` filter. Code here can rewrite or reject any SQL statement, including create_lock()\'s INSERT into wp_options — if the lock write is failing, inspect these callbacks.',
					count( $callbacks ),
					'update-doctor'
				),
				count( $callbacks )
			),
			$lines
		);
	}
}

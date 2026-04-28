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
		// If older than ~1 hour, it's almost certainly stale and blocking new runs.
		$lock = get_option( 'auto_updater.lock' );
		if ( ! $lock ) {
			$results[] = Update_Doctor_Diagnostic::pass(
				'auto_updater.lock',
				__( 'No active auto-update lock. New updates can begin.', 'update-doctor' )
			);
		} else {
			$age = $now - (int) $lock;
			if ( $age > HOUR_IN_SECONDS ) {
				$results[] = Update_Doctor_Diagnostic::fail(
					'auto_updater.lock',
					sprintf(
						__( 'Lock has been held for %s. This is almost certainly stale and is preventing new auto-update runs. Delete the option `auto_updater.lock` from wp_options.', 'update-doctor' ),
						human_time_diff( (int) $lock, $now )
					)
				);
			} else {
				$results[] = Update_Doctor_Diagnostic::info(
					'auto_updater.lock',
					sprintf( __( 'An auto-update is currently running (lock age: %s).', 'update-doctor' ), human_time_diff( (int) $lock, $now ) )
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

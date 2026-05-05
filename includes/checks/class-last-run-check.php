<?php
/**
 * Surfaces the results of the most recent update attempt — manual or automatic.
 *
 * If the user has clicked "Run Background Update Now," or if WordPress's
 * automatic_updates_complete action has fired since this plugin was installed,
 * Update Doctor stores the results, output, and any captured PHP errors in
 * a transient. This check reads that transient and reports prominently.
 *
 * Fatal errors captured during an update attempt are hoisted to FAIL status so
 * they appear at the top of any aggregate status banner.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Last_Run_Check extends Update_Doctor_Check {

	const TRANSIENT = 'update_doctor_last_run';

	public function id() {
		return 'last_run';
	}

	public function label() {
		return __( 'Last Update Attempt', 'update-doctor' );
	}

	public function description() {
		return __( 'Reports the result of the most recent update attempt — whether triggered manually via the "Run Background Update Now" button or automatically by WordPress.', 'update-doctor' );
	}

	public function run() {
		$payload = get_transient( self::TRANSIENT );

		if ( ! is_array( $payload ) ) {
			return $this->no_run_yet();
		}

		$results  = array();
		$age      = time() - (int) ( isset( $payload['time'] ) ? $payload['time'] : 0 );
		$kind     = isset( $payload['kind'] ) ? (string) $payload['kind'] : 'manual';
		$output   = isset( $payload['output'] ) ? (string) $payload['output'] : '';
		$run_data = isset( $payload['results'] ) ? $payload['results'] : array();
		$errors   = isset( $payload['errors'] ) && is_array( $payload['errors'] ) ? $payload['errors'] : array();

		$header_details = array(
			sprintf( __( 'kind: %s', 'update-doctor' ), 'manual' === $kind ? __( 'manual trigger via Update Doctor', 'update-doctor' ) : __( 'automatic update run', 'update-doctor' ) ),
			sprintf( __( 'age: %s ago', 'update-doctor' ), human_time_diff( time() - $age, time() ) ),
		);

		// Hoist fatal/error-severity PHP errors to a top-level FAIL.
		$fatals = $this->extract_fatals( $errors );
		if ( ! empty( $fatals ) ) {
			$results[] = Update_Doctor_Diagnostic::fail(
				__( 'Fatal errors during update attempt', 'update-doctor' ),
				__( "PHP fatal or error-severity messages were captured while WordPress was attempting an update. These are likely the immediate cause of the update failing — investigate the file and line listed for each.", 'update-doctor' ),
				array_merge( $header_details, $fatals )
			);
		}

		// Summary of what the upgrader returned.
		$summary       = $this->summarise_results( $run_data );
		$has_failures  = ! empty( $summary['failures'] );
		$breadcrumbs   = isset( $payload['breadcrumbs'] ) && is_array( $payload['breadcrumbs'] ) ? $payload['breadcrumbs'] : array();
		$pending_before = isset( $payload['pending_before'] ) && is_array( $payload['pending_before'] ) ? $payload['pending_before'] : null;

		if ( $has_failures ) {
			$results[] = Update_Doctor_Diagnostic::warn(
				__( 'Updater reported failures', 'update-doctor' ),
				sprintf(
					__( '%d updates attempted, %d failed. Each failed entry is listed below with WordPress\'s reason.', 'update-doctor' ),
					$summary['attempted'],
					count( $summary['failures'] )
				),
				array_merge( $header_details, $summary['failures'] )
			);
		} elseif ( $summary['attempted'] > 0 ) {
			$results[] = Update_Doctor_Diagnostic::pass(
				__( 'Updater completed successfully', 'update-doctor' ),
				sprintf(
					_n( '%d update attempted with no failures.', '%d updates attempted with no failures.', $summary['attempted'], 'update-doctor' ),
					$summary['attempted']
				),
				array_merge( $header_details, $summary['successes'] )
			);
		} elseif ( empty( $fatals ) ) {
			$results[] = $this->diagnose_zero_attempts( $breadcrumbs, $pending_before, $header_details );
		}

		// Always surface the lifecycle breadcrumbs after the headline diagnostic so
		// the user can see exactly which hooks fired during the run.
		if ( ! empty( $breadcrumbs ) ) {
			$results[] = $this->breadcrumbs_diagnostic( $breadcrumbs );
		}

		// Non-fatal warnings/notices captured.
		$non_fatals = $this->extract_non_fatals( $errors );
		if ( ! empty( $non_fatals ) ) {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'PHP notices/warnings during update attempt', 'update-doctor' ),
				sprintf( __( '%d non-fatal messages were captured. Usually informational, but worth a glance if updates are misbehaving.', 'update-doctor' ), count( $non_fatals ) ),
				$non_fatals
			);
		}

		// Captured stdout/stderr from the update process, if anything was emitted.
		if ( '' !== trim( $output ) ) {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'Captured output', 'update-doctor' ),
				__( 'Standard output emitted while the update ran. Often empty; useful when something went wrong.', 'update-doctor' ),
				array( substr( $output, 0, 4000 ) . ( strlen( $output ) > 4000 ? '…[truncated]' : '' ) )
			);
		}

		return $results;
	}

	/**
	 * Distinguish between "run() never started," "ran but should_update returned
	 * false for everything," and "ran, called the upgrader, but the upgrader
	 * aborted before completing." The lifecycle breadcrumbs make this knowable.
	 */
	private function diagnose_zero_attempts( array $breadcrumbs, $pending_before, array $header_details ) {
		$pending_count = $pending_before ? ( (int) $pending_before['plugins'] + (int) $pending_before['themes'] + ( ! empty( $pending_before['core'] ) ? 1 : 0 ) ) : 0;

		// If no updates were pending, "nothing to do" is the right answer.
		if ( $pending_count === 0 ) {
			return Update_Doctor_Diagnostic::info(
				__( 'Updater ran with nothing to do', 'update-doctor' ),
				__( 'The most recent run completed without applying any updates, and there were no pending updates to attempt. This is the normal state for a fully up-to-date site.', 'update-doctor' ),
				$header_details
			);
		}

		// Did is_disabled() get called? It's called at the very top of run(), so
		// if we never saw the filter fire, run() probably exited even earlier
		// (or the filter wasn't reached because is_disabled returned true based
		// on a constant rather than the filter chain).
		$disabled_calls = isset( $breadcrumbs['is_disabled_filter_calls'] ) ? (int) $breadcrumbs['is_disabled_filter_calls'] : 0;
		$pre_auto       = isset( $breadcrumbs['pre_auto_update'] ) && is_array( $breadcrumbs['pre_auto_update'] ) ? $breadcrumbs['pre_auto_update'] : array();
		$pre_install    = isset( $breadcrumbs['upgrader_pre_install'] ) && is_array( $breadcrumbs['upgrader_pre_install'] ) ? $breadcrumbs['upgrader_pre_install'] : array();

		$details = array_merge( $header_details, array(
			sprintf( __( 'pending updates at trigger time: %d', 'update-doctor' ), $pending_count ),
			sprintf( __( 'automatic_updater_disabled filter called: %d times', 'update-doctor' ), $disabled_calls ),
			sprintf( __( 'pre_auto_update fired: %d times', 'update-doctor' ), count( $pre_auto ) ),
			sprintf( __( 'upgrader_pre_install fired: %d times', 'update-doctor' ), count( $pre_install ) ),
		) );

		// Three possible signatures:
		// (a) is_disabled filter never called → run() didn't reach is_disabled, suggesting an even earlier exit (rare; could be require_once failure).
		// (b) is_disabled filter called but pre_auto_update never fired → run() exited between is_disabled and the iteration loop. Most likely is_main_network/is_main_site or lock acquisition.
		// (c) pre_auto_update fired but upgrader_pre_install never fired → should_update returned false at runtime. The auto_update_$type filters are returning differently in run() context than in our per-item check.
		// (d) upgrader_pre_install fired but no items in results → upgrader_pre_install or upgrader_pre_download returned WP_Error, aborting the install. The errors arrays in breadcrumbs would show this.
		if ( 0 === $disabled_calls ) {
			return Update_Doctor_Diagnostic::fail(
				__( 'Updater never reached is_disabled() check', 'update-doctor' ),
				sprintf(
					__( '%d updates were pending, but Update Doctor did not observe WP_Automatic_Updater::is_disabled() being called during the run. This is unusual and suggests an earlier exit from wp_maybe_auto_update() — possibly a require_once failure or a custom override of the function.', 'update-doctor' ),
					$pending_count
				),
				$details
			);
		}

		if ( empty( $pre_auto ) ) {
			return Update_Doctor_Diagnostic::fail(
				__( 'Updater ran but never iterated pending items', 'update-doctor' ),
				sprintf(
					__( '%d updates were pending and is_disabled() was reached, but the per-item iteration never began. The most likely causes are: (a) WP_Upgrader::create_lock() failed (a stale auto_updater.lock option, or another process holding the lock), (b) is_main_network() or is_main_site() returned false on a multisite install, or (c) the update_plugins / update_themes transient was empty at the moment the updater read it.', 'update-doctor' ),
					$pending_count
				),
				$details
			);
		}

		if ( empty( $pre_install ) ) {
			return Update_Doctor_Diagnostic::fail(
				__( 'Updater iterated items but should_update returned false at runtime', 'update-doctor' ),
				sprintf(
					__( 'WordPress reached %d items during the iteration but the upgrader was never invoked for any of them. This means WP_Automatic_Updater::should_update() returned false at runtime — different from what Update Doctor\'s Per-Item check observed. Check the auto_update_plugin and auto_update_theme filter callbacks listed in the Filters and Hooks section above; one of them is likely behaving differently inside run() than in admin context (for example, checking is_admin() or the current_user_can() result).', 'update-doctor' ),
					count( $pre_auto )
				),
				array_merge( $details, array_map( function ( $item ) {
					return 'iterated: ' . $item['type'] . ' ' . $item['name'];
				}, $pre_auto ) )
			);
		}

		// We have pre_install fires but no completion entries → the upgrader started but aborted.
		$pre_install_errors = isset( $breadcrumbs['upgrader_pre_install_errors'] ) && is_array( $breadcrumbs['upgrader_pre_install_errors'] ) ? $breadcrumbs['upgrader_pre_install_errors'] : array();
		$pre_download_errors = isset( $breadcrumbs['upgrader_pre_download_errors'] ) && is_array( $breadcrumbs['upgrader_pre_download_errors'] ) ? $breadcrumbs['upgrader_pre_download_errors'] : array();
		$abort_lines = array_merge( $pre_install_errors, $pre_download_errors );

		return Update_Doctor_Diagnostic::fail(
			__( 'Upgrader started but did not complete', 'update-doctor' ),
			sprintf(
				__( 'WP_Automatic_Updater began upgrading %d item(s) but no entries appeared in the completion results. Inspect the abortable upgrader hooks in the Upgrader Hooks section — Ithemes_Updater_Admin->filter_upgrader_pre_install, WC_Helper_Updater::block_expired_updates, or one of the plugin-update-checker library callbacks may be returning a WP_Error.', 'update-doctor' ),
				count( $pre_install )
			),
			empty( $abort_lines ) ? $details : array_merge( $details, array( 'abort sources:' ), $abort_lines )
		);
	}

	private function breadcrumbs_diagnostic( array $breadcrumbs ) {
		$lines = array();
		$lines[] = sprintf( 'automatic_updater_disabled filter calls: %d (last result: %s)',
			(int) ( $breadcrumbs['is_disabled_filter_calls'] ?? 0 ),
			isset( $breadcrumbs['is_disabled_filter_last'] ) ? var_export( $breadcrumbs['is_disabled_filter_last'], true ) : 'n/a'
		);
		$lines[] = sprintf( 'pre_auto_update fires: %d', count( (array) ( $breadcrumbs['pre_auto_update'] ?? array() ) ) );
		$lines[] = sprintf( 'upgrader_pre_install fires: %d', count( (array) ( $breadcrumbs['upgrader_pre_install'] ?? array() ) ) );
		$lines[] = sprintf( 'upgrader_pre_download fires: %d', count( (array) ( $breadcrumbs['upgrader_pre_download'] ?? array() ) ) );
		$lines[] = sprintf( 'upgrader_post_install fires: %d', count( (array) ( $breadcrumbs['upgrader_post_install'] ?? array() ) ) );

		$pre_install_errors = (array) ( $breadcrumbs['upgrader_pre_install_errors'] ?? array() );
		if ( ! empty( $pre_install_errors ) ) {
			$lines[] = '— upgrader_pre_install returned WP_Error for:';
			foreach ( $pre_install_errors as $err ) {
				$lines[] = '   • ' . $err;
			}
		}

		$pre_download_errors = (array) ( $breadcrumbs['upgrader_pre_download_errors'] ?? array() );
		if ( ! empty( $pre_download_errors ) ) {
			$lines[] = '— upgrader_pre_download returned WP_Error for:';
			foreach ( $pre_download_errors as $err ) {
				$lines[] = '   • ' . $err;
			}
		}

		return Update_Doctor_Diagnostic::info(
			__( 'Lifecycle breadcrumbs', 'update-doctor' ),
			__( "Each hook fired during WordPress's auto-update process is counted below. These breadcrumbs are how Update Doctor reasons about exactly where the run got to before stopping.", 'update-doctor' ),
			$lines
		);
	}

	private function no_run_yet() {
		$pending = $this->pending_count();

		if ( $pending > 0 ) {
			return array(
				Update_Doctor_Diagnostic::warn(
					__( 'No recent update attempt captured', 'update-doctor' ),
					sprintf(
						__( '%d updates are pending and Update Doctor has no record of a recent update run on this site. Click "Run Background Update Now" above to trigger a live attempt and capture the results — this is the best way to diagnose why pending updates are not applying.', 'update-doctor' ),
						$pending
					)
				),
			);
		}

		return array(
			Update_Doctor_Diagnostic::info(
				__( 'No recent update attempt captured', 'update-doctor' ),
				__( 'Update Doctor has not seen an update run on this site yet. There are also no pending updates, so this is the expected state.', 'update-doctor' )
			),
		);
	}

	private function pending_count() {
		$count = 0;

		$pt = get_site_transient( 'update_plugins' );
		if ( $pt && isset( $pt->response ) && is_array( $pt->response ) ) {
			$count += count( $pt->response );
		}

		$tt = get_site_transient( 'update_themes' );
		if ( $tt && isset( $tt->response ) && is_array( $tt->response ) ) {
			$count += count( $tt->response );
		}

		$ct = get_site_transient( 'update_core' );
		if ( $ct && isset( $ct->updates ) && is_array( $ct->updates ) ) {
			foreach ( $ct->updates as $update ) {
				if ( isset( $update->response ) && in_array( $update->response, array( 'upgrade', 'autoupdate' ), true ) ) {
					$count++;
				}
			}
		}

		return $count;
	}

	private function extract_fatals( array $errors ) {
		$fatal_severities = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		$out = array();

		foreach ( $errors as $err ) {
			$is_fatal = false;
			if ( isset( $err['severity'] ) ) {
				if ( 'exception' === $err['severity'] ) {
					$is_fatal = true;
				} elseif ( is_int( $err['severity'] ) && in_array( $err['severity'], $fatal_severities, true ) ) {
					$is_fatal = true;
				}
			}
			if ( $is_fatal ) {
				$out[] = sprintf(
					'%s in %s:%d — %s',
					$this->severity_label( $err['severity'] ),
					isset( $err['file'] ) ? $err['file'] : '?',
					isset( $err['line'] ) ? (int) $err['line'] : 0,
					isset( $err['message'] ) ? $err['message'] : ''
				);
			}
		}
		return $out;
	}

	private function extract_non_fatals( array $errors ) {
		$fatal_severities = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
		$out = array();
		foreach ( $errors as $err ) {
			if ( ! isset( $err['severity'] ) ) {
				continue;
			}
			if ( 'exception' === $err['severity'] ) {
				continue;
			}
			if ( is_int( $err['severity'] ) && in_array( $err['severity'], $fatal_severities, true ) ) {
				continue;
			}
			$out[] = sprintf(
				'%s in %s:%d — %s',
				$this->severity_label( $err['severity'] ),
				isset( $err['file'] ) ? $err['file'] : '?',
				isset( $err['line'] ) ? (int) $err['line'] : 0,
				isset( $err['message'] ) ? $err['message'] : ''
			);
		}
		return $out;
	}

	private function severity_label( $severity ) {
		if ( 'exception' === $severity ) {
			return 'Exception';
		}
		switch ( (int) $severity ) {
			case E_ERROR:           return 'Fatal Error';
			case E_PARSE:           return 'Parse Error';
			case E_CORE_ERROR:      return 'Core Error';
			case E_COMPILE_ERROR:   return 'Compile Error';
			case E_USER_ERROR:      return 'User Error';
			case E_RECOVERABLE_ERROR: return 'Recoverable Error';
			case E_WARNING:         return 'Warning';
			case E_NOTICE:          return 'Notice';
			case E_DEPRECATED:      return 'Deprecated';
			case E_USER_WARNING:    return 'User Warning';
			case E_USER_NOTICE:     return 'User Notice';
			case E_USER_DEPRECATED: return 'User Deprecated';
			case E_STRICT:          return 'Strict';
			default:                return 'Unknown (' . (int) $severity . ')';
		}
	}

	/**
	 * @return array{attempted:int, successes:string[], failures:string[]}
	 */
	private function summarise_results( $run_data ) {
		$attempted = 0;
		$successes = array();
		$failures  = array();

		if ( ! is_array( $run_data ) ) {
			return compact( 'attempted', 'successes', 'failures' );
		}

		foreach ( $run_data as $type => $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				$attempted++;
				$name = $this->item_name( $type, $entry );

				if ( isset( $entry->result ) ) {
					if ( true === $entry->result ) {
						$successes[] = sprintf( '[%s] %s — succeeded', $type, $name );
					} elseif ( is_wp_error( $entry->result ) ) {
						$failures[] = sprintf( '[%s] %s — %s', $type, $name, $entry->result->get_error_message() );
					} else {
						$failures[] = sprintf( '[%s] %s — failed (no error message)', $type, $name );
					}
				}
			}
		}

		return compact( 'attempted', 'successes', 'failures' );
	}

	private function item_name( $type, $entry ) {
		if ( ! isset( $entry->item ) ) {
			return '?';
		}
		if ( 'plugin' === $type && isset( $entry->item->plugin ) ) {
			return $entry->item->plugin;
		}
		if ( 'theme' === $type && isset( $entry->item->theme ) ) {
			return $entry->item->theme;
		}
		if ( isset( $entry->item->slug ) ) {
			return $entry->item->slug;
		}
		return '?';
	}
}

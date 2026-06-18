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

		// Filter-level invocations (one per item iterated by run(), regardless of should_update outcome).
		$plugin_filter_invocations = isset( $breadcrumbs['auto_update_plugin_invocations'] ) && is_array( $breadcrumbs['auto_update_plugin_invocations'] ) ? $breadcrumbs['auto_update_plugin_invocations'] : array();
		$theme_filter_invocations  = isset( $breadcrumbs['auto_update_theme_invocations'] ) && is_array( $breadcrumbs['auto_update_theme_invocations'] ) ? $breadcrumbs['auto_update_theme_invocations'] : array();
		$core_filter_invocations   = isset( $breadcrumbs['auto_update_core_invocations'] ) && is_array( $breadcrumbs['auto_update_core_invocations'] ) ? $breadcrumbs['auto_update_core_invocations'] : array();
		$filter_invocation_total   = count( $plugin_filter_invocations ) + count( $theme_filter_invocations ) + count( $core_filter_invocations );

		$is_multisite    = ! empty( $breadcrumbs['is_multisite'] );
		$is_main_network = isset( $breadcrumbs['is_main_network'] ) ? (bool) $breadcrumbs['is_main_network'] : true;
		$is_main_site    = isset( $breadcrumbs['is_main_site'] ) ? (bool) $breadcrumbs['is_main_site'] : true;

		$details = array_merge( $header_details, array(
			sprintf( __( 'pending updates at trigger time: %d', 'update-doctor' ), $pending_count ),
			sprintf( __( 'automatic_updater_disabled filter called: %d times', 'update-doctor' ), $disabled_calls ),
			sprintf( __( 'auto_update_$type filter invocations (one per iterated item): %d', 'update-doctor' ), $filter_invocation_total ),
			sprintf( __( 'pre_auto_update fired: %d times', 'update-doctor' ), count( $pre_auto ) ),
			sprintf( __( 'upgrader_pre_install fired: %d times', 'update-doctor' ), count( $pre_install ) ),
		) );

		if ( $is_multisite ) {
			$details[] = sprintf( __( 'is_main_network: %s', 'update-doctor' ), $is_main_network ? 'true' : 'false' );
			$details[] = sprintf( __( 'is_main_site: %s', 'update-doctor' ), $is_main_site ? 'true' : 'false' );
		}

		// Five possible signatures, distinguished by the new filter-invocation counter:
		// (a) is_disabled filter never called → run() didn't reach is_disabled.
		// (b) is_disabled called but filter_invocation_total == 0 → run() exited between is_disabled and the iteration loop. Lock failure, multisite mismatch, or transient empty.
		// (c) filter_invocation_total > 0 but pre_auto_update count == 0 → iteration ran, should_update returned false for every item.
		// (d) pre_auto_update > 0 but pre_install == 0 → unusual; should_update cleared but upgrader bailed before pre_install (probably filesystem init).
		// (e) pre_install > 0 but no results → upgrader started and aborted mid-process.

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

		if ( 0 === $filter_invocation_total ) {
			// Iteration loop never executed. Narrow down which of the four causes
			// using the new transient-read breadcrumbs and lock snapshots.
			if ( $is_multisite && ( ! $is_main_network || ! $is_main_site ) ) {
				return Update_Doctor_Diagnostic::fail(
					__( 'Updater exited because of multisite context', 'update-doctor' ),
					sprintf(
						__( 'WP_Automatic_Updater::run() exits early when is_main_network() or is_main_site() returns false. Both must be true for the updater to proceed. On this site: is_main_network=%s, is_main_site=%s.', 'update-doctor' ),
						$is_main_network ? 'true' : 'false',
						$is_main_site ? 'true' : 'false'
					),
					$details
				);
			}

			$transient_reads     = isset( $breadcrumbs['transient_reads'] ) && is_array( $breadcrumbs['transient_reads'] ) ? $breadcrumbs['transient_reads'] : array();
			$pre_transient_reads = isset( $breadcrumbs['pre_transient_reads'] ) && is_array( $breadcrumbs['pre_transient_reads'] ) ? $breadcrumbs['pre_transient_reads'] : array();
			$snapshot            = isset( $breadcrumbs['pre_run_transient_snapshot'] ) && is_array( $breadcrumbs['pre_run_transient_snapshot'] ) ? $breadcrumbs['pre_run_transient_snapshot'] : null;
			$pre_lock            = isset( $breadcrumbs['pre_run_lock_held'] ) ? (bool) $breadcrumbs['pre_run_lock_held'] : null;
			$post_lock           = isset( $breadcrumbs['post_run_lock_held'] ) ? (bool) $breadcrumbs['post_run_lock_held'] : null;

			// Case: lock was already held when we started.
			if ( true === $pre_lock ) {
				return Update_Doctor_Diagnostic::fail(
					__( 'Updater exited because the auto_updater.lock was already held', 'update-doctor' ),
					__( 'The auto_updater.lock option was set in the database immediately before this trigger called wp_maybe_auto_update(). WP_Upgrader::create_lock() would have returned false, causing run() to exit before reaching the iteration loop. The lock may be stale (held by a previous run that crashed before releasing) — if it persists for more than an hour, manually delete the auto_updater.lock option from wp_options.', 'update-doctor' ),
					$details
				);
			}

			// Case: our pre-run snapshot saw items, but run()'s read returned empty.
			// This is the smoking gun for read-side transient interception.
			if ( $snapshot && $snapshot['plugins_response_count'] > 0 ) {
				$run_saw_items = false;
				foreach ( $transient_reads as $read ) {
					if ( $read['transient'] === 'update_plugins' && $read['response_count'] > 0 ) {
						$run_saw_items = true;
						break;
					}
				}

				if ( ! $run_saw_items ) {
					$reads_lines = array();
					foreach ( $transient_reads as $read ) {
						$reads_lines[] = sprintf( 'site_transient_%s: response_count=%d, has_response=%s',
							$read['transient'],
							$read['response_count'],
							$read['has_response'] ? 'true' : 'false'
						);
					}
					$pre_reads_lines = array();
					foreach ( $pre_transient_reads as $read ) {
						$pre_reads_lines[] = sprintf( 'pre_site_transient_%s: short_circuited=%s',
							$read['transient'],
							$read['short_circuited'] ? 'true' : 'false'
						);
					}

					return Update_Doctor_Diagnostic::fail(
						__( 'Transient read interception detected', 'update-doctor' ),
						sprintf(
							__( 'Smoking gun: Update Doctor read update_plugins immediately before invoking the updater and observed %d pending items. During run(), the site_transient_update_plugins read filter chain returned a stripped value with 0 items — so run()\'s foreach loop never executed. A callback on site_transient_update_plugins or pre_site_transient_update_plugins is rewriting the response when the transient is read in this context. Inspect the callbacks listed in the Filters and Hooks section for those filters; one of them is the culprit. This is consistent with a managed-host mu-plugin that adjusts visibility of pending updates based on request context (web-admin vs cron/CLI).', 'update-doctor' ),
							$snapshot['plugins_response_count']
						),
						array_merge( $details, array(
							sprintf( 'pre-run transient snapshot: plugins=%d items, themes=%d items, core=%s', $snapshot['plugins_response_count'], $snapshot['themes_response_count'], $snapshot['core_has_response'] ? 'pending' : 'none' ),
							'— site_transient_$type reads during run():',
						), array_map( static function ( $line ) { return '   ' . $line; }, $reads_lines ), array( '— pre_site_transient_$type reads during run():' ), array_map( static function ( $line ) { return '   ' . $line; }, $pre_reads_lines ) )
					);
				}

				// run() saw the items (read returned > 0) but still iterated none of
				// them — should_update() returned false BEFORE the auto_update filter.
				// In WP core that means the pre-filter gate in should_update() bailed:
				// either request_filesystem_credentials() failed (strict ownership) or
				// is_vcs_checkout() was true. The Unattended Update Gate check pinpoints
				// which one.
				return Update_Doctor_Diagnostic::fail(
					__( 'Updates were skipped before the auto_update filter (pre-filter gate)', 'update-doctor' ),
					sprintf(
						__( 'Update Doctor saw %1$d pending plugins and the updater read the same %1$d during run() — so the transient is fine and read interception is NOT the cause. But the auto_update_plugin filter fired 0 times, which means WP_Automatic_Updater::should_update() returned false for every item BEFORE reaching that filter. In WP core that happens at exactly one place: the gate that requires filesystem access (request_filesystem_credentials) and rejects VCS checkouts (is_vcs_checkout). Every plugin is skipped regardless of its opt-in setting. The "Unattended Update Gate" section above calls both of those WordPress functions directly and reports which one is failing. Note: if FS_METHOD is defined as "direct" (common on managed hosts), the filesystem branch cannot be the cause and the culprit is a VCS checkout — a stray .git/.svn/.hg/.bzr directory in the plugin or content tree.', 'update-doctor' ),
						$snapshot['plugins_response_count']
					),
					$details
				);
			}

			// Case: our snapshot also saw an empty transient. Genuine empty state.
			if ( $snapshot && 0 === $snapshot['plugins_response_count'] && 0 === $snapshot['themes_response_count'] ) {
				return Update_Doctor_Diagnostic::warn(
					__( 'Updater read the transient and found it empty', 'update-doctor' ),
					__( 'The update_plugins and update_themes transients did not contain any pending items at the moment the updater read them. This is consistent with the transient being mid-refresh or having been cleared by another process. Click "Refresh Updates" from Dashboard → Updates and re-run the live test.', 'update-doctor' ),
					$details
				);
			}

			// Case: post-run lock held while pre-run was empty — race / weird state.
			if ( true === $post_lock && false === $pre_lock ) {
				return Update_Doctor_Diagnostic::warn(
					__( 'auto_updater.lock was set during the run and not released', 'update-doctor' ),
					__( 'The lock was acquired by run() but not released. This usually indicates an exception during the iteration loop. If the lock persists beyond an hour, it will block all subsequent auto-update runs until manually cleared.', 'update-doctor' ),
					$details
				);
			}

			// Fallback: lock not held, snapshot inconclusive, no clear signature.
			return Update_Doctor_Diagnostic::fail(
				__( 'Updater ran but never began per-item iteration', 'update-doctor' ),
				sprintf(
					__( '%d updates were pending and is_disabled() returned false, but the foreach loop over $plugins->response never executed. The read-side breadcrumbs above show what each transient filter returned during run(). If site_transient_update_plugins shows response_count=0, the read filter chain is the cause; investigate the callbacks listed in the Filters and Hooks section for that filter.', 'update-doctor' ),
					$pending_count
				),
				$details
			);
		}

		if ( empty( $pre_auto ) ) {
			// Filter chain ran but should_update returned false for every item. This is the
			// most informative case — we know exactly which items were considered and what the
			// auto_update_$type filter chain decided for each.
			$per_item_lines = array();
			foreach ( $plugin_filter_invocations as $inv ) {
				$per_item_lines[] = sprintf( 'plugin %s → auto_update_plugin returned %s', $inv['name'] ?: '?', $inv['value'] );
			}
			foreach ( $theme_filter_invocations as $inv ) {
				$per_item_lines[] = sprintf( 'theme %s → auto_update_theme returned %s', $inv['name'] ?: '?', $inv['value'] );
			}
			foreach ( $core_filter_invocations as $inv ) {
				$per_item_lines[] = sprintf( 'core → auto_update_core returned %s', $inv['value'] );
			}

			return Update_Doctor_Diagnostic::fail(
				__( 'Updater iterated items but should_update returned false at runtime', 'update-doctor' ),
				sprintf(
					__( 'WordPress reached %d items during iteration but the upgrader was never invoked for any of them. The auto_update_$type filter chain returned false for every item — different from what the Per-Item check sees in admin context. The most likely cause is a filter callback that behaves differently when wp_doing_cron() or wp_doing_ajax() return different values, or one that inspects is_admin() / current_user_can(). The per-item filter results are listed below; check the source files of the callbacks listed in the Filters and Hooks section against those plugin slugs.', 'update-doctor' ),
					$filter_invocation_total
				),
				array_merge( $details, array( '— per-item filter chain results:' ), array_map( static function ( $line ) { return '   ' . $line; }, $per_item_lines ) )
			);
		}

		if ( empty( $pre_install ) ) {
			return Update_Doctor_Diagnostic::fail(
				__( 'Updater cleared should_update but upgrader bailed before installing', 'update-doctor' ),
				sprintf(
					__( '%d items passed should_update() and pre_auto_update fired for them, but upgrader_pre_install never fired. This is unusual; the most likely cause is a WP_Filesystem initialization failure inside Plugin_Upgrader::upgrade().', 'update-doctor' ),
					count( $pre_auto )
				),
				array_merge( $details, array_map( function ( $item ) {
					return 'cleared: ' . $item['type'] . ' ' . $item['name'];
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
		$lines[] = sprintf( 'auto_update_plugin invocations: %d', count( (array) ( $breadcrumbs['auto_update_plugin_invocations'] ?? array() ) ) );
		$lines[] = sprintf( 'auto_update_theme invocations: %d', count( (array) ( $breadcrumbs['auto_update_theme_invocations'] ?? array() ) ) );
		$lines[] = sprintf( 'auto_update_core invocations: %d', count( (array) ( $breadcrumbs['auto_update_core_invocations'] ?? array() ) ) );
		$lines[] = sprintf( 'pre_auto_update fires: %d', count( (array) ( $breadcrumbs['pre_auto_update'] ?? array() ) ) );
		$lines[] = sprintf( 'upgrader_pre_install fires: %d', count( (array) ( $breadcrumbs['upgrader_pre_install'] ?? array() ) ) );
		$lines[] = sprintf( 'upgrader_pre_download fires: %d', count( (array) ( $breadcrumbs['upgrader_pre_download'] ?? array() ) ) );
		$lines[] = sprintf( 'upgrader_post_install fires: %d', count( (array) ( $breadcrumbs['upgrader_post_install'] ?? array() ) ) );
		if ( ! empty( $breadcrumbs['is_multisite'] ) ) {
			$lines[] = sprintf( 'is_main_network: %s, is_main_site: %s',
				isset( $breadcrumbs['is_main_network'] ) ? var_export( (bool) $breadcrumbs['is_main_network'], true ) : 'n/a',
				isset( $breadcrumbs['is_main_site'] ) ? var_export( (bool) $breadcrumbs['is_main_site'], true ) : 'n/a'
			);
		}

		$snapshot = isset( $breadcrumbs['pre_run_transient_snapshot'] ) && is_array( $breadcrumbs['pre_run_transient_snapshot'] ) ? $breadcrumbs['pre_run_transient_snapshot'] : null;
		if ( $snapshot ) {
			$lines[] = sprintf( 'pre-run transient snapshot (our read): plugins=%d, themes=%d, core=%s',
				(int) ( $snapshot['plugins_response_count'] ?? 0 ),
				(int) ( $snapshot['themes_response_count'] ?? 0 ),
				! empty( $snapshot['core_has_response'] ) ? 'pending' : 'none'
			);
		}

		$transient_reads = (array) ( $breadcrumbs['transient_reads'] ?? array() );
		if ( ! empty( $transient_reads ) ) {
			$lines[] = '— site_transient_$type reads during run():';
			foreach ( $transient_reads as $read ) {
				$lines[] = sprintf( '   %s: response_count=%d', $read['transient'] ?? '?', (int) ( $read['response_count'] ?? 0 ) );
			}
		} else {
			$lines[] = 'site_transient_$type reads during run(): 0 (run() never reached the transient read)';
		}

		$pre_transient_reads = (array) ( $breadcrumbs['pre_transient_reads'] ?? array() );
		$short_circuits      = 0;
		foreach ( $pre_transient_reads as $read ) {
			if ( ! empty( $read['short_circuited'] ) ) {
				$short_circuits++;
			}
		}
		$lines[] = sprintf( 'pre_site_transient_$type short-circuits during run(): %d', $short_circuits );

		if ( isset( $breadcrumbs['pre_run_lock_held'] ) ) {
			$lines[] = sprintf( 'auto_updater.lock state pre-run: %s', $breadcrumbs['pre_run_lock_held'] ? 'held' : 'free' );
		}
		if ( isset( $breadcrumbs['post_run_lock_held'] ) ) {
			$lines[] = sprintf( 'auto_updater.lock state post-run: %s', $breadcrumbs['post_run_lock_held'] ? 'held' : 'free' );
		}

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

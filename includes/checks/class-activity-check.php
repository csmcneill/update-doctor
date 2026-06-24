<?php
/**
 * Displays the passively-recorded auto-update activity log, distinguishing the host's
 * scheduled runs from Update Doctor's own manual triggers. This is how we tell whether
 * the platform's update runner is actually firing — without relying on the manual test.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Activity_Check extends Update_Doctor_Check {

	const SHOW = 50;

	public function id() {
		return 'activity';
	}

	public function label() {
		return __( 'Auto-Update Activity Log', 'update-doctor' );
	}

	public function description() {
		return __( "Every real auto-update event — scheduled or manual — is recorded here as it happens. Events tagged [scheduled] come from the host's own update runner (WP-Cron or platform), not from the Run Live Update Test button. This shows whether the scheduled updater is actually running.", 'update-doctor' );
	}

	public function run() {
		$log = get_option( Update_Doctor_Activity_Recorder::OPTION, array() );
		if ( ! is_array( $log ) || empty( $log ) ) {
			return array(
				Update_Doctor_Diagnostic::info(
					__( 'No activity recorded yet', 'update-doctor' ),
					__( 'Update Doctor has not observed any auto-update activity since the recorder was installed. Scheduled runs are recorded automatically as they happen — check back after the next scheduled update window. Running the live test will also record events here (tagged [manual]).', 'update-doctor' )
				),
			);
		}

		// Summarize: did we see scheduled runs that actually reached the updater?
		$scheduled_attempts = 0;
		$scheduled_upgrades = 0;
		$scheduled_locks    = 0;
		foreach ( $log as $e ) {
			if ( ( $e['context'] ?? '' ) !== 'scheduled' ) {
				continue;
			}
			if ( 'attempt' === $e['event'] ) {
				$scheduled_attempts++;
			} elseif ( 'upgraded' === $e['event'] ) {
				$scheduled_upgrades++;
			} elseif ( 'lock_acquired' === $e['event'] || 'lock_released' === $e['event'] ) {
				$scheduled_locks++;
			}
		}

		$results = array();

		if ( $scheduled_attempts > 0 || $scheduled_upgrades > 0 ) {
			$results[] = Update_Doctor_Diagnostic::pass(
				__( 'Scheduled updater is reaching the update process', 'update-doctor' ),
				sprintf(
					__( 'The host\'s scheduled runner has reached WP_Automatic_Updater past the lock: %1$d item attempt(s) and %2$d completed upgrade(s) recorded outside the manual trigger. If specific plugins still are not updating, the cause is per-item (opt-in, license, or a per-item filter), not a global lock failure.', 'update-doctor' ),
					$scheduled_attempts,
					$scheduled_upgrades
				)
			);
		} elseif ( $scheduled_locks > 0 ) {
			$results[] = Update_Doctor_Diagnostic::warn(
				__( 'Scheduled runner touches the lock but no item attempts seen', 'update-doctor' ),
				__( 'The lock was acquired or released by a scheduled run, but no pre_auto_update (item attempt) was recorded for it — so the scheduled run is reaching the updater but not iterating any items. This points away from a stuck lock and toward the per-item should_update gate or an empty transient at run time.', 'update-doctor' )
			);
		} else {
			$results[] = Update_Doctor_Diagnostic::warn(
				__( 'No scheduled-run activity recorded', 'update-doctor' ),
				__( 'Every recorded event so far came from the manual trigger ([manual]); nothing tagged [scheduled] has appeared. If this persists across a known scheduled update window, the host\'s updater is not invoking WP_Automatic_Updater on this site at all — which would be the real problem rather than anything Update Doctor triggers by hand.', 'update-doctor' )
			);
		}

		// The recent events, newest first.
		$recent = array_slice( $log, -self::SHOW );
		$lines  = array();
		foreach ( array_reverse( $recent ) as $e ) {
			$lines[] = sprintf(
				'%s UTC [%s] %s%s',
				gmdate( 'Y-m-d H:i:s', (int) ( $e['ts'] ?? 0 ) ),
				$e['context'] ?? '?',
				$e['event'] ?? '?',
				! empty( $e['detail'] ) ? ' — ' . $e['detail'] : ''
			);
		}
		$results[] = Update_Doctor_Diagnostic::info(
			sprintf( __( 'Recent activity (last %d events)', 'update-doctor' ), count( $recent ) ),
			sprintf( __( '%d total events recorded.', 'update-doctor' ), count( $log ) ),
			$lines
		);

		return $results;
	}
}

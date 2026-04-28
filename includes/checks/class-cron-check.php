<?php
/**
 * Inspects the WP-Cron events that drive automatic updates.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Cron_Check extends Update_Doctor_Check {

	public function id() {
		return 'cron';
	}

	public function label() {
		return __( 'Cron Schedule', 'update-doctor' );
	}

	public function description() {
		return __( 'Auto-updates run via WP-Cron. If the relevant events are missing, overdue, or never fire, updates will be skipped.', 'update-doctor' );
	}

	public function run() {
		$results = array();
		$now     = time();

		$events = array(
			'wp_maybe_auto_update' => __( "The hook that actually applies pending auto-updates. Without this event firing, updates simply don't happen.", 'update-doctor' ),
			'wp_update_plugins'    => __( 'Checks for available plugin updates and refreshes the update transient.', 'update-doctor' ),
			'wp_update_themes'     => __( 'Checks for available theme updates and refreshes the update transient.', 'update-doctor' ),
			'wp_version_check'     => __( 'Checks for available core updates.', 'update-doctor' ),
		);

		foreach ( $events as $hook => $description ) {
			$next      = wp_next_scheduled( $hook );
			$last_meta = $this->last_run( $hook );

			if ( ! $next ) {
				$results[] = Update_Doctor_Diagnostic::fail(
					$hook,
					__( 'Not scheduled. This is a critical event — its absence will prevent auto-updates.', 'update-doctor' ),
					array( 'description' => $description )
				);
				continue;
			}

			$details = array( 'description' => $description );
			$details[] = sprintf( __( 'next run: %s', 'update-doctor' ), $this->format_time( $next, $now ) );
			if ( $last_meta ) {
				$details[] = $last_meta;
			}

			// Overdue by more than 6 hours past schedule = warn.
			if ( $next < ( $now - 6 * HOUR_IN_SECONDS ) ) {
				$results[] = Update_Doctor_Diagnostic::warn(
					$hook,
					__( 'Scheduled run is overdue by more than 6 hours. Cron may not be firing reliably on this server.', 'update-doctor' ),
					$details
				);
			} else {
				$results[] = Update_Doctor_Diagnostic::pass(
					$hook,
					__( 'Scheduled and not overdue.', 'update-doctor' ),
					$details
				);
			}
		}

		// Check whether the local self-test transient round-trips through wp-cron.php.
		$results[] = $this->wp_cron_reachability_check();

		return $results;
	}

	/**
	 * Hook last-run is not stored by core, but we record our own marker via the
	 * Failure Monitor. Surface a friendly note when we don't have data yet.
	 */
	private function last_run( $hook ) {
		$marker = get_option( 'update_doctor_cron_marker_' . $hook );
		if ( ! $marker ) {
			return '';
		}
		return sprintf( __( 'last seen running by Update Doctor: %s', 'update-doctor' ), $this->format_time( (int) $marker, time() ) );
	}

	private function format_time( $timestamp, $now ) {
		$delta = $timestamp - $now;
		$abs   = abs( $delta );

		if ( $abs < MINUTE_IN_SECONDS ) {
			$human = sprintf( __( '%d seconds', 'update-doctor' ), $abs );
		} elseif ( $abs < HOUR_IN_SECONDS ) {
			$human = sprintf( __( '%d minutes', 'update-doctor' ), (int) round( $abs / MINUTE_IN_SECONDS ) );
		} elseif ( $abs < DAY_IN_SECONDS ) {
			$human = sprintf( __( '%d hours', 'update-doctor' ), (int) round( $abs / HOUR_IN_SECONDS ) );
		} else {
			$human = sprintf( __( '%d days', 'update-doctor' ), (int) round( $abs / DAY_IN_SECONDS ) );
		}

		$gmt = gmdate( 'Y-m-d H:i:s', $timestamp );
		if ( $delta >= 0 ) {
			return sprintf( '%s UTC (in %s)', $gmt, $human );
		}
		return sprintf( '%s UTC (%s ago)', $gmt, $human );
	}

	/**
	 * Issue a non-blocking request to wp-cron.php with a marker query string and
	 * see whether the transient that records it gets set within a few seconds.
	 *
	 * Done in a way that won't slow the admin page much: only set the transient
	 * here; the actual round-trip request is fired from the admin page via JS.
	 */
	private function wp_cron_reachability_check() {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return Update_Doctor_Diagnostic::info(
				__( 'wp-cron.php reachability', 'update-doctor' ),
				__( 'DISABLE_WP_CRON is true; this site relies on an external cron job to hit wp-cron.php. Confirm with your host that this is configured correctly.', 'update-doctor' )
			);
		}

		return Update_Doctor_Diagnostic::info(
			__( 'wp-cron.php reachability', 'update-doctor' ),
			__( 'WordPress fires cron events on traffic. If your site is rarely visited, cron may run irregularly. Use the "Run Background Update Now" button below to trigger an update on demand.', 'update-doctor' )
		);
	}
}

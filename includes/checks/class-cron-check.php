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

	/**
	 * @var Update_Doctor_Host_Detector
	 */
	private $host_detector;

	public function __construct( Update_Doctor_Host_Detector $host_detector ) {
		$this->host_detector = $host_detector;
	}

	public function id() {
		return 'cron';
	}

	public function label() {
		return __( 'Cron Schedule', 'update-doctor' );
	}

	public function description() {
		return __( 'Auto-updates run via WP-Cron on most installations. Some managed hosts run them externally; the check below adapts to that context.', 'update-doctor' );
	}

	public function run() {
		$results = array();
		$now     = time();

		$host = $this->host_detector->detect();

		// Recurring update-check events: these *should* be persistent on every site.
		$recurring = array(
			'wp_update_plugins' => __( 'Checks for available plugin updates and refreshes the update transient.', 'update-doctor' ),
			'wp_update_themes'  => __( 'Checks for available theme updates and refreshes the update transient.', 'update-doctor' ),
			'wp_version_check'  => __( 'Checks for available core updates.', 'update-doctor' ),
		);

		foreach ( $recurring as $hook => $description ) {
			$next = wp_next_scheduled( $hook );

			if ( ! $next ) {
				$results[] = Update_Doctor_Diagnostic::fail(
					$hook,
					__( 'Not scheduled. This is a critical recurring event — its absence will prevent auto-updates.', 'update-doctor' ),
					array( 'description' => $description )
				);
				continue;
			}

			$details = array( 'description' => $description );
			$details[] = sprintf( __( 'next run: %s', 'update-doctor' ), $this->format_time( $next, $now ) );

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

		// wp_maybe_auto_update is an ad-hoc one-shot event, not a recurring one.
		// It's only scheduled when an update-check finds something to apply, and
		// it consumes itself when it runs. Its absence in the schedule is normal
		// when there are no pending updates, when an update batch just finished,
		// or when the host runs auto-updates outside of WP-Cron entirely.
		$results[] = $this->evaluate_maybe_auto_update( $host );

		// wp-cron.php reachability commentary.
		$results[] = $this->wp_cron_reachability( $host );

		// Surface managed-host detection prominently so the rest of the report is read in context.
		if ( $host['detected'] ) {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'Managed host detected', 'update-doctor' ),
				sprintf(
					__( '%s. Managed hosts often run automatic updates from their platform rather than via WP-Cron, which is why some indicators below may differ from a self-hosted site.', 'update-doctor' ),
					$this->host_detector->label( $host['host'] )
				),
				$host['evidence']
			);
		}

		return $results;
	}

	private function evaluate_maybe_auto_update( array $host ) {
		$pending = $this->pending_update_summary();
		$next    = wp_next_scheduled( 'wp_maybe_auto_update' );

		$details = array(
			'description' => __( 'WordPress schedules this event on demand when an update-check finds new versions. It is not a recurring event; its absence is only meaningful when updates are actually pending.', 'update-doctor' ),
		);

		if ( $next ) {
			$details[] = sprintf( __( 'next run: %s', 'update-doctor' ), $this->format_time( $next, time() ) );
			return Update_Doctor_Diagnostic::pass(
				'wp_maybe_auto_update',
				__( 'Scheduled. WordPress will apply pending auto-updates on the next run.', 'update-doctor' ),
				$details
			);
		}

		// Not scheduled. Decide whether that's a problem.
		if ( $pending['total'] === 0 ) {
			return Update_Doctor_Diagnostic::pass(
				'wp_maybe_auto_update',
				__( 'Not scheduled, and there are no pending updates. This is the normal state for a fully up-to-date site.', 'update-doctor' ),
				$details
			);
		}

		$details[] = sprintf(
			__( 'pending: %d plugin updates, %d theme updates, %s core update', 'update-doctor' ),
			$pending['plugins'],
			$pending['themes'],
			$pending['core'] ? __( '1', 'update-doctor' ) : __( '0', 'update-doctor' )
		);

		if ( $host['detected'] ) {
			return Update_Doctor_Diagnostic::info(
				'wp_maybe_auto_update',
				sprintf(
					__( 'Not scheduled. Pending updates exist, but %s typically runs auto-updates from outside WP-Cron, so this event is often absent on these hosts. Confirm with your host that automatic updates are running on schedule.', 'update-doctor' ),
					$this->host_detector->label( $host['host'] )
				),
				$details
			);
		}

		return Update_Doctor_Diagnostic::warn(
			'wp_maybe_auto_update',
			__( 'Not scheduled, but updates are pending. WordPress should have queued this event after the last update-check; the most common cause is a previous run that errored out and never re-queued.', 'update-doctor' ),
			$details
		);
	}

	/**
	 * @return array{plugins:int, themes:int, core:bool, total:int}
	 */
	private function pending_update_summary() {
		$plugins = 0;
		$themes  = 0;
		$core    = false;

		$pt = get_site_transient( 'update_plugins' );
		if ( $pt && isset( $pt->response ) && is_array( $pt->response ) ) {
			$plugins = count( $pt->response );
		}

		$tt = get_site_transient( 'update_themes' );
		if ( $tt && isset( $tt->response ) && is_array( $tt->response ) ) {
			$themes = count( $tt->response );
		}

		$ct = get_site_transient( 'update_core' );
		if ( $ct && isset( $ct->updates ) && is_array( $ct->updates ) ) {
			foreach ( $ct->updates as $update ) {
				if ( isset( $update->response ) && in_array( $update->response, array( 'upgrade', 'autoupdate' ), true ) ) {
					$core = true;
					break;
				}
			}
		}

		return array(
			'plugins' => $plugins,
			'themes'  => $themes,
			'core'    => $core,
			'total'   => $plugins + $themes + ( $core ? 1 : 0 ),
		);
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

	private function wp_cron_reachability( array $host ) {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return Update_Doctor_Diagnostic::info(
				__( 'wp-cron.php reachability', 'update-doctor' ),
				__( 'DISABLE_WP_CRON is true; this site relies on an external cron job to hit wp-cron.php. Confirm with your host that real cron is configured.', 'update-doctor' )
			);
		}

		if ( $host['detected'] ) {
			return Update_Doctor_Diagnostic::info(
				__( 'wp-cron.php reachability', 'update-doctor' ),
				__( 'On managed hosts, cron is typically driven by a system scheduler rather than by site traffic. WP-Cron events should fire on time regardless of visitor traffic.', 'update-doctor' )
			);
		}

		return Update_Doctor_Diagnostic::info(
			__( 'wp-cron.php reachability', 'update-doctor' ),
			__( 'WordPress fires cron events on traffic. If your site is rarely visited, cron may run irregularly. Use the "Run Background Update Now" button below to trigger an update on demand.', 'update-doctor' )
		);
	}
}

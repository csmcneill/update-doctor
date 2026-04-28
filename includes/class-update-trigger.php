<?php
/**
 * Manually triggers wp_maybe_auto_update() with output and error capture.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Update_Trigger {

	const ACTION = 'update_doctor_run_update';
	const NONCE  = 'update_doctor_run_update_nonce';

	/**
	 * Marker used by the failure monitor to skip notifications for manual runs.
	 *
	 * Public because the failure monitor reads it via the same flag.
	 *
	 * @var bool
	 */
	public static $manual_run = false;

	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to run updates.', 'update-doctor' ), 403 );
		}

		check_admin_referer( self::ACTION, self::NONCE );

		// Capture results from the auto-updater.
		$results_buffer = array();
		add_action(
			'automatic_updates_complete',
			static function ( $results ) use ( &$results_buffer ) {
				$results_buffer = $results;
			}
		);

		// Capture any PHP notices/warnings emitted during the run.
		$captured_errors = array();
		set_error_handler(
			static function ( $severity, $message, $file, $line ) use ( &$captured_errors ) {
				$captured_errors[] = compact( 'severity', 'message', 'file', 'line' );
				return false;
			}
		);

		self::$manual_run = true;

		ob_start();

		try {
			if ( ! function_exists( 'wp_maybe_auto_update' ) ) {
				require_once ABSPATH . 'wp-admin/includes/update.php';
			}
			wp_maybe_auto_update();
		} catch ( \Throwable $e ) {
			$captured_errors[] = array(
				'severity' => 'exception',
				'message'  => $e->getMessage(),
				'file'     => $e->getFile(),
				'line'     => $e->getLine(),
			);
		}

		$output = ob_get_clean();
		restore_error_handler();
		self::$manual_run = false;

		// Stash results in a transient and redirect back. Keeping it in a transient
		// keeps the URL short and avoids leaking details into the address bar.
		$payload = array(
			'time'    => time(),
			'output'  => $output,
			'results' => $results_buffer,
			'errors'  => $captured_errors,
		);

		set_transient( 'update_doctor_last_run', $payload, MINUTE_IN_SECONDS * 30 );

		$redirect = add_query_arg(
			array(
				'page'           => Update_Doctor_Admin_Page::SLUG,
				'doctor_run'     => '1',
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function last_run_payload() {
		$payload = get_transient( 'update_doctor_last_run' );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		return $payload;
	}

	public function clear_last_run() {
		delete_transient( 'update_doctor_last_run' );
	}
}

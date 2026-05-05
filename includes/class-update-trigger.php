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

		// Capture lifecycle breadcrumbs so the Last Run check can distinguish
		// "run() never started" from "ran-but-skipped-everything" from
		// "ran-and-upgrader-aborted".
		$breadcrumbs = array(
			'is_disabled_filter_calls' => 0,
			'is_disabled_filter_last'  => null,
			'pre_auto_update'          => array(),
			'upgrader_pre_install'     => array(),
			'upgrader_pre_download'    => array(),
			'upgrader_post_install'    => array(),
			'upgrader_pre_install_errors' => array(),
			'upgrader_pre_download_errors' => array(),
		);

		add_filter(
			'automatic_updater_disabled',
			static function ( $disabled ) use ( &$breadcrumbs ) {
				$breadcrumbs['is_disabled_filter_calls']++;
				$breadcrumbs['is_disabled_filter_last'] = (bool) $disabled;
				return $disabled;
			},
			PHP_INT_MAX
		);

		add_action(
			'pre_auto_update',
			static function ( $type, $item, $context ) use ( &$breadcrumbs ) {
				$breadcrumbs['pre_auto_update'][] = array(
					'type' => $type,
					'name' => self::describe_item( $type, $item ),
				);
			},
			10,
			3
		);

		add_filter(
			'upgrader_pre_install',
			static function ( $response, $hook_extra ) use ( &$breadcrumbs ) {
				$name = '';
				if ( ! empty( $hook_extra['plugin'] ) ) {
					$name = $hook_extra['plugin'];
				} elseif ( ! empty( $hook_extra['theme'] ) ) {
					$name = $hook_extra['theme'];
				}
				$breadcrumbs['upgrader_pre_install'][] = $name;
				if ( is_wp_error( $response ) ) {
					$breadcrumbs['upgrader_pre_install_errors'][] = sprintf( '%s — %s', $name, $response->get_error_message() );
				}
				return $response;
			},
			PHP_INT_MAX,
			2
		);

		add_filter(
			'upgrader_pre_download',
			static function ( $reply, $package, $upgrader, $hook_extra = array() ) use ( &$breadcrumbs ) {
				$name = '';
				if ( ! empty( $hook_extra['plugin'] ) ) {
					$name = $hook_extra['plugin'];
				} elseif ( ! empty( $hook_extra['theme'] ) ) {
					$name = $hook_extra['theme'];
				} else {
					$name = (string) $package;
				}
				$breadcrumbs['upgrader_pre_download'][] = $name;
				if ( is_wp_error( $reply ) ) {
					$breadcrumbs['upgrader_pre_download_errors'][] = sprintf( '%s — %s', $name, $reply->get_error_message() );
				}
				return $reply;
			},
			PHP_INT_MAX,
			4
		);

		add_action(
			'upgrader_post_install',
			static function ( $response, $hook_extra ) use ( &$breadcrumbs ) {
				$name = '';
				if ( ! empty( $hook_extra['plugin'] ) ) {
					$name = $hook_extra['plugin'];
				} elseif ( ! empty( $hook_extra['theme'] ) ) {
					$name = $hook_extra['theme'];
				}
				$breadcrumbs['upgrader_post_install'][] = $name;
			},
			PHP_INT_MAX,
			2
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

		// Snapshot pre-run state so the Last Run check can reason about the gap.
		$pending_before = self::pending_summary();

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
			'time'           => time(),
			'kind'           => 'manual',
			'output'         => $output,
			'results'        => $results_buffer,
			'errors'         => $captured_errors,
			'breadcrumbs'    => $breadcrumbs,
			'pending_before' => $pending_before,
		);

		set_transient( 'update_doctor_last_run', $payload, WEEK_IN_SECONDS );

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

	private static function describe_item( $type, $item ) {
		if ( ! is_object( $item ) && ! is_array( $item ) ) {
			return '?';
		}
		$item = (array) $item;
		if ( 'plugin' === $type && ! empty( $item['plugin'] ) ) {
			return $item['plugin'];
		}
		if ( 'theme' === $type && ! empty( $item['theme'] ) ) {
			return $item['theme'];
		}
		if ( ! empty( $item['slug'] ) ) {
			return $item['slug'];
		}
		return '?';
	}

	private static function pending_summary() {
		$summary = array( 'plugins' => 0, 'themes' => 0, 'core' => false );

		$pt = get_site_transient( 'update_plugins' );
		if ( $pt && isset( $pt->response ) && is_array( $pt->response ) ) {
			$summary['plugins'] = count( $pt->response );
		}
		$tt = get_site_transient( 'update_themes' );
		if ( $tt && isset( $tt->response ) && is_array( $tt->response ) ) {
			$summary['themes'] = count( $tt->response );
		}
		$ct = get_site_transient( 'update_core' );
		if ( $ct && isset( $ct->updates ) && is_array( $ct->updates ) ) {
			foreach ( $ct->updates as $update ) {
				if ( isset( $update->response ) && in_array( $update->response, array( 'upgrade', 'autoupdate' ), true ) ) {
					$summary['core'] = true;
					break;
				}
			}
		}
		return $summary;
	}
}

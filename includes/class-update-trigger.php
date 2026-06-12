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
			'is_disabled_filter_calls'      => 0,
			'is_disabled_filter_last'       => null,
			'auto_update_plugin_invocations' => array(),
			'auto_update_theme_invocations'  => array(),
			'auto_update_core_invocations'   => array(),
			'pre_auto_update'               => array(),
			'upgrader_pre_install'          => array(),
			'upgrader_pre_download'         => array(),
			'upgrader_post_install'         => array(),
			'upgrader_pre_install_errors'   => array(),
			'upgrader_pre_download_errors'  => array(),
			'is_main_network'               => function_exists( 'is_main_network' ) ? is_main_network() : null,
			'is_main_site'                  => function_exists( 'is_main_site' ) ? is_main_site() : null,
			'is_multisite'                  => function_exists( 'is_multisite' ) ? is_multisite() : false,
			'transient_reads'               => array(),
			'pre_transient_reads'           => array(),
			'pre_run_transient_snapshot'    => null,
			'pre_run_lock_held'             => null,
			'post_run_lock_held'            => null,
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

		// auto_update_$type filters are called inside should_update() for each item
		// iterated by run(). A counter here distinguishes "the iteration loop never
		// executed" (count 0) from "the iteration ran but should_update returned
		// false for every item" (count > 0 with pre_auto_update count 0). The
		// captured value is the final consensus of all preceding callbacks, after
		// any filter on the site has had its say.
		foreach ( array( 'plugin', 'theme', 'core' ) as $kind ) {
			$key = 'auto_update_' . $kind . '_invocations';
			add_filter(
				'auto_update_' . $kind,
				static function ( $update, $item ) use ( &$breadcrumbs, $key, $kind ) {
					$name = '';
					if ( is_object( $item ) ) {
						if ( 'plugin' === $kind && ! empty( $item->plugin ) ) {
							$name = $item->plugin;
						} elseif ( 'theme' === $kind && ! empty( $item->theme ) ) {
							$name = $item->theme;
						} elseif ( ! empty( $item->slug ) ) {
							$name = $item->slug;
						}
					}
					$breadcrumbs[ $key ][] = array(
						'name'  => $name,
						'value' => var_export( $update, true ),
					);
					return $update;
				},
				PHP_INT_MAX,
				2
			);
		}

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

		// Hook the READ-side transient filters. These are the only thing that can
		// make WP_Automatic_Updater::run() see different transient data than the
		// rest of WordPress sees from a simple get_site_transient() call. The
		// PHP_INT_MAX priority captures the value AFTER every other callback has
		// had its say — which is what run() actually receives.
		foreach ( array( 'update_plugins', 'update_themes', 'update_core' ) as $tname ) {
			add_filter(
				'site_transient_' . $tname,
				static function ( $value, $transient ) use ( &$breadcrumbs ) {
					$breadcrumbs['transient_reads'][] = array(
						'transient'      => $transient,
						'has_response'   => is_object( $value ) && isset( $value->response ) && is_array( $value->response ),
						'response_count' => is_object( $value ) && isset( $value->response ) && is_array( $value->response ) ? count( $value->response ) : 0,
					);
					return $value;
				},
				PHP_INT_MAX,
				2
			);
			add_filter(
				'pre_site_transient_' . $tname,
				static function ( $pre, $transient ) use ( &$breadcrumbs ) {
					$breadcrumbs['pre_transient_reads'][] = array(
						'transient'    => $transient,
						'short_circuited' => false !== $pre,
					);
					return $pre;
				},
				PHP_INT_MAX,
				2
			);
		}

		// Snapshot the transient state and the lock state ourselves immediately
		// before triggering wp_maybe_auto_update(). If our snapshot shows 11
		// pending items and the breadcrumbs show run() saw 0, that's definitive
		// evidence of a read-side filter interception.
		$snapshot_plugins = get_site_transient( 'update_plugins' );
		$snapshot_themes  = get_site_transient( 'update_themes' );
		$snapshot_core    = get_site_transient( 'update_core' );
		$breadcrumbs['pre_run_transient_snapshot'] = array(
			'plugins_response_count' => is_object( $snapshot_plugins ) && isset( $snapshot_plugins->response ) && is_array( $snapshot_plugins->response ) ? count( $snapshot_plugins->response ) : 0,
			'themes_response_count'  => is_object( $snapshot_themes ) && isset( $snapshot_themes->response ) && is_array( $snapshot_themes->response ) ? count( $snapshot_themes->response ) : 0,
			'core_has_response'      => is_object( $snapshot_core ) && isset( $snapshot_core->updates ) && is_array( $snapshot_core->updates ) && count( $snapshot_core->updates ) > 0,
		);
		$breadcrumbs['pre_run_lock_held'] = (bool) get_option( 'auto_updater.lock' );

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

		$breadcrumbs['post_run_lock_held'] = (bool) get_option( 'auto_updater.lock' );

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

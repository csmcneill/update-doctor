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

	const CLEAR_LOCK_ACTION = 'update_doctor_clear_lock';
	const CLEAR_LOCK_NONCE  = 'update_doctor_clear_lock_nonce';

	const EMULATE_ACTION = 'update_doctor_emulate_update';
	const EMULATE_NONCE  = 'update_doctor_emulate_update_nonce';

	/**
	 * Marker used by the failure monitor and activity recorder: true while Update
	 * Doctor's own "Run Update Test" button is driving the updater.
	 *
	 * @var bool
	 */
	public static $manual_run = false;

	/**
	 * True while the "Run Unattended Test" button is driving the updater — a web
	 * request that mimics a cron run (wp_doing_cron filtered true), but is still a
	 * web request, so it is tagged [emulated], never [scheduled].
	 *
	 * @var bool
	 */
	public static $emulated_run = false;

	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::EMULATE_ACTION, array( $this, 'handle_emulate' ) );
		add_action( 'admin_post_' . self::CLEAR_LOCK_ACTION, array( $this, 'handle_clear_lock' ) );
	}

	/**
	 * Delete the auto_updater.lock option, defeating a cache-masked stale lock.
	 *
	 * delete_option() finds the row with a direct DB query (so it works even when the
	 * object cache hides the row from get_option), but we also delete via $wpdb and
	 * scrub the cache to be certain the masked entry is gone.
	 */
	public function handle_clear_lock() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to clear the update lock.', 'update-doctor' ), 403 );
		}

		check_admin_referer( self::CLEAR_LOCK_ACTION, self::CLEAR_LOCK_NONCE );

		global $wpdb;

		$before_db    = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'auto_updater.lock' ) );
		$before_cache = get_option( 'auto_updater.lock' );

		// Remove via the WordPress API (cache-aware) and directly (defeats masking).
		delete_option( 'auto_updater.lock' );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'auto_updater.lock' ) );

		// Scrub the object cache: the single option entry and the notoptions mask.
		wp_cache_delete( 'auto_updater.lock', 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions['auto_updater.lock'] ) ) {
			unset( $notoptions['auto_updater.lock'] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		$after_db = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'auto_updater.lock' ) );

		$payload = array(
			'time'         => time(),
			'before_db'    => null === $before_db ? '(none)' : (string) $before_db,
			'before_cache' => empty( $before_cache ) ? '(none)' : (string) $before_cache,
			'after_db'     => null === $after_db ? '(none)' : (string) $after_db,
			'cleared'      => ( null === $after_db ),
		);
		set_transient( 'update_doctor_lock_cleared', $payload, MINUTE_IN_SECONDS * 30 );

		$redirect = add_query_arg(
			array(
				'page'         => Update_Doctor_Admin_Page::SLUG,
				'doctor_lock'  => '1',
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to run updates.', 'update-doctor' ), 403 );
		}
		check_admin_referer( self::ACTION, self::NONCE );
		$this->finish_run( $this->capture_run( 'manual' ) );
	}

	public function handle_emulate() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to run updates.', 'update-doctor' ), 403 );
		}
		check_admin_referer( self::EMULATE_ACTION, self::EMULATE_NONCE );
		// Get as close to an unattended cron run as a web request can: make
		// wp_doing_cron() report true for the duration. This is still a web (PHP-FPM)
		// request, so it cannot reproduce a true CLI/platform process context — which
		// is why its activity is tagged [emulated], never [scheduled].
		add_filter( 'wp_doing_cron', '__return_true' );
		$this->finish_run( $this->capture_run( 'emulated' ) );
	}

	/**
	 * Persist the captured run payload and return to the diagnostic page.
	 *
	 * @param array $payload
	 */
	private function finish_run( $payload ) {
		set_transient( 'update_doctor_last_run', $payload, WEEK_IN_SECONDS );
		$redirect = add_query_arg(
			array(
				'page'       => Update_Doctor_Admin_Page::SLUG,
				'doctor_run' => '1',
			),
			admin_url( 'tools.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Run the auto-updater with full instrumentation and return the captured payload.
	 *
	 * @param string $kind 'manual' or 'emulated' — sets the run flag (which the
	 *                     activity recorder reads to tag context) and the payload kind.
	 * @return array
	 */
	private function capture_run( $kind ) {
		// Defensive reset. $manual_run / $emulated_run are static, and PHP-FPM workers
		// persist statics across requests. If an earlier run on this worker died before
		// its end-of-run reset (an uncatchable fatal or timeout escapes the try/catch
		// below), a stale flag would survive into this request and mis-tag the run —
		// and, worse, mis-tag a real scheduled run that later reuses the worker as
		// [manual]/[emulated], hiding genuine platform activity. Clear both here, and
		// register a shutdown reset so a death mid-run cannot leak into the next request.
		self::$manual_run   = false;
		self::$emulated_run = false;
		register_shutdown_function(
			static function () {
				self::$manual_run   = false;
				self::$emulated_run = false;
			}
		);

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
			'lock_written_during_run'       => false,
			'lock_released_during_run'      => false,
		);

		// Observe the auto_updater.lock lifecycle without touching it. WP_Upgrader's
		// create_lock()/release_lock() are essentially the only writers of this option,
		// and release_lock() deletes it via delete_option() (which reliably fires the
		// deleted_option action). A delete during the run is strong evidence that run()
		// acquired AND released the lock — i.e. it got past the create_lock() gate at the
		// top of run() and executed the locked section. No write and no delete, with the
		// lock free before and after, is the signature of run() bailing at create_lock()
		// because another process already held the lock (lock contention).
		add_action( 'deleted_option', static function ( $option ) use ( &$breadcrumbs ) {
			if ( 'auto_updater.lock' === $option ) {
				$breadcrumbs['lock_released_during_run'] = true;
			}
		}, 10, 1 );
		add_action( 'added_option', static function ( $option ) use ( &$breadcrumbs ) {
			if ( 'auto_updater.lock' === $option ) {
				$breadcrumbs['lock_written_during_run'] = true;
			}
		}, 10, 1 );
		add_action( 'updated_option', static function ( $option ) use ( &$breadcrumbs ) {
			if ( 'auto_updater.lock' === $option ) {
				$breadcrumbs['lock_written_during_run'] = true;
			}
		}, 10, 1 );

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

		if ( 'emulated' === $kind ) {
			self::$emulated_run = true;
		} else {
			self::$manual_run = true;
		}

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
		self::$manual_run   = false;
		self::$emulated_run = false;

		$breadcrumbs['post_run_lock_held'] = (bool) get_option( 'auto_updater.lock' );

		return array(
			'time'           => time(),
			'kind'           => $kind,
			'output'         => $output,
			'results'        => $results_buffer,
			'errors'         => $captured_errors,
			'breadcrumbs'    => $breadcrumbs,
			'pending_before' => $pending_before,
		);
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

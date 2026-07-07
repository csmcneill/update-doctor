<?php
/**
 * Watches automatic update runs for failures and silent skips, and sends an
 * opt-in email alert (capped at one per 24 hours) pointing the admin at the
 * Update Doctor diagnostic page.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Failure_Monitor {

	const NOTIFY_LOCK_TRANSIENT = 'update_doctor_notify_lock';
	const EXPECTED_OPTION       = 'update_doctor_expected_updates';
	const NOTIFY_LOCK_TTL       = DAY_IN_SECONDS;

	/**
	 * @var Update_Doctor_Settings
	 */
	private $settings;

	public function __construct( Update_Doctor_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		// Track expected auto-updates whenever the plugin update transient is updated.
		add_action( 'set_site_transient_update_plugins', array( $this, 'snapshot_expected_plugins' ), 10, 1 );
		add_action( 'set_site_transient_update_themes',  array( $this, 'snapshot_expected_themes' ),  10, 1 );

		// Inspect outcomes after every auto-update batch.
		add_action( 'automatic_updates_complete', array( $this, 'on_complete' ), 10, 1 );

		// Also clear expected-update entries on manual upgrades so we don't false-positive.
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
	}

	public function snapshot_expected_plugins( $value ) {
		$auto_plugins = (array) get_option( 'auto_update_plugins', array() );
		if ( empty( $auto_plugins ) ) {
			return;
		}

		$expected = $this->load_expected();

		if ( is_object( $value ) && isset( $value->response ) && is_array( $value->response ) ) {
			foreach ( $value->response as $file => $info ) {
				// Only track plugins that can actually auto-update. A response entry with
				// no package download URL is license-gated (a WooCommerce.com, Freemius,
				// or EDD plugin without an active subscription) — WordPress can never
				// auto-update it, so it is not a "silent skip" and must not be expected,
				// or it would trip a false failure alert on every run, forever.
				if ( in_array( $file, $auto_plugins, true ) && ! empty( $info->package ) ) {
					$key = 'plugin:' . $file;
					if ( ! isset( $expected[ $key ] ) ) {
						$expected[ $key ] = array(
							'type'        => 'plugin',
							'slug'        => $file,
							'version'     => isset( $info->new_version ) ? $info->new_version : '',
							'observed_at' => time(),
						);
					}
				}
			}
		}

		$this->save_expected( $expected );
	}

	public function snapshot_expected_themes( $value ) {
		$auto_themes = (array) get_option( 'auto_update_themes', array() );
		if ( empty( $auto_themes ) ) {
			return;
		}

		$expected = $this->load_expected();

		if ( is_object( $value ) && isset( $value->response ) && is_array( $value->response ) ) {
			foreach ( $value->response as $stylesheet => $info ) {
				// Same license-gate guard as plugins: a theme with no package URL cannot
				// auto-update, so it must not be tracked as an expected update. Theme
				// response entries are arrays, so the package key is accessed as such.
				if ( in_array( $stylesheet, $auto_themes, true ) && ! empty( $info['package'] ) ) {
					$key = 'theme:' . $stylesheet;
					if ( ! isset( $expected[ $key ] ) ) {
						$expected[ $key ] = array(
							'type'        => 'theme',
							'slug'        => $stylesheet,
							'version'     => isset( $info['new_version'] ) ? $info['new_version'] : '',
							'observed_at' => time(),
						);
					}
				}
			}
		}

		$this->save_expected( $expected );
	}

	/**
	 * Inspect the results array after a batch of auto-updates.
	 */
	public function on_complete( $results ) {
		// Skip notifications for runs Update Doctor itself initiated (the Manual Update
		// Test); only real scheduled runs should alert.
		if ( Update_Doctor_Update_Trigger::$manual_run ) {
			return;
		}

		// Capture the run results into the same transient the Last Run check reads,
		// so automatic runs are visible in the diagnostic alongside manual ones.
		$payload = array(
			'time'    => time(),
			'kind'    => 'auto',
			'output'  => '',
			'results' => $results,
			'errors'  => array(),
		);
		set_transient( 'update_doctor_last_run', $payload, WEEK_IN_SECONDS );

		$failures = $this->extract_failures( $results );
		$expected = $this->load_expected();

		// Clear expected entries that succeeded.
		if ( is_array( $results ) ) {
			foreach ( array( 'plugin', 'theme' ) as $type ) {
				if ( ! empty( $results[ $type ] ) && is_array( $results[ $type ] ) ) {
					foreach ( $results[ $type ] as $entry ) {
						if ( isset( $entry->item ) && true === $entry->result ) {
							$slug = '';
							if ( 'plugin' === $type && isset( $entry->item->plugin ) ) {
								$slug = $entry->item->plugin;
							} elseif ( 'theme' === $type && isset( $entry->item->theme ) ) {
								$slug = $entry->item->theme;
							}
							if ( $slug ) {
								unset( $expected[ $type . ':' . $slug ] );
							}
						}
					}
				}
			}
		}

		// Anything left in $expected that's older than 6 hours is a silent skip.
		$grace      = 6 * HOUR_IN_SECONDS;
		$now        = time();
		$silent_skips = array();
		foreach ( $expected as $key => $entry ) {
			if ( ( $now - (int) $entry['observed_at'] ) > $grace ) {
				// Only a genuine silent skip if the item is STILL pending with a
				// downloadable package. An item that has since updated has dropped out of
				// the transient; a license-gated item never had a package to download.
				// Neither is a failure, so drop it without alerting. This recheck also
				// flushes license-gated entries recorded by versions before the snapshot
				// guard existed, so upgrading stops the false emails immediately.
				if ( $this->is_still_updatable( $entry ) ) {
					$silent_skips[] = $entry;
				}
				unset( $expected[ $key ] );
			}
		}

		$this->save_expected( $expected );

		if ( $failures || $silent_skips ) {
			$this->maybe_notify();
		}
	}

	/**
	 * If a manual upgrade applies a plugin/theme, drop it from the expected list.
	 */
	public function on_upgrader_complete( $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
			return;
		}
		if ( empty( $hook_extra['type'] ) || empty( $hook_extra['plugins'] ) && empty( $hook_extra['themes'] ) ) {
			return;
		}

		$expected = $this->load_expected();

		if ( ! empty( $hook_extra['plugins'] ) ) {
			foreach ( (array) $hook_extra['plugins'] as $plugin ) {
				unset( $expected[ 'plugin:' . $plugin ] );
			}
		}
		if ( ! empty( $hook_extra['themes'] ) ) {
			foreach ( (array) $hook_extra['themes'] as $theme ) {
				unset( $expected[ 'theme:' . $theme ] );
			}
		}

		$this->save_expected( $expected );
	}

	private function extract_failures( $results ) {
		$failures = array();
		if ( ! is_array( $results ) ) {
			return $failures;
		}
		foreach ( $results as $type => $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( isset( $entry->result ) && ( false === $entry->result || is_wp_error( $entry->result ) ) ) {
					$failures[] = $type;
				}
			}
		}
		return $failures;
	}

	/**
	 * Whether an expected item still has a pending, downloadable update right now.
	 * Distinguishes a genuine silent skip (updatable but not applied) from a
	 * license-gated item (no package) or one that has already updated (gone from the
	 * transient). Only the first warrants an alert.
	 *
	 * @param array $entry
	 * @return bool
	 */
	private function is_still_updatable( array $entry ) {
		$slug = isset( $entry['slug'] ) ? $entry['slug'] : '';
		$type = isset( $entry['type'] ) ? $entry['type'] : '';
		if ( '' === $slug ) {
			return false;
		}
		if ( 'plugin' === $type ) {
			$t = get_site_transient( 'update_plugins' );
			return is_object( $t ) && isset( $t->response[ $slug ] ) && ! empty( $t->response[ $slug ]->package );
		}
		if ( 'theme' === $type ) {
			$t = get_site_transient( 'update_themes' );
			return is_object( $t ) && isset( $t->response[ $slug ] ) && ! empty( $t->response[ $slug ]['package'] );
		}
		return false;
	}

	private function maybe_notify() {
		if ( ! $this->settings->notifications_enabled() ) {
			return;
		}

		// Hard 24-hour throttle.
		if ( get_transient( self::NOTIFY_LOCK_TRANSIENT ) ) {
			return;
		}

		$recipient = $this->settings->recipient();
		if ( ! is_email( $recipient ) ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url  = home_url();

		$subject = sprintf( '[%s] Automatic update issue detected', $site_name );
		$body    = sprintf(
			"An automatic update issue was detected on %s.\n\nVisit Tools → Update Doctor for diagnostics.\n\n— Update Doctor",
			$site_url
		);

		set_transient( self::NOTIFY_LOCK_TRANSIENT, time(), self::NOTIFY_LOCK_TTL );

		wp_mail( $recipient, $subject, $body );
	}

	private function load_expected() {
		$expected = get_option( self::EXPECTED_OPTION, array() );
		return is_array( $expected ) ? $expected : array();
	}

	private function save_expected( array $expected ) {
		update_option( self::EXPECTED_OPTION, $expected, false );
	}
}

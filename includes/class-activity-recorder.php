<?php
/**
 * Passively records real auto-update activity — scheduled or manual — into a rolling
 * option, so Update Doctor can show what the host's own update runner actually does on
 * its schedule, not just what our manual trigger does.
 *
 * Registered on every request (including WP-Cron), the same as the failure monitor, so
 * it captures the platform's scheduled runs without anyone clicking anything.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Activity_Recorder {

	const OPTION = 'update_doctor_activity_log';
	const MAX    = 200;

	public function register() {
		add_action( 'pre_auto_update', array( $this, 'on_pre_auto_update' ), 10, 3 );
		add_action( 'automatic_updates_complete', array( $this, 'on_auto_complete' ), 11, 1 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 11, 2 );
		add_action( 'deleted_option', array( $this, 'on_deleted_option' ), 10, 1 );
		add_action( 'added_option', array( $this, 'on_added_option' ), 10, 2 );
		add_action( 'updated_option', array( $this, 'on_updated_option' ), 10, 3 );
	}

	/**
	 * "manual" when the event happens inside Update Doctor's own trigger; "scheduled"
	 * otherwise (a real WP-Cron / platform-initiated run).
	 */
	private function context() {
		if ( class_exists( 'Update_Doctor_Update_Trigger' ) ) {
			if ( Update_Doctor_Update_Trigger::$manual_run ) {
				return 'manual';
			}
			if ( Update_Doctor_Update_Trigger::$emulated_run ) {
				return 'emulated';
			}
		}
		return 'scheduled';
	}

	private function record( $event, $detail = '' ) {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'ts'      => time(),
			'event'   => $event,
			'detail'  => (string) $detail,
			'context' => $this->context(),
		);
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		// autoload 'no'; this update fires updated_option for our own option, but the
		// option-name guards below ignore it, so there is no recursion.
		update_option( self::OPTION, $log, false );
	}

	public function on_pre_auto_update( $type, $item, $context ) {
		$name = '';
		if ( is_object( $item ) ) {
			if ( 'plugin' === $type && ! empty( $item->plugin ) ) {
				$name = $item->plugin;
			} elseif ( 'theme' === $type && ! empty( $item->theme ) ) {
				$name = $item->theme;
			} elseif ( ! empty( $item->slug ) ) {
				$name = $item->slug;
			}
		}
		$this->record( 'attempt', trim( $type . ' ' . $name ) );
	}

	public function on_auto_complete( $results ) {
		$attempted = 0;
		$failed    = 0;
		if ( is_array( $results ) ) {
			foreach ( $results as $entries ) {
				if ( ! is_array( $entries ) ) {
					continue;
				}
				foreach ( $entries as $entry ) {
					$attempted++;
					if ( isset( $entry->result ) && ( false === $entry->result || is_wp_error( $entry->result ) ) ) {
						$failed++;
					}
				}
			}
		}
		$this->record( 'batch_complete', sprintf( '%d attempted, %d failed', $attempted, $failed ) );
	}

	public function on_upgrader_complete( $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ) {
			return;
		}
		$items = array();
		if ( ! empty( $hook_extra['plugins'] ) ) {
			$items = array_merge( $items, (array) $hook_extra['plugins'] );
		}
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$items[] = $hook_extra['plugin'];
		}
		if ( ! empty( $hook_extra['themes'] ) ) {
			$items = array_merge( $items, (array) $hook_extra['themes'] );
		}
		$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : 'item';
		$this->record( 'upgraded', $type . ': ' . ( $items ? implode( ', ', $items ) : '(unknown)' ) );
	}

	public function on_deleted_option( $option ) {
		if ( 'auto_updater.lock' === $option ) {
			$this->record( 'lock_released' );
		}
	}

	public function on_added_option( $option, $value = null ) {
		if ( 'auto_updater.lock' === $option ) {
			$this->record( 'lock_acquired' );
		}
	}

	public function on_updated_option( $option, $old = null, $new = null ) {
		if ( 'auto_updater.lock' === $option ) {
			$this->record( 'lock_acquired' );
		}
	}
}

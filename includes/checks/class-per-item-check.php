<?php
/**
 * Reports, per plugin and per theme, whether WordPress would auto-update it right now.
 *
 * Uses WP_Automatic_Updater::should_update() to ensure the answer matches what core
 * would actually decide, then cross-references the package download URL — premium
 * plugins distributed through systems like WooCommerce.com Update Manager, Freemius,
 * or EDD Software Licensing leave a version entry in the update transient but no
 * package URL when the site doesn't have an active license, so should_update() will
 * return true even though no download is actually possible.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Per_Item_Check extends Update_Doctor_Check {

	public function id() {
		return 'per_item';
	}

	public function label() {
		return __( 'Per-Plugin and Per-Theme Decisions', 'update-doctor' );
	}

	public function description() {
		return __( 'For every installed plugin and theme, asks WordPress whether it would auto-update right now and why. Cross-references the package download URL to flag updates that are license-gated by their publisher.', 'update-doctor' );
	}

	public function run() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}

		$updater = new WP_Automatic_Updater();
		$results = array();

		// If the updater itself reports disabled, surface that prominently and skip per-item detail.
		if ( $updater->is_disabled() ) {
			$results[] = Update_Doctor_Diagnostic::fail(
				__( 'Automatic updater is disabled', 'update-doctor' ),
				__( 'WordPress reports that automatic updates are disabled at the global level. The Constants and Filters checks above will identify the cause.', 'update-doctor' )
			);
			return $results;
		}

		// Plugins.
		$plugins        = get_plugins();
		$plugin_updates = get_site_transient( 'update_plugins' );
		$auto_plugins   = (array) get_option( 'auto_update_plugins', array() );

		$plugin_lines        = array();
		$license_gated_count = 0;

		foreach ( $plugins as $file => $data ) {
			$has_update = isset( $plugin_updates->response[ $file ] );
			$opted_in   = in_array( $file, $auto_plugins, true );

			$item              = new stdClass();
			$item->plugin      = $file;
			$item->slug        = isset( $plugin_updates->response[ $file ]->slug ) ? $plugin_updates->response[ $file ]->slug : dirname( $file );
			$item->new_version = isset( $plugin_updates->response[ $file ]->new_version ) ? $plugin_updates->response[ $file ]->new_version : '';

			$package = '';
			if ( $has_update && isset( $plugin_updates->response[ $file ]->package ) ) {
				$package = (string) $plugin_updates->response[ $file ]->package;
			}

			$would_update = $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR );
			$reason       = $this->reason_plugin( $has_update, $opted_in, $would_update, $package );

			if ( $has_update && $would_update && '' === $package ) {
				$license_gated_count++;
			}

			$plugin_lines[] = sprintf(
				'%s (%s) — %s%s',
				$data['Name'],
				$data['Version'],
				$reason,
				$has_update ? sprintf( ' [update available: %s]', $item->new_version ) : ''
			);
		}

		if ( $license_gated_count > 0 ) {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'License-gated plugin updates detected', 'update-doctor' ),
				sprintf(
					_n(
						'%d plugin has a pending update with no package download URL. This typically means a premium plugin distributed via a marketplace like WooCommerce.com Update Manager, Freemius, or EDD Software Licensing, where an active subscription or license is required to receive updates. Confirm subscription status with each plugin publisher.',
						'%d plugins have pending updates with no package download URL. This typically means premium plugins distributed via marketplaces like WooCommerce.com Update Manager, Freemius, or EDD Software Licensing, where an active subscription or license is required to receive updates. Confirm subscription status with each plugin publisher.',
						$license_gated_count,
						'update-doctor'
					),
					$license_gated_count
				)
			);
		}

		$results[] = Update_Doctor_Diagnostic::info(
			__( 'Plugins', 'update-doctor' ),
			sprintf( __( '%d plugins inspected.', 'update-doctor' ), count( $plugins ) ),
			$plugin_lines
		);

		// Themes.
		$themes        = wp_get_themes();
		$theme_updates = get_site_transient( 'update_themes' );
		$auto_themes   = (array) get_option( 'auto_update_themes', array() );

		$theme_lines = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$has_update = isset( $theme_updates->response[ $stylesheet ] );
			$opted_in   = in_array( $stylesheet, $auto_themes, true );

			$item              = new stdClass();
			$item->theme       = $stylesheet;
			$item->stylesheet  = $stylesheet;
			$item->new_version = isset( $theme_updates->response[ $stylesheet ]['new_version'] ) ? $theme_updates->response[ $stylesheet ]['new_version'] : '';

			$package = '';
			if ( $has_update && isset( $theme_updates->response[ $stylesheet ]['package'] ) ) {
				$package = (string) $theme_updates->response[ $stylesheet ]['package'];
			}

			$would_update = $updater->should_update( 'theme', $item, get_theme_root( $stylesheet ) );
			$reason       = $this->reason_theme( $has_update, $opted_in, $would_update, $package );

			$theme_lines[] = sprintf(
				'%s (%s) — %s%s',
				$theme->get( 'Name' ),
				$theme->get( 'Version' ),
				$reason,
				$has_update ? sprintf( ' [update available: %s]', $item->new_version ) : ''
			);
		}

		$results[] = Update_Doctor_Diagnostic::info(
			__( 'Themes', 'update-doctor' ),
			sprintf( __( '%d themes inspected.', 'update-doctor' ), count( $themes ) ),
			$theme_lines
		);

		return $results;
	}

	private function reason_plugin( $has_update, $opted_in, $would_update, $package ) {
		if ( ! $has_update ) {
			return __( 'no update available', 'update-doctor' );
		}
		if ( $would_update ) {
			if ( '' === $package ) {
				return __( 'cleared by WordPress, but no package download URL — typically a premium plugin awaiting an active license or subscription', 'update-doctor' );
			}
			return __( 'would auto-update on next cron run', 'update-doctor' );
		}
		if ( ! $opted_in ) {
			return __( 'will NOT auto-update — not opted in (Plugins/Themes screen → enable auto-updates)', 'update-doctor' );
		}
		return __( 'will NOT auto-update — a filter callback returned false (see Filters section)', 'update-doctor' );
	}

	private function reason_theme( $has_update, $opted_in, $would_update, $package ) {
		if ( ! $has_update ) {
			return __( 'no update available', 'update-doctor' );
		}
		if ( $would_update ) {
			if ( '' === $package ) {
				return __( 'cleared by WordPress, but no package download URL — typically a premium theme awaiting an active license or subscription', 'update-doctor' );
			}
			return __( 'would auto-update on next cron run', 'update-doctor' );
		}
		if ( ! $opted_in ) {
			return __( 'will NOT auto-update — not opted in (Plugins/Themes screen → enable auto-updates)', 'update-doctor' );
		}
		return __( 'will NOT auto-update — a filter callback returned false (see Filters section)', 'update-doctor' );
	}
}

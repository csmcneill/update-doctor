<?php
/**
 * Reports, per plugin and per theme, whether WordPress would auto-update it right now.
 *
 * Uses WP_Automatic_Updater::should_update() to ensure the answer matches what core
 * would actually decide.
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
		return __( 'For every installed plugin and theme, asks WordPress whether it would auto-update right now and why.', 'update-doctor' );
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

		$plugin_lines = array();
		foreach ( $plugins as $file => $data ) {
			$has_update = isset( $plugin_updates->response[ $file ] );
			$opted_in   = in_array( $file, $auto_plugins, true );

			$item              = new stdClass();
			$item->plugin      = $file;
			$item->slug        = isset( $plugin_updates->response[ $file ]->slug ) ? $plugin_updates->response[ $file ]->slug : dirname( $file );
			$item->new_version = isset( $plugin_updates->response[ $file ]->new_version ) ? $plugin_updates->response[ $file ]->new_version : '';

			$would_update = $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR );
			$reason       = $this->reason( 'plugin', $has_update, $opted_in, $would_update );

			$plugin_lines[] = sprintf(
				'%s (%s) — %s%s',
				$data['Name'],
				$data['Version'],
				$reason,
				$has_update ? sprintf( ' [update available: %s]', $item->new_version ) : ''
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

			$item                = new stdClass();
			$item->theme         = $stylesheet;
			$item->stylesheet    = $stylesheet;
			$item->new_version   = isset( $theme_updates->response[ $stylesheet ]['new_version'] ) ? $theme_updates->response[ $stylesheet ]['new_version'] : '';

			$would_update = $updater->should_update( 'theme', $item, get_theme_root( $stylesheet ) );
			$reason       = $this->reason( 'theme', $has_update, $opted_in, $would_update );

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

	private function reason( $type, $has_update, $opted_in, $would_update ) {
		if ( ! $has_update ) {
			return __( 'no update available', 'update-doctor' );
		}
		if ( $would_update ) {
			return __( 'would auto-update on next cron run', 'update-doctor' );
		}
		if ( ! $opted_in ) {
			return __( 'will NOT auto-update — not opted in (Plugins/Themes screen → enable auto-updates)', 'update-doctor' );
		}
		return __( 'will NOT auto-update — a filter callback returned false (see Filters section)', 'update-doctor' );
	}
}

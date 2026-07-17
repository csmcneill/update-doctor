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
		$managed_count       = 0;
		$managed_lines       = array();

		foreach ( $plugins as $file => $data ) {
			$has_update = isset( $plugin_updates->response[ $file ] );
			$opted_in   = in_array( $file, $auto_plugins, true );
			$managed    = $this->is_host_managed_plugin( $file );

			$item              = new stdClass();
			$item->plugin      = $file;
			$item->slug        = isset( $plugin_updates->response[ $file ]->slug ) ? $plugin_updates->response[ $file ]->slug : dirname( $file );
			$item->new_version = isset( $plugin_updates->response[ $file ]->new_version ) ? $plugin_updates->response[ $file ]->new_version : '';

			$package = '';
			if ( $has_update && isset( $plugin_updates->response[ $file ]->package ) ) {
				$package = (string) $plugin_updates->response[ $file ]->package;
			}

			$would_update = $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR );
			$reason       = $this->reason_plugin( $has_update, $opted_in, $would_update, $package, $managed );

			// License-gated covers every cleared item whose package cannot download:
			// empty, an expired-subscription marker, or any other non-URL value.
			if ( $has_update && $would_update && ! preg_match( '#^https?://#i', $package ) && ! $managed ) {
				$license_gated_count++;
			}
			if ( $managed ) {
				$managed_count++;
				$managed_lines[] = sprintf( '%s (%s)', $data['Name'], $file );
			}

			$source_tag = $managed ? ' [host-managed]' : '';
			$plugin_lines[] = sprintf(
				'%s (%s) — %s%s%s',
				$data['Name'],
				$data['Version'],
				$reason,
				$has_update ? sprintf( ' [update available: %s]', $item->new_version ) : '',
				$source_tag
			);
		}

		if ( $managed_count > 0 ) {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'Host-managed plugins detected', 'update-doctor' ),
				sprintf(
					_n(
						'%d plugin is symlinked from a shared host-managed store and is updated externally by your hosting provider. WordPress\'s auto-update system intentionally skips these plugins because the host handles them. This is normal and not a bug.',
						'%d plugins are symlinked from a shared host-managed store and are updated externally by your hosting provider. WordPress\'s auto-update system intentionally skips these plugins because the host handles them. This is normal and not a bug.',
						$managed_count,
						'update-doctor'
					),
					$managed_count
				),
				$managed_lines
			);
		}

		if ( $license_gated_count > 0 ) {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'License-gated plugin updates detected', 'update-doctor' ),
				sprintf(
					_n(
						'%d plugin has a pending update with no downloadable package URL (empty, an expired-subscription marker, or another non-URL value). This typically means a premium plugin distributed via a marketplace like WooCommerce.com Update Manager, Freemius, or EDD Software Licensing, where an active subscription or license is required to receive updates. The per-item lines below name each package state. Confirm subscription status with each plugin publisher.',
						'%d plugins have pending updates with no downloadable package URL (empty, an expired-subscription marker, or another non-URL value). This typically means premium plugins distributed via marketplaces like WooCommerce.com Update Manager, Freemius, or EDD Software Licensing, where an active subscription or license is required to receive updates. The per-item lines below name each package state. Confirm subscription status with each plugin publisher.',
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

	private function reason_plugin( $has_update, $opted_in, $would_update, $package, $managed = false ) {
		if ( $managed ) {
			return $has_update
				? __( 'will NOT auto-update via WordPress — symlinked from host-managed store; your host updates this externally', 'update-doctor' )
				: __( 'no update available (host-managed plugin)', 'update-doctor' );
		}
		if ( ! $has_update ) {
			return __( 'no update available', 'update-doctor' );
		}
		if ( $would_update ) {
			return $this->cleared_reason( $package, __( 'plugin', 'update-doctor' ) );
		}
		if ( ! $opted_in ) {
			return __( 'will NOT auto-update — not opted in (Plugins/Themes screen → enable auto-updates)', 'update-doctor' );
		}
		return __( 'will NOT auto-update — a filter callback returned false (see Filters section)', 'update-doctor' );
	}

	/**
	 * The reason line for an item WordPress has cleared to auto-update, refined by
	 * what the package field actually holds — the field that decides whether the
	 * download can succeed. Real URLs are reduced to their host so signed download
	 * URLs (which can embed auth tokens) never appear in a shareable report.
	 *
	 * @param string $package The package value from the update transient.
	 * @param string $noun    Translated 'plugin' or 'theme', for the license-gated copy.
	 * @return string
	 */
	private function cleared_reason( $package, $noun ) {
		if ( '' === $package ) {
			/* translators: %s: "plugin" or "theme" */
			return sprintf( __( 'cleared by WordPress, but no package download URL — typically a premium %s awaiting an active license or subscription', 'update-doctor' ), $noun );
		}

		if ( 0 === strpos( $package, 'woocommerce-com-expired-' ) ) {
			return __( 'cleared by WordPress, but the package is WooCommerce.com\'s expired-subscription marker — core will block the download with a renewal notice instead of updating', 'update-doctor' );
		}

		if ( preg_match( '#^https?://#i', $package ) ) {
			$host = wp_parse_url( $package, PHP_URL_HOST );
			/* translators: %s: hostname of the package download URL */
			return sprintf( __( 'would auto-update on next cron run (package: %s)', 'update-doctor' ), $host ? $host : 'url' );
		}

		return sprintf(
			/* translators: %s: truncated non-URL package value */
			__( 'cleared by WordPress, but the package value is not a downloadable URL ("%s") — the download will fail', 'update-doctor' ),
			substr( $package, 0, 40 ) . ( strlen( $package ) > 40 ? '…' : '' )
		);
	}

	/**
	 * A plugin is "host-managed" on Atomic/Pressable when its directory in
	 * wp-content/plugins/ is a symlink whose realpath lives under /wordpress/.
	 * This matches the test that atomic-platform.php uses to decide whether to
	 * strip the plugin from update transients and opt-in lists.
	 */
	private function is_host_managed_plugin( $plugin_file ) {
		if ( ! is_string( $plugin_file ) || '' === $plugin_file ) {
			return false;
		}
		$plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
		if ( ! is_dir( $plugin_dir ) || ! is_link( $plugin_dir ) ) {
			return false;
		}
		$real = @realpath( $plugin_dir );
		if ( ! $real ) {
			return false;
		}
		// Common host-managed realpath prefixes. Pressable / WordPress.com Atomic
		// use /wordpress/. Other managed hosts may use different prefixes; this
		// list is the verified set as of v1.1.4.
		foreach ( array( '/wordpress/', '/usr/share/wordpress/', '/opt/wordpress/' ) as $prefix ) {
			if ( 0 === strpos( $real, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	private function reason_theme( $has_update, $opted_in, $would_update, $package ) {
		if ( ! $has_update ) {
			return __( 'no update available', 'update-doctor' );
		}
		if ( $would_update ) {
			return $this->cleared_reason( $package, __( 'theme', 'update-doctor' ) );
		}
		if ( ! $opted_in ) {
			return __( 'will NOT auto-update — not opted in (Plugins/Themes screen → enable auto-updates)', 'update-doctor' );
		}
		return __( 'will NOT auto-update — a filter callback returned false (see Filters section)', 'update-doctor' );
	}
}

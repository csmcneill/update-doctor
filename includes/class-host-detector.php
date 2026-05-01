<?php
/**
 * Detects common managed-WordPress hosting platforms.
 *
 * Many managed hosts (Pressable, WP Engine, WordPress.com Atomic, Kinsta, Pantheon,
 * Flywheel) run automatic updates from their platform rather than via WP-Cron. On
 * those sites, several of WordPress's normal indicators (wp_maybe_auto_update being
 * scheduled, auto_update_core being enabled) are absent by design. The diagnostic
 * needs to know about that context so it doesn't report false positives.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Host_Detector {

	/**
	 * Inspect the environment for indicators of a managed host.
	 *
	 * @return array{detected:bool, host:string, evidence:string[]}
	 */
	public function detect() {
		$evidence = array();
		$host     = '';

		$mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ( WP_CONTENT_DIR . '/mu-plugins' );

		// WordPress.com Atomic (also covers Pressable's Atomic-based platform).
		if ( file_exists( $mu_dir . '/atomic-platform.php' ) || class_exists( 'Atomic_Platform_Mu_Plugin' ) ) {
			$host = 'wpcom-atomic';
			$evidence[] = __( 'mu-plugin atomic-platform.php is present (WordPress.com Atomic / Pressable).', 'update-doctor' );
		} elseif ( is_dir( $mu_dir . '/wpcomsh' ) || file_exists( $mu_dir . '/wpcomsh-loader.php' ) ) {
			$host = 'wpcom-atomic';
			$evidence[] = __( 'mu-plugin wpcomsh is present (WordPress.com Atomic).', 'update-doctor' );
		}

		// Pressable.
		if ( defined( 'IS_PRESSABLE' ) && IS_PRESSABLE ) {
			$host = $host ?: 'pressable';
			$evidence[] = __( 'Constant IS_PRESSABLE is defined.', 'update-doctor' );
		}

		// WP Engine.
		if ( defined( 'WPE_APIKEY' ) || defined( 'WPE_INSTALL' ) || defined( 'WPE_GOVERNOR' ) ) {
			$host = $host ?: 'wpengine';
			$evidence[] = __( 'WP Engine constant is defined.', 'update-doctor' );
		}
		if ( file_exists( $mu_dir . '/wpengine-common' ) || is_dir( $mu_dir . '/wpengine-common' ) ) {
			$host = $host ?: 'wpengine';
			$evidence[] = __( 'mu-plugin wpengine-common is present.', 'update-doctor' );
		}

		// Kinsta.
		if ( defined( 'KINSTA_CACHE_ZONE' ) || defined( 'KINSTAMU_VERSION' ) ) {
			$host = $host ?: 'kinsta';
			$evidence[] = __( 'Kinsta constant is defined.', 'update-doctor' );
		}

		// Flywheel.
		if ( defined( 'FLYWHEEL_CONFIG_DIR' ) || defined( 'FLYWHEEL_PLUGIN_DIR' ) ) {
			$host = $host ?: 'flywheel';
			$evidence[] = __( 'Flywheel constant is defined.', 'update-doctor' );
		}

		// Pantheon.
		if ( defined( 'PANTHEON_ENVIRONMENT' ) ) {
			$host = $host ?: 'pantheon';
			$evidence[] = __( 'PANTHEON_ENVIRONMENT is defined.', 'update-doctor' );
		}

		return array(
			'detected' => ! empty( $evidence ),
			'host'     => $host,
			'evidence' => $evidence,
		);
	}

	/**
	 * Human-readable label for a detected host slug.
	 */
	public function label( $host ) {
		switch ( $host ) {
			case 'wpcom-atomic':
				return __( 'WordPress.com Atomic / Pressable', 'update-doctor' );
			case 'pressable':
				return __( 'Pressable', 'update-doctor' );
			case 'wpengine':
				return __( 'WP Engine', 'update-doctor' );
			case 'kinsta':
				return __( 'Kinsta', 'update-doctor' );
			case 'flywheel':
				return __( 'Flywheel', 'update-doctor' );
			case 'pantheon':
				return __( 'Pantheon', 'update-doctor' );
			default:
				return __( 'unknown managed host', 'update-doctor' );
		}
	}
}

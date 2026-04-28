<?php
/**
 * Inspects PHP constants that influence the WordPress auto-update decision.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Constants_Check extends Update_Doctor_Check {

	public function id() {
		return 'constants';
	}

	public function label() {
		return __( 'Constants', 'update-doctor' );
	}

	public function description() {
		return __( 'Checks the PHP constants in wp-config.php that can disable automatic updates entirely.', 'update-doctor' );
	}

	public function run() {
		$results = array();

		// AUTOMATIC_UPDATER_DISABLED — the master kill switch.
		$results[] = $this->evaluate(
			'AUTOMATIC_UPDATER_DISABLED',
			defined( 'AUTOMATIC_UPDATER_DISABLED' ) ? (bool) AUTOMATIC_UPDATER_DISABLED : null,
			array(
				true  => array( Update_Doctor_Diagnostic::STATUS_FAIL, __( 'AUTOMATIC_UPDATER_DISABLED is true. All automatic updates are disabled by this constant. Remove it from wp-config.php to allow updates.', 'update-doctor' ) ),
				false => array( Update_Doctor_Diagnostic::STATUS_PASS, __( 'AUTOMATIC_UPDATER_DISABLED is explicitly false; not blocking updates.', 'update-doctor' ) ),
				null  => array( Update_Doctor_Diagnostic::STATUS_PASS, __( 'AUTOMATIC_UPDATER_DISABLED is not defined; not blocking updates.', 'update-doctor' ) ),
			)
		);

		// DISALLOW_FILE_MODS — disables ALL file modifications, including updates.
		$results[] = $this->evaluate(
			'DISALLOW_FILE_MODS',
			defined( 'DISALLOW_FILE_MODS' ) ? (bool) DISALLOW_FILE_MODS : null,
			array(
				true  => array( Update_Doctor_Diagnostic::STATUS_FAIL, __( 'DISALLOW_FILE_MODS is true. WordPress cannot install, update, or delete plugins, themes, or core files. Remove it to allow updates.', 'update-doctor' ) ),
				false => array( Update_Doctor_Diagnostic::STATUS_PASS, __( 'DISALLOW_FILE_MODS is explicitly false; not blocking updates.', 'update-doctor' ) ),
				null  => array( Update_Doctor_Diagnostic::STATUS_PASS, __( 'DISALLOW_FILE_MODS is not defined; not blocking updates.', 'update-doctor' ) ),
			)
		);

		// WP_AUTO_UPDATE_CORE — controls core auto-updates.
		$core_value = defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : 'undefined';
		if ( 'undefined' === $core_value ) {
			$results[] = Update_Doctor_Diagnostic::info(
				'WP_AUTO_UPDATE_CORE',
				__( 'Not defined; WordPress will use its default behaviour (minor and dev updates auto-apply).', 'update-doctor' )
			);
		} elseif ( false === $core_value ) {
			$results[] = Update_Doctor_Diagnostic::warn(
				'WP_AUTO_UPDATE_CORE',
				__( 'Set to false. Core auto-updates are disabled (plugin/theme auto-updates may still run).', 'update-doctor' )
			);
		} else {
			$results[] = Update_Doctor_Diagnostic::info(
				'WP_AUTO_UPDATE_CORE',
				sprintf( __( 'Set to %s. Controls which core releases auto-apply.', 'update-doctor' ), wp_json_encode( $core_value ) )
			);
		}

		// DISABLE_WP_CRON — if true, no cron events fire automatically.
		$results[] = $this->evaluate(
			'DISABLE_WP_CRON',
			defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : null,
			array(
				true  => array( Update_Doctor_Diagnostic::STATUS_WARN, __( 'DISABLE_WP_CRON is true. WordPress will not fire cron events (including auto-updates) unless an external process triggers wp-cron.php on a schedule. Confirm with your host that real cron is configured.', 'update-doctor' ) ),
				false => array( Update_Doctor_Diagnostic::STATUS_PASS, __( 'DISABLE_WP_CRON is explicitly false; cron events fire on traffic.', 'update-doctor' ) ),
				null  => array( Update_Doctor_Diagnostic::STATUS_PASS, __( 'DISABLE_WP_CRON is not defined; cron events fire on traffic.', 'update-doctor' ) ),
			)
		);

		// ALTERNATE_WP_CRON — informational only.
		if ( defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON ) {
			$results[] = Update_Doctor_Diagnostic::info(
				'ALTERNATE_WP_CRON',
				__( 'Enabled. Cron is fired via a redirect on the front-end. Some hosts disable this; verify cron is firing reliably.', 'update-doctor' )
			);
		}

		// FS_METHOD — informational; reveals filesystem behaviour.
		if ( defined( 'FS_METHOD' ) ) {
			$results[] = Update_Doctor_Diagnostic::info(
				'FS_METHOD',
				sprintf( __( 'Set to %s. Forces WordPress to use a specific filesystem transport for updates.', 'update-doctor' ), wp_json_encode( FS_METHOD ) )
			);
		}

		// WP_DEBUG_LOG — useful to know whether logs are even being captured.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$log_path = is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
			$results[] = Update_Doctor_Diagnostic::info(
				'WP_DEBUG_LOG',
				sprintf( __( 'Enabled. Log path: %s', 'update-doctor' ), $log_path )
			);
		} else {
			$results[] = Update_Doctor_Diagnostic::info(
				'WP_DEBUG_LOG',
				__( 'Not enabled. Errors during background updates will not be captured to a debug log.', 'update-doctor' )
			);
		}

		return $results;
	}

	/**
	 * Build a Diagnostic for a boolean-ish constant using a status map.
	 */
	private function evaluate( $name, $value, array $map ) {
		$key = is_bool( $value ) ? $value : null;
		$key = ( null === $key ) ? 'null' : ( $key ? 'true' : 'false' );

		// Normalize the map keys (PHP converts true/false/null to 1/''/'') to the same string keys.
		$normalized = array(
			'true'  => isset( $map[ true ] ) ? $map[ true ] : null,
			'false' => isset( $map[ false ] ) ? $map[ false ] : null,
			'null'  => isset( $map[ null ] ) ? $map[ null ] : null,
		);

		if ( ! isset( $normalized[ $key ] ) ) {
			return Update_Doctor_Diagnostic::info( $name, __( 'Unable to evaluate.', 'update-doctor' ) );
		}

		list( $status, $message ) = $normalized[ $key ];
		return new Update_Doctor_Diagnostic( $status, $name, $message );
	}
}

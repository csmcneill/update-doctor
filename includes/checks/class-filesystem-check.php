<?php
/**
 * Inspects filesystem readiness for performing updates.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Filesystem_Check extends Update_Doctor_Check {

	public function id() {
		return 'filesystem';
	}

	public function label() {
		return __( 'Filesystem', 'update-doctor' );
	}

	public function description() {
		return __( "If WordPress can't write to its update folders, every update silently fails.", 'update-doctor' );
	}

	public function run() {
		$results = array();

		$paths = array(
			WP_CONTENT_DIR . '/upgrade'             => __( 'Working directory used while applying updates.', 'update-doctor' ),
			WP_CONTENT_DIR . '/upgrade-temp-backup' => __( 'Rollback directory created by WordPress 6.3+ when applying updates.', 'update-doctor' ),
			WP_CONTENT_DIR . '/plugins'             => __( 'Plugins directory; updates write here.', 'update-doctor' ),
			WP_CONTENT_DIR . '/themes'              => __( 'Themes directory; updates write here.', 'update-doctor' ),
			WP_CONTENT_DIR                          => __( 'wp-content directory; parent for all of the above.', 'update-doctor' ),
		);

		foreach ( $paths as $path => $description ) {
			$exists   = file_exists( $path );
			$writable = $exists ? wp_is_writable( $path ) : false;

			if ( ! $exists ) {
				// upgrade and upgrade-temp-backup may not exist until first update; not necessarily fatal.
				$is_optional = in_array( basename( $path ), array( 'upgrade', 'upgrade-temp-backup' ), true );
				$results[]   = $is_optional
					? Update_Doctor_Diagnostic::info( $path, __( 'Does not exist yet. Will be created by WordPress on first update.', 'update-doctor' ), array( 'description' => $description ) )
					: Update_Doctor_Diagnostic::fail( $path, __( 'Does not exist.', 'update-doctor' ), array( 'description' => $description ) );
				continue;
			}

			if ( ! $writable ) {
				$results[] = Update_Doctor_Diagnostic::fail(
					$path,
					__( 'Exists but is not writable by PHP. Updates cannot be applied to this directory.', 'update-doctor' ),
					array( 'description' => $description )
				);
			} else {
				$results[] = Update_Doctor_Diagnostic::pass(
					$path,
					__( 'Writable.', 'update-doctor' ),
					array( 'description' => $description )
				);
			}
		}

		// Disk space check.
		$free = @disk_free_space( WP_CONTENT_DIR );
		if ( false === $free ) {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'Free disk space', 'update-doctor' ),
				__( 'Unable to determine free disk space.', 'update-doctor' )
			);
		} elseif ( $free < 50 * 1024 * 1024 ) {
			$results[] = Update_Doctor_Diagnostic::fail(
				__( 'Free disk space', 'update-doctor' ),
				sprintf( __( 'Only %s available. Updates may fail to download or apply.', 'update-doctor' ), size_format( $free ) )
			);
		} else {
			$results[] = Update_Doctor_Diagnostic::pass(
				__( 'Free disk space', 'update-doctor' ),
				sprintf( __( '%s available.', 'update-doctor' ), size_format( $free ) )
			);
		}

		// WP_Filesystem method.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$method = function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : '';
		if ( 'direct' === $method ) {
			$results[] = Update_Doctor_Diagnostic::pass(
				__( 'WP_Filesystem method', 'update-doctor' ),
				__( "direct (PHP can write to wp-content directly; this is the typical configuration on managed hosts).", 'update-doctor' )
			);
		} elseif ( $method ) {
			$results[] = Update_Doctor_Diagnostic::warn(
				__( 'WP_Filesystem method', 'update-doctor' ),
				sprintf( __( 'Resolved to %s. WordPress will prompt for FTP/SSH credentials when updating, which means background updates cannot run.', 'update-doctor' ), wp_json_encode( $method ) )
			);
		} else {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'WP_Filesystem method', 'update-doctor' ),
				__( 'Could not resolve a filesystem method.', 'update-doctor' )
			);
		}

		return $results;
	}
}

<?php
/**
 * Tails the WordPress debug log for entries related to auto-updates.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Error_Log_Check extends Update_Doctor_Check {

	const MAX_LINES = 200;
	const MAX_BYTES = 1024 * 1024; // 1 MB

	public function id() {
		return 'error_log';
	}

	public function label() {
		return __( 'Error Log', 'update-doctor' );
	}

	public function description() {
		return __( 'Looks at the most recent entries in the WordPress debug log for hints about update failures.', 'update-doctor' );
	}

	public function run() {
		$path = $this->log_path();

		if ( ! $path ) {
			return array(
				Update_Doctor_Diagnostic::info(
					__( 'No log path configured', 'update-doctor' ),
					__( 'Set WP_DEBUG_LOG to true (or to a file path) in wp-config.php to start capturing background-update errors.', 'update-doctor' )
				),
			);
		}

		if ( ! file_exists( $path ) ) {
			return array(
				Update_Doctor_Diagnostic::info(
					__( 'Log file does not exist', 'update-doctor' ),
					sprintf( __( 'Configured path: %s', 'update-doctor' ), $path )
				),
			);
		}

		if ( ! is_readable( $path ) ) {
			return array(
				Update_Doctor_Diagnostic::warn(
					__( 'Log file not readable', 'update-doctor' ),
					sprintf( __( 'Configured path: %s', 'update-doctor' ), $path )
				),
			);
		}

		$lines = $this->tail( $path, self::MAX_LINES, self::MAX_BYTES );

		$pattern = '/(WP_Automatic_Updater|Upgrader|auto[- ]update|Background Update|automatic update)/i';
		$matches = array_values( array_filter( $lines, static function ( $line ) use ( $pattern ) {
			return (bool) preg_match( $pattern, $line );
		} ) );

		if ( empty( $matches ) ) {
			return array(
				Update_Doctor_Diagnostic::pass(
					__( 'No update-related entries in recent log lines', 'update-doctor' ),
					sprintf( __( "Tailed last %d lines of %s and found nothing relevant.", 'update-doctor' ), self::MAX_LINES, $path )
				),
			);
		}

		// Truncate each line for display.
		$display = array_map( static function ( $line ) {
			$line = trim( $line );
			if ( strlen( $line ) > 300 ) {
				return substr( $line, 0, 300 ) . '…';
			}
			return $line;
		}, $matches );

		return array(
			Update_Doctor_Diagnostic::warn(
				sprintf( __( '%d update-related entries found in recent log', 'update-doctor' ), count( $matches ) ),
				sprintf( __( "Tailed last %d lines of %s.", 'update-doctor' ), self::MAX_LINES, $path ),
				$display
			),
		);
	}

	private function log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) ) {
			if ( is_string( WP_DEBUG_LOG ) ) {
				return WP_DEBUG_LOG;
			}
			if ( WP_DEBUG_LOG && defined( 'WP_CONTENT_DIR' ) ) {
				return WP_CONTENT_DIR . '/debug.log';
			}
		}
		return '';
	}

	/**
	 * Read the last N lines of a file, capped at $max_bytes.
	 */
	private function tail( $path, $max_lines, $max_bytes ) {
		$size = @filesize( $path );
		if ( false === $size || 0 === $size ) {
			return array();
		}

		$read = min( $size, $max_bytes );
		$fh   = @fopen( $path, 'rb' );
		if ( ! $fh ) {
			return array();
		}

		fseek( $fh, max( 0, $size - $read ) );
		$contents = fread( $fh, $read );
		fclose( $fh );

		$lines = explode( "\n", (string) $contents );
		// If we're not reading from byte 0, the first line is partial — discard it.
		if ( $size > $read && count( $lines ) > 0 ) {
			array_shift( $lines );
		}

		return array_slice( $lines, -$max_lines );
	}
}

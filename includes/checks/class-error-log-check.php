<?php
/**
 * Tails the WordPress debug log and the PHP error log for entries related to auto-updates.
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
		return __( 'Looks at the most recent entries in the WordPress debug log and the PHP error log for hints about update failures, including PHP fatal errors.', 'update-doctor' );
	}

	public function run() {
		$results = array();
		$paths   = $this->candidate_log_paths();

		if ( empty( $paths ) ) {
			return array(
				Update_Doctor_Diagnostic::info(
					__( 'No log path configured', 'update-doctor' ),
					__( 'Set WP_DEBUG_LOG to true (or to a file path) in wp-config.php to start capturing background-update errors. Also check that PHP\'s error_log directive is configured.', 'update-doctor' )
				),
			);
		}

		foreach ( $paths as $label => $path ) {
			$results[] = $this->inspect( $label, $path );
		}

		return $results;
	}

	/**
	 * @return array<string,string> map of label => path
	 */
	private function candidate_log_paths() {
		$paths = array();

		// WordPress debug log.
		if ( defined( 'WP_DEBUG_LOG' ) ) {
			if ( is_string( WP_DEBUG_LOG ) ) {
				$paths['WP_DEBUG_LOG'] = WP_DEBUG_LOG;
			} elseif ( WP_DEBUG_LOG && defined( 'WP_CONTENT_DIR' ) ) {
				$paths['WP_DEBUG_LOG'] = WP_CONTENT_DIR . '/debug.log';
			}
		}

		// PHP error log (separate path on many managed hosts).
		$php_log = ini_get( 'error_log' );
		if ( $php_log && is_string( $php_log ) && ! in_array( $php_log, $paths, true ) ) {
			$paths['php_error_log'] = $php_log;
		}

		return $paths;
	}

	private function inspect( $label, $path ) {
		if ( ! file_exists( $path ) ) {
			return Update_Doctor_Diagnostic::info(
				sprintf( __( '%s: file does not exist', 'update-doctor' ), $label ),
				sprintf( __( 'Configured path: %s', 'update-doctor' ), $path )
			);
		}

		if ( ! is_readable( $path ) ) {
			return Update_Doctor_Diagnostic::warn(
				sprintf( __( '%s: file not readable', 'update-doctor' ), $label ),
				sprintf( __( 'Configured path: %s', 'update-doctor' ), $path )
			);
		}

		$lines = $this->tail( $path, self::MAX_LINES, self::MAX_BYTES );

		// Match update-related context AND/OR fatal/parse errors.
		$update_pattern = '/(WP_Automatic_Updater|Upgrader|auto[- ]update|Background Update|automatic update)/i';
		$fatal_pattern  = '/(PHP Fatal error|PHP Parse error|PHP Recoverable error|Uncaught [A-Z][A-Za-z_\\\\]*Exception)/i';

		$update_hits = array();
		$fatal_hits  = array();

		foreach ( $lines as $line ) {
			$is_update = (bool) preg_match( $update_pattern, $line );
			$is_fatal  = (bool) preg_match( $fatal_pattern, $line );

			if ( $is_update ) {
				$update_hits[] = $line;
			}
			if ( $is_fatal ) {
				$fatal_hits[] = $line;
			}
		}

		// Fatal errors are always worth surfacing, regardless of whether they look update-related.
		if ( ! empty( $fatal_hits ) ) {
			return Update_Doctor_Diagnostic::fail(
				sprintf( __( '%s: fatal/parse errors found in recent entries', 'update-doctor' ), $label ),
				sprintf(
					__( '%d fatal-class entries found in the last %d lines of %s. Even if not directly update-related, fatal errors during an update can abort the process silently. Investigate each.', 'update-doctor' ),
					count( $fatal_hits ),
					self::MAX_LINES,
					$path
				),
				array_map( array( $this, 'truncate' ), array_slice( $fatal_hits, -20 ) )
			);
		}

		if ( ! empty( $update_hits ) ) {
			return Update_Doctor_Diagnostic::warn(
				sprintf( __( '%s: %d update-related entries found', 'update-doctor' ), $label, count( $update_hits ) ),
				sprintf( __( 'Tailed last %d lines of %s.', 'update-doctor' ), self::MAX_LINES, $path ),
				array_map( array( $this, 'truncate' ), array_slice( $update_hits, -20 ) )
			);
		}

		return Update_Doctor_Diagnostic::pass(
			sprintf( __( '%s: no update-related or fatal entries in recent log', 'update-doctor' ), $label ),
			sprintf( __( 'Tailed last %d lines of %s and found nothing relevant.', 'update-doctor' ), self::MAX_LINES, $path )
		);
	}

	private function truncate( $line ) {
		$line = trim( $line );
		if ( strlen( $line ) > 300 ) {
			return substr( $line, 0, 300 ) . '…';
		}
		return $line;
	}

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
		if ( $size > $read && count( $lines ) > 0 ) {
			array_shift( $lines );
		}

		return array_slice( $lines, -$max_lines );
	}
}

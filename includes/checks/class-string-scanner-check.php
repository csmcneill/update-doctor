<?php
/**
 * Scans wp-content for string occurrences that suggest auto-update interference,
 * including cached snippet libraries and code that may not currently be loaded.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_String_Scanner_Check extends Update_Doctor_Check {

	const MAX_DEPTH        = 4;
	const MAX_FILE_SIZE    = 5 * 1024 * 1024; // 5 MB
	const MAX_FILES_SCAN   = 5000;
	const SKIP_EXTENSIONS  = 'png|jpe?g|gif|webp|ico|mp[34]|mov|avi|svg|woff2?|ttf|eot|zip|gz|tar|bz2|pdf';

	public function id() {
		return 'string_scanner';
	}

	public function label() {
		return __( 'String Scanner', 'update-doctor' );
	}

	public function description() {
		return __( "Searches plugin, mu-plugin, and uploads directories for code that disables auto-updates — including cached snippet files that aren't currently loaded as filters.", 'update-doctor' );
	}

	public function run() {
		$patterns = array(
			'auto_update_plugin'         => __( "References the 'auto_update_plugin' filter.", 'update-doctor' ),
			'auto_update_theme'          => __( "References the 'auto_update_theme' filter.", 'update-doctor' ),
			'automatic_updater_disabled' => __( "References the 'automatic_updater_disabled' filter.", 'update-doctor' ),
			'AUTOMATIC_UPDATER_DISABLED' => __( "References the AUTOMATIC_UPDATER_DISABLED constant.", 'update-doctor' ),
			'DISALLOW_FILE_MODS'         => __( "References the DISALLOW_FILE_MODS constant.", 'update-doctor' ),
		);

		$roots = array_filter(
			array(
				WP_CONTENT_DIR . '/mu-plugins',
				WP_CONTENT_DIR . '/plugins',
				WP_CONTENT_DIR . '/uploads',
			),
			'is_dir'
		);

		$matches    = array();
		$file_count = 0;
		foreach ( $roots as $root ) {
			$this->scan_dir( $root, 0, $patterns, $matches, $file_count );
			if ( $file_count >= self::MAX_FILES_SCAN ) {
				break;
			}
		}

		$results = array();

		if ( $file_count >= self::MAX_FILES_SCAN ) {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'Scan limit reached', 'update-doctor' ),
				sprintf( __( 'Scanner stopped after %d files to avoid impacting performance. Re-run on a quieter site for full coverage.', 'update-doctor' ), self::MAX_FILES_SCAN )
			);
		}

		if ( empty( $matches ) ) {
			$results[] = Update_Doctor_Diagnostic::pass(
				__( 'No suspicious strings found', 'update-doctor' ),
				__( 'Did not find references to the auto-update kill-switches in any scanned plugin, mu-plugin, or uploads file.', 'update-doctor' )
			);
			return $results;
		}

		// Group matches by pattern for readability.
		$by_pattern = array();
		foreach ( $matches as $match ) {
			$by_pattern[ $match['pattern'] ][] = $match;
		}

		foreach ( $by_pattern as $pattern => $list ) {
			$lines = array();
			foreach ( $list as $m ) {
				$lines[] = $m['file'] . ':' . $m['line'] . ' — ' . $m['snippet'];
			}
			$results[] = Update_Doctor_Diagnostic::warn(
				sprintf( __( '%d matches for "%s"', 'update-doctor' ), count( $list ), $pattern ),
				$patterns[ $pattern ],
				$lines
			);
		}

		return $results;
	}

	private function scan_dir( $dir, $depth, array $patterns, array &$matches, &$file_count ) {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		if ( $file_count >= self::MAX_FILES_SCAN ) {
			return;
		}

		$handle = @opendir( $dir );
		if ( ! $handle ) {
			return;
		}

		while ( false !== ( $entry = readdir( $handle ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;

			if ( is_dir( $path ) ) {
				$this->scan_dir( $path, $depth + 1, $patterns, $matches, $file_count );
				continue;
			}

			if ( ! is_file( $path ) ) {
				continue;
			}

			if ( preg_match( '#\.(' . self::SKIP_EXTENSIONS . ')$#i', $entry ) ) {
				continue;
			}

			$size = @filesize( $path );
			if ( false === $size || $size > self::MAX_FILE_SIZE ) {
				continue;
			}

			$file_count++;
			if ( $file_count > self::MAX_FILES_SCAN ) {
				closedir( $handle );
				return;
			}

			$contents = @file_get_contents( $path );
			if ( false === $contents ) {
				continue;
			}

			foreach ( $patterns as $needle => $_description ) {
				$pos = 0;
				while ( false !== ( $pos = strpos( $contents, $needle, $pos ) ) ) {
					$line_no = substr_count( substr( $contents, 0, $pos ), "\n" ) + 1;
					$snippet = $this->extract_line( $contents, $pos );
					$matches[] = array(
						'pattern' => $needle,
						'file'    => $this->display_path( $path ),
						'line'    => $line_no,
						'snippet' => $snippet,
					);
					$pos += strlen( $needle );
				}
			}
		}

		closedir( $handle );
	}

	private function extract_line( $contents, $pos ) {
		$line_start = strrpos( substr( $contents, 0, $pos ), "\n" );
		$line_start = ( false === $line_start ) ? 0 : $line_start + 1;
		$line_end   = strpos( $contents, "\n", $pos );
		if ( false === $line_end ) {
			$line_end = strlen( $contents );
		}
		$line = substr( $contents, $line_start, $line_end - $line_start );
		$line = trim( $line );
		if ( strlen( $line ) > 200 ) {
			$line = substr( $line, 0, 200 ) . '…';
		}
		return $line;
	}

	private function display_path( $path ) {
		if ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $path, WP_CONTENT_DIR ) ) {
			return 'wp-content' . substr( $path, strlen( WP_CONTENT_DIR ) );
		}
		if ( defined( 'ABSPATH' ) && 0 === strpos( $path, ABSPATH ) ) {
			return substr( $path, strlen( ABSPATH ) );
		}
		return $path;
	}
}

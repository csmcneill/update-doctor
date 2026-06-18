<?php
/**
 * Replicates the two checks inside WP_Automatic_Updater::should_update() that run
 * BEFORE the auto_update_{$type} filter. If either fails, every plugin and theme
 * is silently skipped during an automatic update — even when it shows as
 * "auto-updates enabled" in wp-admin, and even when the update transient is fully
 * populated.
 *
 * In WP core (wp-admin/includes/class-wp-automatic-updater.php), should_update()
 * contains:
 *
 *     if ( ! $skin->request_filesystem_credentials( false, $context, $allow_relaxed_file_ownership )
 *          || $this->is_vcs_checkout( $context )
 *     ) {
 *         ...
 *         return false;   // <-- before the auto_update_{$type} filter
 *     }
 *
 * For plugins and themes, $allow_relaxed_file_ownership is false, so the ownership
 * test is strict. This is the single most common cause of "auto-updates are enabled
 * but nothing ever updates" — and it does not surface anywhere in the wp-admin UI.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Unattended_Gate_Check extends Update_Doctor_Check {

	public function id() {
		return 'unattended_gate';
	}

	public function label() {
		return __( 'Unattended Update Gate', 'update-doctor' );
	}

	public function description() {
		return __( "Replicates the filesystem-access and VCS checks that WP_Automatic_Updater::should_update() runs BEFORE the auto_update filter. If either fails, every plugin and theme is silently skipped during automatic updates regardless of its opt-in setting — this is the most common cause of \"auto-updates enabled but nothing updates.\"", 'update-doctor' );
	}

	public function run() {
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$results = array();

		$results[] = $this->check_filesystem_method();
		$results[] = $this->check_ownership();
		$results[] = $this->check_vcs();

		return $results;
	}

	/**
	 * The decisive test. Auto-updates resolve the filesystem method for the plugin
	 * context with STRICT ownership (allow_relaxed_file_ownership = false). If this
	 * is not "direct", request_filesystem_credentials() returns false during an
	 * unattended run and should_update() bails before the auto_update filter.
	 *
	 * Note this differs from the generic get_filesystem_method() the Filesystem
	 * section reports — that one uses no context and relaxed ownership, so it can
	 * say "direct" while this stricter, context-specific test says otherwise.
	 */
	private function check_filesystem_method() {
		$generic = get_filesystem_method();
		$plugin_strict = get_filesystem_method( array(), WP_PLUGIN_DIR, false );
		$theme_strict  = get_filesystem_method( array(), get_theme_root(), false );

		$details = array(
			sprintf( __( 'generic method (no context, relaxed ownership): %s', 'update-doctor' ), $generic ),
			sprintf( __( 'plugin context, strict ownership: %s', 'update-doctor' ), $plugin_strict ),
			sprintf( __( 'theme context, strict ownership: %s', 'update-doctor' ), $theme_strict ),
		);

		if ( 'direct' !== $plugin_strict || 'direct' !== $theme_strict ) {
			return Update_Doctor_Diagnostic::fail(
				__( 'Unattended updates cannot obtain direct filesystem access', 'update-doctor' ),
				__( "With strict ownership checks (which automatic updates always use for plugins and themes), WordPress cannot write directly to the install directory, so it would need FTP/SSH credentials — which are not available during an unattended run. WP_Automatic_Updater::should_update() therefore returns false before the auto_update filter, and every plugin and theme is skipped. The usual cause is that the files are owned by a different system user than the PHP process, which commonly happens after a site is migrated between hosts. See the ownership check below, and ask your host to correct file ownership on wp-content.", 'update-doctor' ),
				$details
			);
		}

		return Update_Doctor_Diagnostic::pass(
			__( 'Unattended updates can obtain direct filesystem access', 'update-doctor' ),
			__( 'Strict-ownership filesystem checks pass for both plugins and themes; this gate is not blocking automatic updates.', 'update-doctor' ),
			$details
		);
	}

	/**
	 * The thing the strict filesystem method actually tests: does the PHP process
	 * own the files it would need to overwrite? Surface the raw uids so a host can
	 * act on it directly.
	 */
	private function check_ownership() {
		if ( ! function_exists( 'getmyuid' ) || ! function_exists( 'fileowner' ) ) {
			return Update_Doctor_Diagnostic::info(
				__( 'File ownership', 'update-doctor' ),
				__( 'POSIX ownership functions are not available in this PHP build; cannot compare file ownership.', 'update-doctor' )
			);
		}

		$php_uid   = getmyuid();
		$targets   = array(
			'wp-content/plugins'      => WP_PLUGIN_DIR,
			'wp-content/themes'       => get_theme_root(),
			'wp-content/upgrade'      => WP_CONTENT_DIR . '/upgrade',
		);

		$mismatch = false;
		$details  = array( sprintf( __( 'PHP process uid: %d', 'update-doctor' ), $php_uid ) );

		foreach ( $targets as $label => $path ) {
			if ( ! file_exists( $path ) ) {
				$details[] = sprintf( '%s: %s', $label, __( 'does not exist', 'update-doctor' ) );
				continue;
			}
			$owner = @fileowner( $path );
			if ( false === $owner ) {
				$details[] = sprintf( '%s: %s', $label, __( 'owner unreadable', 'update-doctor' ) );
				continue;
			}
			$flag = ( $owner !== $php_uid ) ? '  [MISMATCH]' : '';
			if ( $owner !== $php_uid ) {
				$mismatch = true;
			}
			$details[] = sprintf( '%s: owned by uid %d%s', $label, $owner, $flag );
		}

		if ( $mismatch ) {
			return Update_Doctor_Diagnostic::warn(
				__( 'File ownership mismatch', 'update-doctor' ),
				__( "At least one update target is owned by a different uid than the PHP process. WordPress's strict ownership test for unattended updates treats this as \"not directly writable,\" which causes should_update() to skip every item before the auto_update filter. Ask your host to correct ownership (chown to the PHP/web user) on the affected directories. This pattern frequently appears after a site is migrated between hosts.", 'update-doctor' ),
				$details
			);
		}

		return Update_Doctor_Diagnostic::pass(
			__( 'File ownership', 'update-doctor' ),
			__( 'Update target directories are owned by the PHP process uid; ownership is not blocking unattended updates.', 'update-doctor' ),
			$details
		);
	}

	/**
	 * Replicate WP's is_vcs_checkout() reasoning: a VCS metadata directory at or
	 * above the install root makes the auto-updater treat the site as developer-
	 * managed and refuse to update.
	 */
	private function check_vcs() {
		$vcs_dirs = array( '.svn', '.git', '.hg', '.bzr' );

		// Walk from the plugin dir up to (and including) ABSPATH's parent.
		$check_dirs = array();
		$dir        = realpath( WP_PLUGIN_DIR );
		$abspath    = realpath( ABSPATH );

		if ( $dir ) {
			while ( true ) {
				$check_dirs[] = $dir;
				$parent = dirname( $dir );
				if ( $parent === $dir ) {
					break;
				}
				// Stop once we've passed above ABSPATH.
				if ( $abspath && strlen( $parent ) < strlen( $abspath ) && 0 !== strpos( $abspath, $parent ) ) {
					break;
				}
				$dir = $parent;
			}
		}
		if ( $abspath && ! in_array( $abspath, $check_dirs, true ) ) {
			$check_dirs[] = $abspath;
		}

		foreach ( $check_dirs as $check_dir ) {
			foreach ( $vcs_dirs as $vcs_dir ) {
				if ( @is_dir( $check_dir . '/' . $vcs_dir ) ) {
					return Update_Doctor_Diagnostic::fail(
						__( 'VCS checkout detected', 'update-doctor' ),
						sprintf(
							__( 'Found a %1$s directory at %2$s. WP_Automatic_Updater::is_vcs_checkout() treats this as a developer-managed checkout and should_update() returns false before the auto_update filter — so nothing auto-updates. Remove or relocate the %1$s directory, or hook the automatic_updates_is_vcs_checkout filter to override, to allow automatic updates.', 'update-doctor' ),
							$vcs_dir,
							$check_dir . '/' . $vcs_dir
						)
					);
				}
			}
		}

		return Update_Doctor_Diagnostic::pass(
			__( 'VCS checkout detection', 'update-doctor' ),
			__( 'No .git, .svn, .hg, or .bzr directory found in the plugin tree or above it up to the install root.', 'update-doctor' )
		);
	}
}

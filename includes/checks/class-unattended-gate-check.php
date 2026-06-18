<?php
/**
 * Evaluates the gate inside WP_Automatic_Updater::should_update() that runs BEFORE
 * the auto_update_{$type} filter. If this gate rejects an item, the item is silently
 * skipped during an automatic update — even when it shows "auto-updates enabled" in
 * wp-admin and the update transient is fully populated.
 *
 * The gate, in WP core, is:
 *
 *     if ( ! $skin->request_filesystem_credentials( false, $context, $allow_relaxed_file_ownership )
 *          || $this->is_vcs_checkout( $context )
 *     ) {
 *         return false;   // <-- before the auto_update_{$type} filter
 *     }
 *
 * Rather than reimplement these conditions (an earlier version did, and produced a
 * false positive on a healthy site), this check calls WordPress's own functions and
 * reports their actual return values:
 *
 *   - request_filesystem_credentials( '', '', false, WP_PLUGIN_DIR, null, false )
 *   - WP_Automatic_Updater::is_vcs_checkout( $context )   (a public method)
 *
 * Note: when FS_METHOD is defined as 'direct', request_filesystem_credentials() always
 * succeeds, so on those sites the filesystem branch can never be the cause and the gate
 * can only be tripped by a VCS checkout.
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
		return __( "Evaluates the filesystem-access and VCS conditions that WP_Automatic_Updater::should_update() checks BEFORE the auto_update filter. If either rejects an item, every plugin and theme is silently skipped during automatic updates regardless of its opt-in setting — the most common cause of \"auto-updates enabled but nothing updates.\" This check calls WordPress's own functions directly and reports their actual return values.", 'update-doctor' );
	}

	public function run() {
		if ( ! function_exists( 'request_filesystem_credentials' ) || ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		}

		$results = array();

		$creds_ok = $this->check_credentials();
		$results[] = $creds_ok['diagnostic'];

		$results[] = $this->check_vcs();

		return $results;
	}

	/**
	 * Call request_filesystem_credentials() exactly as should_update() does for a
	 * plugin (strict ownership) and report whether it grants access. This is the
	 * authoritative filesystem branch of the gate.
	 *
	 * @return array{ok:bool, diagnostic:Update_Doctor_Diagnostic}
	 */
	private function check_credentials() {
		$fs_method_defined = defined( 'FS_METHOD' ) ? FS_METHOD : null;

		// request_filesystem_credentials() can echo a credentials form when access is
		// not direct; buffer and discard any output. Return value is what matters.
		ob_start();
		$creds = request_filesystem_credentials( '', '', false, WP_PLUGIN_DIR, null, false );
		ob_end_clean();

		$ok = ( false !== $creds );

		$details = array();
		if ( null !== $fs_method_defined ) {
			$details[] = sprintf( __( 'FS_METHOD constant is defined as "%s" — request_filesystem_credentials() is forced to this method, so the filesystem branch of the gate cannot be the cause on this site.', 'update-doctor' ), $fs_method_defined );
		}
		$details[] = sprintf( __( 'request_filesystem_credentials( plugin context, strict ownership ) returned: %s', 'update-doctor' ), $ok ? 'access granted' : 'FALSE (no access)' );

		if ( $ok ) {
			return array(
				'ok'         => true,
				'diagnostic' => Update_Doctor_Diagnostic::pass(
					__( 'Filesystem access for unattended updates', 'update-doctor' ),
					__( 'WordPress can obtain direct filesystem access during an unattended update, so this branch of the should_update() gate is not blocking updates.', 'update-doctor' ),
					$details
				),
			);
		}

		return array(
			'ok'         => false,
			'diagnostic' => Update_Doctor_Diagnostic::fail(
				__( 'Filesystem access denied for unattended updates', 'update-doctor' ),
				__( "request_filesystem_credentials() returned false for the plugin context with strict ownership. WP_Automatic_Updater::should_update() therefore returns false before the auto_update filter, and every plugin and theme is skipped. Because no FS_METHOD forces this, the usual cause is that PHP cannot create files owned by the same user as the existing WordPress files (an ownership mismatch). Ask your host to correct file ownership on wp-content, or define FS_METHOD as 'direct' in wp-config.php if direct writes are actually safe on this host.", 'update-doctor' ),
				$details
			),
		);
	}

	/**
	 * Call WP_Automatic_Updater::is_vcs_checkout() directly (it is public) for both
	 * the plugin context and ABSPATH — the two contexts should_update() and run()
	 * pass. If either is true, locate the actual VCS directory so the user can act.
	 */
	private function check_vcs() {
		$updater = new WP_Automatic_Updater();

		$plugin_vcs  = (bool) $updater->is_vcs_checkout( WP_PLUGIN_DIR );
		$abspath_vcs = (bool) $updater->is_vcs_checkout( ABSPATH );

		if ( ! $plugin_vcs && ! $abspath_vcs ) {
			return Update_Doctor_Diagnostic::pass(
				__( 'VCS checkout detection', 'update-doctor' ),
				__( "WordPress's is_vcs_checkout() returns false for both the plugin directory and the install root; this branch of the gate is not blocking updates.", 'update-doctor' ),
				array(
					'is_vcs_checkout( WP_PLUGIN_DIR ): false',
					'is_vcs_checkout( ABSPATH ): false',
				)
			);
		}

		// At least one context is a VCS checkout. Find the offending directory.
		$found = $this->locate_vcs_dirs();
		$details = array(
			sprintf( 'is_vcs_checkout( WP_PLUGIN_DIR ): %s', $plugin_vcs ? 'true' : 'false' ),
			sprintf( 'is_vcs_checkout( ABSPATH ): %s', $abspath_vcs ? 'true' : 'false' ),
		);
		if ( ! empty( $found ) ) {
			$details[] = '— VCS directories found:';
			foreach ( $found as $f ) {
				$details[] = '   ' . $f;
			}
		} else {
			$details[] = __( 'Could not locate the specific VCS directory by scanning (it may be above the scanned depth, or hidden by permissions), but WordPress\'s own is_vcs_checkout() reports one exists.', 'update-doctor' );
		}

		return Update_Doctor_Diagnostic::fail(
			__( 'VCS checkout detected — automatic updates are disabled', 'update-doctor' ),
			__( "WordPress's is_vcs_checkout() returns true, so WP_Automatic_Updater::should_update() returns false for every plugin and theme before the auto_update filter ever runs. WordPress refuses to auto-update a site it believes is under version control, to avoid clobbering a developer's working copy. Remove or relocate the VCS metadata directory listed below (for example, rename .git to .git-disabled), or add `add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );` to a must-use plugin if the checkout is intentional and you still want auto-updates. This is the single condition blocking automatic updates on this site.", 'update-doctor' ),
			$details
		);
	}

	/**
	 * Scan the same trees WP_Automatic_Updater::is_vcs_checkout() inspects — from
	 * WP_PLUGIN_DIR up to root, and from ABSPATH up to root — for VCS metadata dirs.
	 */
	private function locate_vcs_dirs() {
		$vcs_dirs = array( '.svn', '.git', '.hg', '.bzr' );
		$found    = array();

		$starts = array( WP_PLUGIN_DIR, ABSPATH );
		$checked = array();

		foreach ( $starts as $start ) {
			$dir = realpath( $start );
			if ( ! $dir ) {
				continue;
			}
			while ( true ) {
				if ( isset( $checked[ $dir ] ) ) {
					// already walked this dir and its ancestors
					break;
				}
				$checked[ $dir ] = true;
				foreach ( $vcs_dirs as $vcs ) {
					if ( @is_dir( $dir . '/' . $vcs ) ) {
						$found[] = $dir . '/' . $vcs;
					}
				}
				$parent = dirname( $dir );
				if ( $parent === $dir ) {
					break;
				}
				$dir = $parent;
			}
		}

		return array_values( array_unique( $found ) );
	}
}

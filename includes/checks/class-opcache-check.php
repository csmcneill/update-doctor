<?php
/**
 * Reports how PHP OPcache validates changed files. On hosts that do not validate
 * timestamps, updated plugin/theme/core code keeps running its previously-compiled
 * bytecode until OPcache is cleared — so an update can install yet appear to do
 * nothing. This does not block updates from installing; it governs whether the
 * newly installed code actually runs.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_OPcache_Check extends Update_Doctor_Check {

	public function id() {
		return 'opcache';
	}

	public function label() {
		return __( 'PHP OPcache', 'update-doctor' );
	}

	public function description() {
		return __( 'OPcache compiles PHP to bytecode and reuses it. How it validates changed files determines whether updated code runs once an update has installed.', 'update-doctor' );
	}

	public function run() {
		$results = array();

		// ini_get() is used throughout rather than opcache_get_configuration(): hosts
		// that set opcache.restrict_api (e.g. Pressable) lock the opcache_*() API to a
		// specific script path, so it returns false from inside a plugin — but ini_get()
		// reads the directive values regardless of restrict_api.
		$enabled = filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );

		if ( ! $enabled ) {
			$results[] = Update_Doctor_Diagnostic::pass(
				__( 'OPcache is disabled', 'update-doctor' ),
				__( 'OPcache is off in this context, so updated PHP files take effect immediately. There is no bytecode cache to clear after an update.', 'update-doctor' )
			);
			return $results;
		}

		$validate = filter_var( ini_get( 'opcache.validate_timestamps' ), FILTER_VALIDATE_BOOLEAN );
		$freq     = (int) ini_get( 'opcache.revalidate_freq' );
		$restrict = trim( (string) ini_get( 'opcache.restrict_api' ) );

		$details = array(
			'opcache.enable'              => '1',
			'opcache.validate_timestamps' => $validate ? '1' : '0',
			'opcache.revalidate_freq'     => (string) $freq,
			'opcache.restrict_api'        => '' !== $restrict ? $restrict : '(unset)',
		);

		if ( $validate ) {
			$results[] = Update_Doctor_Diagnostic::pass(
				__( 'OPcache validates file timestamps', 'update-doctor' ),
				sprintf(
					/* translators: %d: opcache.revalidate_freq in seconds */
					__( 'OPcache is on and re-checks file modification times (revalidate_freq: %d second(s)), so updated plugin, theme, and core files are recompiled automatically — within that window — and updates take effect on their own.', 'update-doctor' ),
					$freq
				),
				$details
			);
		} else {
			$results[] = Update_Doctor_Diagnostic::warn(
				__( 'OPcache does not validate file timestamps', 'update-doctor' ),
				__( 'OPcache is on but opcache.validate_timestamps is 0, so it never re-checks files for changes. Updated plugin, theme, and core files keep running their previously-compiled bytecode until OPcache is reset — typically via your host\'s "clear cache" control or a PHP-FPM restart. Automatic updates can still download and install; they simply will not take effect until the cache is flushed. (This also means updating Update Doctor itself has no visible effect until OPcache is cleared.)', 'update-doctor' ),
				$details
			);
		}

		if ( '' !== $restrict ) {
			$results[] = Update_Doctor_Diagnostic::info(
				__( 'OPcache reset is API-restricted', 'update-doctor' ),
				sprintf(
					/* translators: %s: opcache.restrict_api path */
					__( 'opcache.restrict_api is set to "%s". Only PHP scripts under that path may call opcache_reset() or opcache_invalidate(), so a plugin cannot flush OPcache itself — use the host\'s cache-clear control or contact the host.', 'update-doctor' ),
					$restrict
				)
			);
		}

		return $results;
	}
}

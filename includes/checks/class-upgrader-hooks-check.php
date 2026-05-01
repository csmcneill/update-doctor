<?php
/**
 * Inspects callbacks on the WP_Upgrader hooks that can silently abort or modify
 * the actual update process — the layer below WordPress's auto-update *decision*.
 *
 * The decision filters (auto_update_plugin, auto_update_theme, etc.) are inspected
 * by the Filters and Hooks check. This one covers the execution layer: hooks that
 * fire while an update is being downloaded, extracted, or installed.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Upgrader_Hooks_Check extends Update_Doctor_Check {

	/**
	 * @var Update_Doctor_Hook_Inspector
	 */
	private $inspector;

	public function __construct( Update_Doctor_Hook_Inspector $inspector ) {
		$this->inspector = $inspector;
	}

	public function id() {
		return 'upgrader_hooks';
	}

	public function label() {
		return __( 'Upgrader Hooks', 'update-doctor' );
	}

	public function description() {
		return __( "Lists callbacks on the hooks that fire during the upgrade process itself — separate from the auto-update decision layer. Misuse here can silently abort an update mid-process even after the auto-update logic has cleared it to run.", 'update-doctor' );
	}

	public function run() {
		// Filter hooks that can abort or modify an upgrade in flight.
		$abortable_filters = array(
			'upgrader_pre_install'             => __( 'Fires before install starts. A callback returning a WP_Error here aborts the install.', 'update-doctor' ),
			'upgrader_pre_download'            => __( 'Fires before the package is downloaded. A callback returning a WP_Error here aborts the download.', 'update-doctor' ),
			'upgrader_source_selection'        => __( 'Selects which directory inside the downloaded package is treated as the source. A callback returning a WP_Error aborts the install.', 'update-doctor' ),
			'upgrader_install_package_result'  => __( 'Filters the result of installation. A callback returning a WP_Error reports the install as failed.', 'update-doctor' ),
			'upgrader_post_install'            => __( 'Fires after install. A callback can mutate the result; returning a WP_Error reports failure.', 'update-doctor' ),
			'upgrader_clear_destination'       => __( 'Controls whether the destination directory is cleared before install. A callback returning a WP_Error aborts.', 'update-doctor' ),
		);

		// Action hooks: observers that fire on completion. Cannot abort but are useful to inspect for context.
		$completion_actions = array(
			'upgrader_process_complete'  => __( 'Fires after the upgrader finishes. Often used for logging, cache busting, or third-party integration.', 'update-doctor' ),
			'automatic_updates_complete' => __( 'Fires after a batch of automatic updates completes. Often used for notification or audit logging.', 'update-doctor' ),
		);

		$results = array();

		foreach ( $abortable_filters as $tag => $description ) {
			$results[] = $this->inspect( $tag, $description, true );
		}

		foreach ( $completion_actions as $tag => $description ) {
			$results[] = $this->inspect( $tag, $description, false );
		}

		return $results;
	}

	private function inspect( $tag, $description, $abortable ) {
		$callbacks = $this->inspector->inspect( $tag );

		if ( empty( $callbacks ) ) {
			return Update_Doctor_Diagnostic::pass(
				$tag,
				__( 'No callbacks registered.', 'update-doctor' ),
				array( 'description' => $description )
			);
		}

		$lines = array();
		foreach ( $callbacks as $cb ) {
			$location = $cb['file'] ? $cb['file'] . ( $cb['line'] ? ':' . $cb['line'] : '' ) : '';
			$marker   = $cb['suspicious'] ? ' [' . __( 'suspicious', 'update-doctor' ) . ']' : '';
			$lines[]  = sprintf(
				'priority %d — %s%s%s',
				$cb['priority'],
				$cb['callback_label'],
				$location ? ' (' . $location . ')' : '',
				$marker
			);
		}

		// Abortable filters with callbacks deserve more attention than completion actions.
		$count = count( $callbacks );
		if ( $abortable ) {
			$message = sprintf(
				_n(
					'%d callback registered. If updates are silently failing, inspect this callback for code that returns a WP_Error.',
					'%d callbacks registered. If updates are silently failing, inspect these callbacks for code that returns a WP_Error.',
					$count,
					'update-doctor'
				),
				$count
			);
		} else {
			$message = sprintf(
				_n(
					'%d callback registered (observer; cannot abort the update).',
					'%d callbacks registered (observers; cannot abort the update).',
					$count,
					'update-doctor'
				),
				$count
			);
		}

		return Update_Doctor_Diagnostic::info(
			$tag,
			$message,
			array_merge( array( 'description' => $description ), $lines )
		);
	}
}

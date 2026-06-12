<?php
/**
 * Inspects callbacks attached to the filters that gate auto-updates.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Filters_Check extends Update_Doctor_Check {

	/**
	 * @var Update_Doctor_Hook_Inspector
	 */
	private $inspector;

	public function __construct( Update_Doctor_Hook_Inspector $inspector ) {
		$this->inspector = $inspector;
	}

	public function id() {
		return 'filters';
	}

	public function label() {
		return __( 'Filters and Hooks', 'update-doctor' );
	}

	public function description() {
		return __( "Lists every callback registered to the filters that decide whether automatic updates run. Each callback's source file and line are shown so you can find third-party code that may be interfering.", 'update-doctor' );
	}

	public function run() {
		$filters = array(
			'automatic_updater_disabled'              => __( 'Master kill-switch filter; if any callback returns true, ALL auto-updates are disabled.', 'update-doctor' ),
			'auto_update_plugin'                      => __( 'Per-plugin opt-in filter. If any callback returns false, plugin auto-updates are skipped.', 'update-doctor' ),
			'auto_update_theme'                       => __( 'Per-theme opt-in filter. If any callback returns false, theme auto-updates are skipped.', 'update-doctor' ),
			'auto_update_core'                        => __( 'Per-release filter for core auto-updates.', 'update-doctor' ),
			'auto_update_translation'                 => __( 'Per-translation filter for translation auto-updates.', 'update-doctor' ),
			'file_mod_allowed'                        => __( 'Gates whether file modifications (including updates) are allowed at all.', 'update-doctor' ),
			'pre_set_site_transient_update_plugins'   => __( 'Mutates the available-plugin-updates transient when it is WRITTEN. Misuse here can hide updates entirely.', 'update-doctor' ),
			'pre_set_site_transient_update_themes'    => __( 'Mutates the available-theme-updates transient when it is WRITTEN.', 'update-doctor' ),
			'pre_site_transient_update_plugins'       => __( 'Short-circuits the available-plugin-updates transient READ. Returning anything other than false bypasses the DB entirely and is what the auto-updater will see.', 'update-doctor' ),
			'pre_site_transient_update_themes'        => __( 'Short-circuits the available-theme-updates transient READ.', 'update-doctor' ),
			'pre_site_transient_update_core'          => __( 'Short-circuits the available-core-update transient READ.', 'update-doctor' ),
			'site_transient_update_plugins'           => __( 'Modifies the available-plugin-updates transient when it is READ. A callback that returns a stripped object will cause WP_Automatic_Updater::run() to iterate nothing — even if the DB still holds the original.', 'update-doctor' ),
			'site_transient_update_themes'            => __( 'Modifies the available-theme-updates transient when it is READ.', 'update-doctor' ),
			'site_transient_update_core'              => __( 'Modifies the available-core-update transient when it is READ.', 'update-doctor' ),
			'option_auto_update_plugins'              => __( 'Filters the auto_update_plugins option when READ. Managed-host mu-plugins use this to strip platform-managed plugins from the user-visible opt-in list.', 'update-doctor' ),
			'option_auto_update_themes'               => __( 'Filters the auto_update_themes option when READ.', 'update-doctor' ),
			'pre_update_option_auto_update_plugins'   => __( 'Filters the auto_update_plugins option when WRITTEN. Managed-host mu-plugins use this to prevent users from opting in to auto-updates for platform-managed plugins.', 'update-doctor' ),
			'pre_update_option_auto_update_themes'    => __( 'Filters the auto_update_themes option when WRITTEN.', 'update-doctor' ),
		);

		$results = array();

		foreach ( $filters as $tag => $description ) {
			$callbacks = $this->inspector->inspect( $tag );

			if ( empty( $callbacks ) ) {
				$results[] = Update_Doctor_Diagnostic::pass(
					$tag,
					__( 'No callbacks registered. Nothing on this site is overriding the default behaviour.', 'update-doctor' ),
					array( 'description' => $description )
				);
				continue;
			}

			$status = Update_Doctor_Diagnostic::STATUS_INFO;
			$lines  = array();
			foreach ( $callbacks as $cb ) {
				$location = '';
				if ( $cb['file'] ) {
					$location = $cb['file'] . ( $cb['line'] ? ':' . $cb['line'] : '' );
				}

				$marker = $cb['suspicious'] ? ' [' . __( 'suspicious', 'update-doctor' ) . ']' : '';
				$lines[] = sprintf(
					'priority %d — %s%s%s',
					$cb['priority'],
					$cb['callback_label'],
					$location ? ' (' . $location . ')' : '',
					$marker
				);

				if ( $cb['suspicious'] ) {
					$status = Update_Doctor_Diagnostic::STATUS_WARN;
				}
			}

			$results[] = new Update_Doctor_Diagnostic(
				$status,
				$tag,
				sprintf(
					_n(
						'%d callback registered. Review the source(s) below to confirm none of them is unintentionally disabling updates.',
						'%d callbacks registered. Review the sources below to confirm none of them is unintentionally disabling updates.',
						count( $callbacks ),
						'update-doctor'
					),
					count( $callbacks )
				),
				array_merge( array( 'description' => $description ), $lines )
			);
		}

		return $results;
	}
}

<?php
/**
 * Top-level orchestrator. Wires checks, the admin page, the update trigger,
 * the failure monitor, and settings together.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Plugin {

	private static $instance = null;

	/** @var Update_Doctor_Runner */
	public $runner;

	/** @var Update_Doctor_Hook_Inspector */
	public $hook_inspector;

	/** @var Update_Doctor_Host_Detector */
	public $host_detector;

	/** @var Update_Doctor_Update_Trigger */
	public $update_trigger;

	/** @var Update_Doctor_Report_Formatter */
	public $report_formatter;

	/** @var Update_Doctor_Settings */
	public $settings;

	/** @var Update_Doctor_Failure_Monitor */
	public $failure_monitor;

	/** @var Update_Doctor_Admin_Page */
	public $admin_page;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->hook_inspector   = new Update_Doctor_Hook_Inspector();
		$this->host_detector    = new Update_Doctor_Host_Detector();
		$this->runner           = new Update_Doctor_Runner();
		$this->update_trigger   = new Update_Doctor_Update_Trigger();
		$this->report_formatter = new Update_Doctor_Report_Formatter();
		$this->settings         = new Update_Doctor_Settings();
		$this->failure_monitor  = new Update_Doctor_Failure_Monitor( $this->settings );
		$this->admin_page       = new Update_Doctor_Admin_Page( $this->runner, $this->update_trigger, $this->report_formatter, $this->settings );

		$this->register_checks();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$this->admin_page->register();
			$this->update_trigger->register();
			$this->settings->register();
		}

		// Failure monitor runs on cron context too, not just admin.
		$this->failure_monitor->register();
	}

	private function register_checks() {
		$this->runner->register( new Update_Doctor_Constants_Check() );
		$this->runner->register( new Update_Doctor_Filters_Check( $this->hook_inspector ) );
		$this->runner->register( new Update_Doctor_Cron_Check( $this->host_detector ) );
		$this->runner->register( new Update_Doctor_Filesystem_Check() );
		$this->runner->register( new Update_Doctor_Options_Check() );
		$this->runner->register( new Update_Doctor_Per_Item_Check() );
		$this->runner->register( new Update_Doctor_String_Scanner_Check() );
		$this->runner->register( new Update_Doctor_Error_Log_Check() );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'update-doctor', false, dirname( plugin_basename( UPDATE_DOCTOR_FILE ) ) . '/languages' );
	}
}

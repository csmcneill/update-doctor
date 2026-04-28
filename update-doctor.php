<?php
/**
 * Plugin Name: Update Doctor
 * Plugin URI: https://github.com/csmcneill/update-doctor
 * Description: Diagnoses why WordPress automatic updates aren't running. Inspects constants, filter callbacks, cron, filesystem, options, and per-item state, and produces a plain-language report you can hand to your host's support.
 * Version: 1.0.0
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Author: Chris McNeill
 * Author URI: https://csmcneill.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: update-doctor
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'UPDATE_DOCTOR_VERSION', '1.0.0' );
define( 'UPDATE_DOCTOR_FILE', __FILE__ );
define( 'UPDATE_DOCTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'UPDATE_DOCTOR_URL', plugin_dir_url( __FILE__ ) );

require_once UPDATE_DOCTOR_DIR . 'includes/class-diagnostic.php';
require_once UPDATE_DOCTOR_DIR . 'includes/class-check.php';
require_once UPDATE_DOCTOR_DIR . 'includes/class-hook-inspector.php';
require_once UPDATE_DOCTOR_DIR . 'includes/class-runner.php';
require_once UPDATE_DOCTOR_DIR . 'includes/class-admin-page.php';
require_once UPDATE_DOCTOR_DIR . 'includes/class-update-trigger.php';
require_once UPDATE_DOCTOR_DIR . 'includes/class-report-formatter.php';
require_once UPDATE_DOCTOR_DIR . 'includes/class-failure-monitor.php';
require_once UPDATE_DOCTOR_DIR . 'includes/class-settings.php';
require_once UPDATE_DOCTOR_DIR . 'includes/checks/class-constants-check.php';
require_once UPDATE_DOCTOR_DIR . 'includes/checks/class-filters-check.php';
require_once UPDATE_DOCTOR_DIR . 'includes/checks/class-cron-check.php';
require_once UPDATE_DOCTOR_DIR . 'includes/checks/class-filesystem-check.php';
require_once UPDATE_DOCTOR_DIR . 'includes/checks/class-options-check.php';
require_once UPDATE_DOCTOR_DIR . 'includes/checks/class-per-item-check.php';
require_once UPDATE_DOCTOR_DIR . 'includes/checks/class-string-scanner-check.php';
require_once UPDATE_DOCTOR_DIR . 'includes/checks/class-error-log-check.php';
require_once UPDATE_DOCTOR_DIR . 'includes/class-plugin.php';

Update_Doctor_Plugin::instance();

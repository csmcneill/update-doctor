<?php
/**
 * Abstract base class for diagnostic checks.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Update_Doctor_Check {

	/**
	 * Unique slug for the check (e.g. "constants").
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human-readable label for the check (e.g. "Constants").
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Run the check and return an array of Update_Doctor_Diagnostic objects.
	 *
	 * @return Update_Doctor_Diagnostic[]
	 */
	abstract public function run();

	/**
	 * Optional summary describing what the check looks for.
	 *
	 * @return string
	 */
	public function description() {
		return '';
	}
}

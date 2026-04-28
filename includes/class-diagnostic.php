<?php
/**
 * Value object representing a single diagnostic finding.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Diagnostic {

	const STATUS_PASS = 'pass';
	const STATUS_WARN = 'warn';
	const STATUS_FAIL = 'fail';
	const STATUS_INFO = 'info';

	public $status;
	public $title;
	public $message;
	public $details;
	public $source;

	public function __construct( $status, $title, $message = '', array $details = array(), $source = '' ) {
		$this->status  = in_array( $status, array( self::STATUS_PASS, self::STATUS_WARN, self::STATUS_FAIL, self::STATUS_INFO ), true ) ? $status : self::STATUS_INFO;
		$this->title   = (string) $title;
		$this->message = (string) $message;
		$this->details = $details;
		$this->source  = (string) $source;
	}

	public static function pass( $title, $message = '', array $details = array(), $source = '' ) {
		return new self( self::STATUS_PASS, $title, $message, $details, $source );
	}

	public static function warn( $title, $message = '', array $details = array(), $source = '' ) {
		return new self( self::STATUS_WARN, $title, $message, $details, $source );
	}

	public static function fail( $title, $message = '', array $details = array(), $source = '' ) {
		return new self( self::STATUS_FAIL, $title, $message, $details, $source );
	}

	public static function info( $title, $message = '', array $details = array(), $source = '' ) {
		return new self( self::STATUS_INFO, $title, $message, $details, $source );
	}

	public function status_label() {
		switch ( $this->status ) {
			case self::STATUS_PASS:
				return __( 'OK', 'update-doctor' );
			case self::STATUS_WARN:
				return __( 'Warning', 'update-doctor' );
			case self::STATUS_FAIL:
				return __( 'Issue', 'update-doctor' );
			default:
				return __( 'Info', 'update-doctor' );
		}
	}
}

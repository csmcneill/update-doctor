<?php
/**
 * Runs registered diagnostic checks.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Runner {

	/**
	 * @var Update_Doctor_Check[]
	 */
	private $checks = array();

	public function register( Update_Doctor_Check $check ) {
		$this->checks[ $check->id() ] = $check;
	}

	/**
	 * @return Update_Doctor_Check[]
	 */
	public function checks() {
		return $this->checks;
	}

	/**
	 * Run all registered checks.
	 *
	 * @return array Map of check id => array{ label: string, description: string, diagnostics: Update_Doctor_Diagnostic[] }
	 */
	public function run_all() {
		$results = array();

		foreach ( $this->checks as $check ) {
			$diagnostics = array();

			try {
				$diagnostics = $check->run();
			} catch ( \Throwable $e ) {
				$diagnostics = array(
					Update_Doctor_Diagnostic::warn(
						sprintf( __( '%s check failed to run', 'update-doctor' ), $check->label() ),
						$e->getMessage()
					),
				);
			}

			$results[ $check->id() ] = array(
				'label'       => $check->label(),
				'description' => $check->description(),
				'diagnostics' => $diagnostics,
			);
		}

		return $results;
	}

	/**
	 * Aggregate the worst status across all diagnostics.
	 *
	 * @param array $results Output of run_all().
	 * @return string fail|warn|pass
	 */
	public function overall_status( array $results ) {
		$has_fail = false;
		$has_warn = false;

		foreach ( $results as $section ) {
			foreach ( $section['diagnostics'] as $diagnostic ) {
				if ( $diagnostic->status === Update_Doctor_Diagnostic::STATUS_FAIL ) {
					$has_fail = true;
				} elseif ( $diagnostic->status === Update_Doctor_Diagnostic::STATUS_WARN ) {
					$has_warn = true;
				}
			}
		}

		if ( $has_fail ) {
			return Update_Doctor_Diagnostic::STATUS_FAIL;
		}

		if ( $has_warn ) {
			return Update_Doctor_Diagnostic::STATUS_WARN;
		}

		return Update_Doctor_Diagnostic::STATUS_PASS;
	}
}

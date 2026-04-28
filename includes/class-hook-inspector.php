<?php
/**
 * Inspects WordPress hook callbacks and resolves their source location via reflection.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Hook_Inspector {

	/**
	 * Returns information about every callback registered on a given filter or action.
	 *
	 * @param string $tag Filter or action name.
	 * @return array<int, array{priority:int, callback_label:string, file:string, line:int, suspicious:bool}>
	 */
	public function inspect( $tag ) {
		global $wp_filter;

		$entries = array();

		if ( ! isset( $wp_filter[ $tag ] ) ) {
			return $entries;
		}

		$hook = $wp_filter[ $tag ];

		// $hook may be a WP_Hook object (modern WP) or a raw array (very old WP).
		$callbacks = ( $hook instanceof WP_Hook ) ? $hook->callbacks : $hook;

		if ( ! is_array( $callbacks ) ) {
			return $entries;
		}

		foreach ( $callbacks as $priority => $registered ) {
			if ( ! is_array( $registered ) ) {
				continue;
			}
			foreach ( $registered as $entry ) {
				if ( ! isset( $entry['function'] ) ) {
					continue;
				}

				$resolved = $this->resolve_callable( $entry['function'] );

				$entries[] = array(
					'priority'       => (int) $priority,
					'callback_label' => $resolved['label'],
					'file'           => $resolved['file'],
					'line'           => $resolved['line'],
					'suspicious'     => $resolved['suspicious'],
				);
			}
		}

		return $entries;
	}

	/**
	 * Resolve a callable into a human label and (file, line) source location.
	 *
	 * @param mixed $callable
	 * @return array{label:string, file:string, line:int, suspicious:bool}
	 */
	private function resolve_callable( $callable ) {
		$label      = '';
		$file       = '';
		$line       = 0;
		$suspicious = false;

		try {
			if ( is_string( $callable ) ) {
				// Function name or "Class::method" string.
				$label = $callable;

				if ( in_array( $callable, array( '__return_false', '__return_zero', '__return_empty_array', '__return_empty_string', '__return_null' ), true ) ) {
					$suspicious = true;
				}

				if ( strpos( $callable, '::' ) !== false ) {
					list( $cls, $method ) = explode( '::', $callable, 2 );
					if ( class_exists( $cls ) && method_exists( $cls, $method ) ) {
						$ref  = new ReflectionMethod( $cls, $method );
						$file = (string) $ref->getFileName();
						$line = (int) $ref->getStartLine();
					}
				} elseif ( function_exists( $callable ) ) {
					$ref  = new ReflectionFunction( $callable );
					$file = (string) $ref->getFileName();
					$line = (int) $ref->getStartLine();
				}
			} elseif ( is_array( $callable ) && count( $callable ) === 2 ) {
				list( $obj_or_class, $method ) = $callable;

				if ( is_object( $obj_or_class ) ) {
					$cls   = get_class( $obj_or_class );
					$label = $cls . '->' . $method;
				} else {
					$cls   = (string) $obj_or_class;
					$label = $cls . '::' . $method;
				}

				if ( class_exists( $cls ) && method_exists( $cls, $method ) ) {
					$ref  = new ReflectionMethod( $cls, $method );
					$file = (string) $ref->getFileName();
					$line = (int) $ref->getStartLine();
				}
			} elseif ( $callable instanceof Closure ) {
				$ref   = new ReflectionFunction( $callable );
				$file  = (string) $ref->getFileName();
				$line  = (int) $ref->getStartLine();
				$label = 'Closure';
				$scope = $ref->getClosureScopeClass();
				if ( $scope ) {
					$label = 'Closure (' . $scope->getName() . ')';
				}
			} elseif ( is_object( $callable ) && method_exists( $callable, '__invoke' ) ) {
				$cls   = get_class( $callable );
				$label = $cls . '->__invoke';
				$ref   = new ReflectionMethod( $cls, '__invoke' );
				$file  = (string) $ref->getFileName();
				$line  = (int) $ref->getStartLine();
			} else {
				$label = __( 'unknown callable', 'update-doctor' );
			}
		} catch ( \Throwable $e ) {
			// Fall through to unresolved label.
			if ( '' === $label ) {
				$label = __( 'unresolvable callable', 'update-doctor' );
			}
		}

		// Trim file path to a path relative to wp-content for readability, if possible.
		$display_file = $file;
		if ( '' !== $file && defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $file, WP_CONTENT_DIR ) ) {
			$display_file = 'wp-content' . substr( $file, strlen( WP_CONTENT_DIR ) );
		} elseif ( '' !== $file && defined( 'ABSPATH' ) && 0 === strpos( $file, ABSPATH ) ) {
			$display_file = substr( $file, strlen( ABSPATH ) );
		}

		return array(
			'label'      => $label,
			'file'       => $display_file,
			'line'       => $line,
			'suspicious' => $suspicious,
		);
	}
}

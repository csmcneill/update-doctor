<?php
/**
 * Renders the Tools → Update Doctor admin page.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Admin_Page {

	const SLUG = 'update-doctor';

	/** @var Update_Doctor_Runner */
	private $runner;

	/** @var Update_Doctor_Update_Trigger */
	private $trigger;

	/** @var Update_Doctor_Report_Formatter */
	private $formatter;

	/** @var Update_Doctor_Settings */
	private $settings;

	public function __construct( Update_Doctor_Runner $runner, Update_Doctor_Update_Trigger $trigger, Update_Doctor_Report_Formatter $formatter, Update_Doctor_Settings $settings ) {
		$this->runner    = $runner;
		$this->trigger   = $trigger;
		$this->formatter = $formatter;
		$this->settings  = $settings;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_management_page(
			__( 'Update Doctor', 'update-doctor' ),
			__( 'Update Doctor', 'update-doctor' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'update-doctor-admin', UPDATE_DOCTOR_URL . 'assets/admin.css', array(), UPDATE_DOCTOR_VERSION );
		wp_enqueue_script( 'update-doctor-admin', UPDATE_DOCTOR_URL . 'assets/admin.js', array(), UPDATE_DOCTOR_VERSION, true );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'update-doctor' ) );
		}

		$results          = $this->runner->run_all();
		$overall          = $this->runner->overall_status( $results );
		$report           = $this->formatter->format( $results );
		$last_run_payload = $this->trigger->last_run_payload();
		?>
		<div class="wrap update-doctor-wrap">
			<h1><?php esc_html_e( 'Update Doctor', 'update-doctor' ); ?></h1>
			<p class="update-doctor-intro">
				<?php esc_html_e( "Diagnoses why automatic updates aren't running on this site. Findings below show each layer of WordPress's update decision and which (if any) is blocking updates.", 'update-doctor' ); ?>
			</p>

			<?php $this->render_overall_banner( $overall ); ?>

			<div class="update-doctor-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="<?php echo esc_attr( Update_Doctor_Update_Trigger::ACTION ); ?>" />
					<?php wp_nonce_field( Update_Doctor_Update_Trigger::ACTION, Update_Doctor_Update_Trigger::NONCE ); ?>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Run Background Update Now', 'update-doctor' ); ?>
					</button>
				</form>

				<button type="button" class="button" id="update-doctor-copy-report"
					data-report="<?php echo esc_attr( $report ); ?>">
					<?php esc_html_e( 'Copy Markdown Report', 'update-doctor' ); ?>
				</button>

				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ); ?>" class="button">
					<?php esc_html_e( 'Re-run Diagnostics', 'update-doctor' ); ?>
				</a>
			</div>

			<?php if ( ! empty( $_GET['doctor_run'] ) && $last_run_payload ) : ?>
				<?php $this->render_last_run( $last_run_payload ); ?>
			<?php endif; ?>

			<div class="update-doctor-sections">
				<?php foreach ( $results as $section_id => $section ) : ?>
					<?php $this->render_section( $section_id, $section ); ?>
				<?php endforeach; ?>
			</div>

			<div class="update-doctor-section">
				<h2><?php esc_html_e( 'Email Notifications', 'update-doctor' ); ?></h2>
				<?php $this->settings->render_form(); ?>
			</div>

			<details class="update-doctor-raw-report">
				<summary><?php esc_html_e( 'View raw Markdown report', 'update-doctor' ); ?></summary>
				<textarea readonly rows="20" class="large-text code"><?php echo esc_textarea( $report ); ?></textarea>
			</details>
		</div>
		<?php
	}

	private function render_overall_banner( $status ) {
		switch ( $status ) {
			case Update_Doctor_Diagnostic::STATUS_FAIL:
				$class   = 'notice notice-error';
				$message = __( 'One or more issues are likely preventing automatic updates. See the affected sections below.', 'update-doctor' );
				break;
			case Update_Doctor_Diagnostic::STATUS_WARN:
				$class   = 'notice notice-warning';
				$message = __( 'No outright failures, but at least one finding is worth a closer look.', 'update-doctor' );
				break;
			default:
				$class   = 'notice notice-success';
				$message = __( 'No blockers detected. Automatic updates should be running normally on this site.', 'update-doctor' );
		}
		printf( '<div class="%s update-doctor-overall"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	private function render_last_run( array $payload ) {
		?>
		<div class="notice notice-info update-doctor-last-run">
			<h3><?php esc_html_e( 'Last manual run', 'update-doctor' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %s: human-readable elapsed time */
					esc_html__( 'Triggered %s ago.', 'update-doctor' ),
					esc_html( human_time_diff( (int) $payload['time'], time() ) )
				);
				?>
			</p>

			<?php if ( ! empty( $payload['results'] ) ) : ?>
				<h4><?php esc_html_e( 'Updater results', 'update-doctor' ); ?></h4>
				<pre class="update-doctor-pre"><?php echo esc_html( $this->summarise_results( $payload['results'] ) ); ?></pre>
			<?php else : ?>
				<p><?php esc_html_e( 'The updater ran with no items to process.', 'update-doctor' ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $payload['errors'] ) ) : ?>
				<h4><?php esc_html_e( 'Captured PHP errors', 'update-doctor' ); ?></h4>
				<pre class="update-doctor-pre"><?php echo esc_html( $this->summarise_errors( $payload['errors'] ) ); ?></pre>
			<?php endif; ?>

			<?php if ( ! empty( $payload['output'] ) ) : ?>
				<h4><?php esc_html_e( 'Captured output', 'update-doctor' ); ?></h4>
				<pre class="update-doctor-pre"><?php echo esc_html( $payload['output'] ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	private function summarise_results( $results ) {
		$out = '';
		foreach ( (array) $results as $type => $entries ) {
			$out .= sprintf( "[%s]\n", $type );
			foreach ( (array) $entries as $entry ) {
				$status = '?';
				if ( isset( $entry->result ) ) {
					if ( true === $entry->result ) {
						$status = 'success';
					} elseif ( is_wp_error( $entry->result ) ) {
						$status = 'error: ' . $entry->result->get_error_message();
					} else {
						$status = 'failed';
					}
				}
				$name = '';
				if ( isset( $entry->item ) ) {
					if ( isset( $entry->item->plugin ) ) {
						$name = $entry->item->plugin;
					} elseif ( isset( $entry->item->theme ) ) {
						$name = $entry->item->theme;
					} elseif ( isset( $entry->item->slug ) ) {
						$name = $entry->item->slug;
					}
				}
				$out .= sprintf( "  - %s: %s\n", $name ?: '(unknown)', $status );
			}
		}
		return $out ?: '(no results)';
	}

	private function summarise_errors( $errors ) {
		$out = '';
		foreach ( (array) $errors as $err ) {
			$out .= sprintf(
				"[%s] %s in %s:%d\n",
				$err['severity'],
				$err['message'],
				$err['file'],
				(int) $err['line']
			);
		}
		return $out;
	}

	private function render_section( $section_id, array $section ) {
		?>
		<div class="update-doctor-section update-doctor-section--<?php echo esc_attr( $section_id ); ?>">
			<h2><?php echo esc_html( $section['label'] ); ?></h2>
			<?php if ( ! empty( $section['description'] ) ) : ?>
				<p class="description"><?php echo esc_html( $section['description'] ); ?></p>
			<?php endif; ?>

			<ul class="update-doctor-diagnostics">
				<?php foreach ( $section['diagnostics'] as $diagnostic ) : ?>
					<li class="update-doctor-diagnostic update-doctor-diagnostic--<?php echo esc_attr( $diagnostic->status ); ?>">
						<span class="update-doctor-status-pill update-doctor-status-pill--<?php echo esc_attr( $diagnostic->status ); ?>">
							<?php echo esc_html( $diagnostic->status_label() ); ?>
						</span>
						<strong class="update-doctor-diagnostic-title"><?php echo esc_html( $diagnostic->title ); ?></strong>
						<?php if ( $diagnostic->message ) : ?>
							<div class="update-doctor-diagnostic-message"><?php echo esc_html( $diagnostic->message ); ?></div>
						<?php endif; ?>
						<?php if ( ! empty( $diagnostic->details ) ) : ?>
							<ul class="update-doctor-diagnostic-details">
								<?php foreach ( $diagnostic->details as $key => $value ) : ?>
									<li>
										<?php if ( ! is_int( $key ) ) : ?>
											<em><?php echo esc_html( $key ); ?>:</em>
										<?php endif; ?>
										<?php
										if ( is_scalar( $value ) ) {
											echo esc_html( (string) $value );
										} else {
											echo esc_html( wp_json_encode( $value ) );
										}
										?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}
}

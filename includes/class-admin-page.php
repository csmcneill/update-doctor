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
			<?php $this->render_live_test_banner( $last_run_payload ); ?>

			<div class="update-doctor-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="<?php echo esc_attr( Update_Doctor_Update_Trigger::ACTION ); ?>" />
					<?php wp_nonce_field( Update_Doctor_Update_Trigger::ACTION, Update_Doctor_Update_Trigger::NONCE ); ?>
					<button type="submit" class="button button-primary button-hero">
						<?php esc_html_e( 'Run Live Update Test', 'update-doctor' ); ?>
					</button>
				</form>

				<a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ); ?>" class="button">
					<?php esc_html_e( 'Re-run Diagnostics', 'update-doctor' ); ?>
				</a>

				<button type="button" class="button" id="update-doctor-copy-report"
					data-report="<?php echo esc_attr( $report ); ?>">
					<?php esc_html_e( 'Copy Markdown Report', 'update-doctor' ); ?>
				</button>
			</div>

			<p class="update-doctor-actions-help description">
				<?php esc_html_e( '"Run Live Update Test" calls WordPress\'s automatic-update process directly, applies any pending updates, and captures the output (including PHP errors) into the diagnostic. This is the most reliable way to determine whether updates can actually run on this site.', 'update-doctor' ); ?>
			</p>

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

	/**
	 * Show an actionable banner at the top of the page when pending updates exist but
	 * no recent live test has been captured. The Last Run check also surfaces this in
	 * the body, but the banner makes it impossible to miss.
	 */
	private function render_live_test_banner( $last_run_payload ) {
		$pending = $this->pending_count();
		if ( $pending === 0 ) {
			return;
		}

		$has_recent_run = is_array( $last_run_payload ) && ! empty( $last_run_payload['time'] ) && ( time() - (int) $last_run_payload['time'] < HOUR_IN_SECONDS );
		if ( $has_recent_run ) {
			return;
		}

		?>
		<div class="notice notice-info update-doctor-live-test-banner">
			<p>
				<strong><?php esc_html_e( 'For the most complete diagnosis: run a live update test.', 'update-doctor' ); ?></strong>
				<?php
				printf(
					/* translators: %d: number of pending updates */
					esc_html( _n(
						'%d update is pending on this site, and Update Doctor has no record of a recent live update attempt. Click "Run Live Update Test" above to invoke WordPress\'s update process and capture exactly what happens — output, errors, and result for each item.',
						'%d updates are pending on this site, and Update Doctor has no record of a recent live update attempt. Click "Run Live Update Test" above to invoke WordPress\'s update process and capture exactly what happens — output, errors, and result for each item.',
						$pending,
						'update-doctor'
					) ),
					$pending
				);
				?>
			</p>
		</div>
		<?php
	}

	private function pending_count() {
		$count = 0;
		$pt = get_site_transient( 'update_plugins' );
		if ( $pt && isset( $pt->response ) && is_array( $pt->response ) ) {
			$count += count( $pt->response );
		}
		$tt = get_site_transient( 'update_themes' );
		if ( $tt && isset( $tt->response ) && is_array( $tt->response ) ) {
			$count += count( $tt->response );
		}
		$ct = get_site_transient( 'update_core' );
		if ( $ct && isset( $ct->updates ) && is_array( $ct->updates ) ) {
			foreach ( $ct->updates as $update ) {
				if ( isset( $update->response ) && in_array( $update->response, array( 'upgrade', 'autoupdate' ), true ) ) {
					$count++;
				}
			}
		}
		return $count;
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

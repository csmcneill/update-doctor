<?php
/**
 * Settings storage and rendering for the opt-in failure-notification email.
 *
 * @package Update_Doctor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Update_Doctor_Settings {

	const OPTION_GROUP    = 'update_doctor_settings';
	const ENABLED_OPTION  = 'update_doctor_notify_enabled';
	const RECIPIENT_OPTION = 'update_doctor_notify_recipient';

	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::RECIPIENT_OPTION,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_email' ),
			)
		);
	}

	public function sanitize_bool( $value ) {
		return ! empty( $value );
	}

	public function sanitize_email( $value ) {
		$value = is_string( $value ) ? sanitize_email( trim( $value ) ) : '';
		return $value;
	}

	public function notifications_enabled() {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	public function recipient() {
		$configured = get_option( self::RECIPIENT_OPTION, '' );
		if ( $configured && is_email( $configured ) ) {
			return $configured;
		}
		return get_option( 'admin_email' );
	}

	public function render_form() {
		$enabled   = $this->notifications_enabled();
		$recipient = (string) get_option( self::RECIPIENT_OPTION, '' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="update-doctor-settings">
			<?php settings_fields( self::OPTION_GROUP ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row">
							<label for="update_doctor_notify_enabled"><?php esc_html_e( 'Email notifications', 'update-doctor' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::ENABLED_OPTION ); ?>" id="update_doctor_notify_enabled" value="1" <?php checked( $enabled ); ?> />
								<?php esc_html_e( 'Email me when an automatic update fails or is silently skipped.', 'update-doctor' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Disabled by default. When enabled, Update Doctor sends at most one email per 24 hours, regardless of how many failures occur. The email tells you to visit Tools → Update Doctor for details — it does not include specifics about your site.', 'update-doctor' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="update_doctor_notify_recipient"><?php esc_html_e( 'Recipient', 'update-doctor' ); ?></label>
						</th>
						<td>
							<input type="email" class="regular-text" name="<?php echo esc_attr( self::RECIPIENT_OPTION ); ?>" id="update_doctor_notify_recipient" value="<?php echo esc_attr( $recipient ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
							<p class="description">
								<?php esc_html_e( "Leave blank to use your site's admin email.", 'update-doctor' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Save notification settings', 'update-doctor' ) ); ?>
		</form>
		<?php
	}
}

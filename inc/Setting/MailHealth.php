<?php
namespace WPDevAssist\Setting;

use WPDevAssist\Asset;
use WPDevAssist\AdminNotice;
use WPDevAssist\MailHealth\Store;
use WPDevAssist\MailHealth\State;
use WPDevAssist\MailHealth\Scheduler;

defined( 'ABSPATH' ) || exit;

/** Shared settings page; monitoring and status updates are automatic. */
class MailHealth extends Page {
	public const KEY        = 'wp_dev_assist_mail_health';
	public const PAGE       = self::KEY;
	public const ENABLE_KEY = self::KEY . '_enabled';
	protected Store $store;
	protected Scheduler $scheduler;
	protected Control $control;

	public function __construct( Store $store, Scheduler $scheduler, Asset $asset, AdminNotice $admin_notice ) {
		$this->store     = $store;
		$this->scheduler = $scheduler;
		$this->control   = new Control();
		parent::__construct( $asset, $admin_notice );
		// The Settings API field is a projection of the atomic monitoring store.
		add_filter( 'pre_option_' . self::ENABLE_KEY, array( $this, 'enabled_value' ) );
		add_action( 'wp_ajax_' . self::KEY . '_status', array( $this, 'status' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function enabled_value(): string {
		return $this->store->read()['config']['enabled'] ? 'yes' : 'no';
	}

	public function url(): string {
		return $this->get_page_url();
	}

	protected function add_page(): callable {
		return function (): void {
			add_submenu_page( \WPDevAssist\KEY, __( 'Mail Health', 'development-assistant' ), __( 'Mail Health', 'development-assistant' ), 'manage_options', self::KEY, $this->render_page() );
		};
	}

	protected function add_sections(): callable {
		return function (): void {
			$section = self::KEY . '_monitoring';
			$this->add_section( $section, __( 'Monitoring', 'development-assistant' ) );
			$this->add_setting(
				$section,
				self::ENABLE_KEY,
				__( 'Enable mail monitoring', 'development-assistant' ),
				array( $this->control, 'render_checkbox' ),
				'no',
				array( 'description' => __( 'Checks run automatically using the site mail settings. Test emails are sent to the site administrator.', 'development-assistant' ) ),
				array( $this, 'sanitize_enabled' )
			);
			$this->add_control( $section, self::KEY . '_status', __( 'Status', 'development-assistant' ), array( $this, 'render_status' ) );
		};
	}

	public function sanitize_enabled( $value ): string {
		if ( ! $this->set_enabled( 'yes' === $value ) ) {
			add_settings_error( self::KEY, 'mail_health_save', __( 'The change could not be saved. Please try again.', 'development-assistant' ), 'error' );
		}
		return $this->enabled_value();
	}

	public function set_enabled( bool $enabled ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		$before = $this->store->read()['generation'];
		if ( ! $this->store->configure( $enabled ) ) {
			return false;
		}
		if ( $before !== $this->store->read()['generation'] ) {
			$this->scheduler->clear();
		}
		$this->scheduler->ensure();
		return true;
	}

	public function status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( self::PAGE . '_status', 'nonce' );
		wp_send_json_success( $this->response() );
	}

	protected function response(): array {
		return array(
			'enabled' => $this->store->read()['config']['enabled'],
			'html'    => $this->status_html(),
		);
	}

	protected function message( string $summary ): array {
		$messages = array(
			'off'       => array( __( 'Monitoring is off', 'development-assistant' ), '', '' ),
			'checking'  => array( __( 'Checking email sending…', 'development-assistant' ), __( 'The first background check will run automatically.', 'development-assistant' ), '' ),
			'ok'        => array( __( 'Email sending works', 'development-assistant' ), '', '' ),
			'failed'    => array( __( 'Email sending failed', 'development-assistant' ), '', '' ),
			'degraded'  => array( __( 'Email sending needs attention', 'development-assistant' ), __( 'A backup connection sent the email, but the primary connection reported a problem.', 'development-assistant' ), '' ),
			'unknown'   => array( __( 'Email sending could not be confirmed', 'development-assistant' ), __( 'The configured mailer did not provide a verifiable sending result. A queued message is not yet a completed send.', 'development-assistant' ), __( 'Review the mail plugin queue and sending logs. Monitoring will continue automatically.', 'development-assistant' ) ),
			'stale'     => array( __( 'The last result is out of date', 'development-assistant' ), __( 'There is no current result confirming that the site can send email.', 'development-assistant' ), __( 'Check that WordPress scheduled tasks can run. A fresh automatic check will update this status.', 'development-assistant' ) ),
			'delayed'   => array( __( 'Automatic checks are delayed', 'development-assistant' ), __( 'The background check has not started or completed within its expected time. Mail service availability is still unknown.', 'development-assistant' ), __( 'Check WordPress scheduled tasks or the hosting cron runner. The pending check can complete when the runner resumes.', 'development-assistant' ) ),
			'recipient' => array( __( 'The site administrator email is invalid', 'development-assistant' ), __( 'The automatic test has no valid recipient address.', 'development-assistant' ), __( 'Update the Administration Email Address in WordPress Settings → General. Checks will resume automatically.', 'development-assistant' ) ),
		);
		return $messages[ $summary ] ?? $messages['unknown'];
	}

	/** Fixed diagnoses and recovery guidance; never render raw transport responses. */
	protected function diagnosis( string $category, array $diagnostic ): array {
		$messages = array(
			'invalid_sender' => array( __( 'Invalid sender address', 'development-assistant' ), __( 'The configured From address is invalid. WordPress stopped the email before handing it to the mail service.', 'development-assistant' ), __( 'Set a valid From address in your SMTP plugin or site mail configuration.', 'development-assistant' ) ),
			'recipient'      => array( __( 'Recipient address could not be used', 'development-assistant' ), __( 'A recipient address was invalid or rejected, so the affected email could not be sent.', 'development-assistant' ), __( 'Check the Administration Email Address in WordPress Settings → General and any plugins that change email recipients.', 'development-assistant' ) ),
			'message'        => array( __( 'Email or attachment could not be processed', 'development-assistant' ), __( 'An email content, attachment, or encoding error prevented sending.', 'development-assistant' ), __( 'Check the plugin that generated the message and verify that its attachments are accessible.', 'development-assistant' ) ),
			'quota'          => array( __( 'Mail sending quota reached', 'development-assistant' ), __( 'The mail service reported an exhausted sending quota.', 'development-assistant' ), __( 'Review the provider dashboard for the sending allowance and its reset time. Sending can resume when quota is available.', 'development-assistant' ) ),
			'throttled'      => array( __( 'Mail sending temporarily limited', 'development-assistant' ), __( 'The mail service is limiting sending requests.', 'development-assistant' ), __( 'Wait for the automatic retry. If this repeats, review the provider rate limits and the site sending volume.', 'development-assistant' ) ),
			'authentication' => array( __( 'Mail authentication failed', 'development-assistant' ), __( 'The configured mail account could not authenticate, so sending did not complete.', 'development-assistant' ), __( 'Verify the SMTP credentials or reconnect the mail provider OAuth account in your mail plugin.', 'development-assistant' ) ),
			'tls'            => array( __( 'Secure mail connection failed', 'development-assistant' ), __( 'A TLS or certificate problem prevented a secure mail connection.', 'development-assistant' ), __( 'Verify the SMTP host, port, encryption mode, and server certificate with the mail provider or host.', 'development-assistant' ) ),
			'dns'            => array( __( 'Mail server name could not be resolved', 'development-assistant' ), __( 'The site could not resolve the configured mail server hostname.', 'development-assistant' ), __( 'Verify the mail server hostname and the hosting server DNS configuration.', 'development-assistant' ) ),
			'timeout'        => array( __( 'Mail service response timed out', 'development-assistant' ), __( 'Sending did not complete within the transport time limit.', 'development-assistant' ), __( 'Check mail service availability and the hosting network. Automatic checks will continue.', 'development-assistant' ) ),
			'connection'     => array( __( 'Could not connect to the mail service', 'development-assistant' ), __( 'The send attempt failed while connecting to the mail service.', 'development-assistant' ), __( 'Check the SMTP host and port, mail service availability, and outbound firewall restrictions with your host.', 'development-assistant' ) ),
			'sender'         => array( __( 'Sender or relay problem', 'development-assistant' ), __( 'The configured sender address could not be used or was rejected during sending.', 'development-assistant' ), __( 'Check the From address and verify that the mail account is allowed to send for that domain.', 'development-assistant' ) ),
			'unknown'        => array( __( 'Email sending failed', 'development-assistant' ), __( 'The email was not sent, but the available evidence does not identify the cause.', 'development-assistant' ), __( 'Review your mail plugin diagnostics or contact your hosting provider to identify the cause.', 'development-assistant' ) ),
		);
		$message  = $messages[ $category ] ?? $messages['unknown'];
		$sender   = State::safe_sender( $diagnostic['sender'] ?? '' );
		if ( 'invalid_sender' === $category && $sender ) {
			$message[1] = sprintf( __( 'The From address %s is invalid. WordPress stopped the email before handing it to the mail service.', 'development-assistant' ), $sender );
			if ( 'localhost' === wp_parse_url( home_url(), PHP_URL_HOST ) && '@localhost' === substr( strtolower( $sender ), -10 ) ) {
				$message[2] .= ' ' . __( 'For local development, use a valid sender such as wordpress@example.test. A local mail catcher only receives messages after WordPress submits them.', 'development-assistant' );
			}
		}
		return $message;
	}

	protected function failure( array $state ): array {
		$category   = $state['error'] ? $state['error'] : ( $state['attempt']['error'] ?? 'unknown' );
		$diagnostic = $state['error'] ? $state['diagnostic'] : ( $state['attempt']['diagnostic'] ?? array() );
		return $this->diagnosis( $category, is_array( $diagnostic ) ? $diagnostic : array() );
	}

	protected function status_message( array $state, string $summary ): array {
		if ( 'failed' === $summary ) {
			return $this->failure( $state );
		}
		$message = $this->message( $summary );
		if ( 'degraded' === $summary ) {
			$primary     = $this->failure( $state );
			$message[1] .= ' ' . $primary[1];
			$message[2]  = $primary[2];
		}
		return $message;
	}

	public function status_html(): string {
		$state   = $this->store->read();
		$summary = State::summary( $state, $this->store->now() );
		$message = $this->status_message( $state, $summary );
		$checked = max( $state['checked_at'], $state['observed_at'] );
		ob_start();
		?>
		<?php if ( $message[1] ) { ?>
			<p><?php echo esc_html( $message[1] ); ?></p>
		<?php } ?>
		<?php if ( $message[2] ) { ?>
			<p><strong><?php esc_html_e( 'What to check:', 'development-assistant' ); ?></strong> <?php echo esc_html( $message[2] ); ?></p>
		<?php } ?>
		<?php if ( 'off' !== $summary && $checked ) { ?>
			<p><?php echo esc_html( sprintf( __( 'Last checked: %s', 'development-assistant' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $checked ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) ); ?></p>
		<?php } ?>
		<?php
		$description = (string) ob_get_clean();
		ob_start();
		$this->control->render_status(
			array(
				'is_success'     => 'ok' === $summary,
				'success_title'  => esc_html( $message[0] ),
				'failure_title'  => esc_html( $message[0] ),
				'disabled'       => in_array( $summary, array( 'off', 'checking' ), true ),
				'disabled_title' => esc_html( $message[0] ),
				'warning'        => in_array( $summary, array( 'degraded', 'unknown', 'stale', 'delayed' ), true ),
				'description'    => $description,
			)
		);
		return (string) ob_get_clean();
	}

	public function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ( isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$state   = $this->store->read();
		$summary = State::summary( $state, $this->store->now() );
		if ( ! in_array( $summary, array( 'failed', 'degraded', 'stale', 'delayed', 'recipient', 'unknown' ), true ) ) {
			return;
		}
		$message = $this->status_message( $state, $summary );
		$error   = in_array( $summary, array( 'failed', 'degraded' ), true );
		?>
		<div class="notice <?php echo $error ? 'notice-error' : 'notice-warning'; ?>"><p>
			<?php echo esc_html( $message[0] . ( $message[2] ? '. ' . $message[2] : '' ) ); ?>
			<a href="<?php echo esc_url( $this->url() ); ?>"><?php esc_html_e( 'Mail Health', 'development-assistant' ); ?></a>
		</p></div>
		<?php
	}

	public function render_status(): void {
		$status_url = add_query_arg(
			array(
				'action' => self::KEY . '_status',
				'nonce'  => wp_create_nonce( self::KEY . '_status' ),
			),
			admin_url( 'admin-ajax.php' )
		);
		?>
		<div data-mail-health-status data-status-url="<?php echo esc_url( $status_url ); ?>">
			<div id="da-mail-health-status" role="status" aria-live="polite"><?php echo $this->status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated escaped markup only. ?></div>
			<p data-status-error role="status" hidden><?php esc_html_e( 'Status could not be refreshed. Reconnecting…', 'development-assistant' ); ?></p>
		</div>
		<p class="description"><?php esc_html_e( 'A successful send confirms acceptance by the mail service. Delivery to Inbox cannot be verified automatically.', 'development-assistant' ); ?></p>
		<?php
	}

	protected function render_content(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view Mail Health.', 'development-assistant' ), '', array( 'response' => 403 ) );
		}
		$this->store->sync_identity();
		parent::render_content();
	}
}

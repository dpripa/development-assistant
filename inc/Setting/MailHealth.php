<?php
namespace WPDevAssist\Setting;

use WPDevAssist\Asset;
use WPDevAssist\MailHealth\Store;
use WPDevAssist\MailHealth\State;
use WPDevAssist\MailHealth\Scheduler;

defined( 'ABSPATH' ) || exit;

/** One switch; monitoring, test recipient, and status updates are automatic. */
class MailHealth {
	public const PAGE = 'wp_dev_assist_mail_health';
	protected Store $store;
	protected Scheduler $scheduler;
	protected Asset $asset;

	public function __construct( Store $store, Scheduler $scheduler, Asset $asset ) {
		$this->store     = $store;
		$this->scheduler = $scheduler;
		$this->asset     = $asset;
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::PAGE, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . self::PAGE . '_toggle', array( $this, 'toggle' ) );
		add_action( 'wp_ajax_' . self::PAGE . '_status', array( $this, 'status' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function url(): string {
		return add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
	}

	public function menu(): void {
		add_submenu_page( \WPDevAssist\KEY, __( 'Mail health', 'development-assistant' ), __( 'Mail health', 'development-assistant' ), 'manage_options', self::PAGE, array( $this, 'render' ) );
	}

	public function enqueue(): void {
		if ( current_user_can( 'manage_options' ) && isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->asset->enqueue_script( 'setting' );
		}
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

	protected function require_post(): void {
		if ( 'post' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change mail health settings.', 'development-assistant' ), '', array( 'response' => 403 ) );
		}
	}

	public function handle(): void {
		$this->require_post();
		check_admin_referer( self::PAGE . '_toggle' );
		$enabled = isset( $_POST['enabled'] ) && 'yes' === $_POST['enabled'];
		if ( ! $this->set_enabled( $enabled ) ) {
			wp_die( esc_html__( 'The change could not be saved. Please try again.', 'development-assistant' ), '', array( 'response' => 503 ) );
		}
		wp_safe_redirect( $this->url() );
		exit;
	}

	public function toggle(): void {
		$this->require_post();
		check_ajax_referer( self::PAGE . '_toggle', '_wpnonce' );
		$enabled = isset( $_POST['enabled'] ) && 'yes' === $_POST['enabled'];
		if ( ! $this->set_enabled( $enabled ) ) {
			wp_send_json_error( null, 503 );
		}
		wp_send_json_success( $this->response() );
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
		<p><strong><?php echo esc_html( $message[0] ); ?></strong></p>
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
			<a href="<?php echo esc_url( $this->url() ); ?>"><?php esc_html_e( 'Mail health', 'development-assistant' ); ?></a>
		</p></div>
		<?php
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to view mail health.', 'development-assistant' ), '', array( 'response' => 403 ) );
		}
		$this->store->sync_identity();
		$state      = $this->store->read();
		$status_url = add_query_arg(
			array(
				'action' => self::PAGE . '_status',
				'nonce'  => wp_create_nonce( self::PAGE . '_status' ),
			),
			admin_url( 'admin-ajax.php' )
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mail health', 'development-assistant' ); ?></h1>
			<form class="da-mail-health-toggle" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-save-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-toggle-action="<?php echo esc_attr( self::PAGE . '_toggle' ); ?>" data-status-url="<?php echo esc_url( $status_url ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php wp_nonce_field( self::PAGE . '_toggle' ); ?>
				<p><label><input type="checkbox" name="enabled" value="yes" <?php checked( $state['config']['enabled'] ); ?>> <?php esc_html_e( 'Enable mail monitoring', 'development-assistant' ); ?></label></p>
				<p class="description"><?php esc_html_e( 'Checks run automatically using the site mail settings. Test emails are sent to the site administrator.', 'development-assistant' ); ?></p>
				<noscript><?php submit_button( __( 'Save', 'development-assistant' ), 'secondary', 'mail_health_save' ); ?></noscript>
				<p data-save-error role="alert" hidden><?php esc_html_e( 'The change could not be confirmed. Check your connection and try again.', 'development-assistant' ); ?></p>
				<p data-status-error role="status" hidden><?php esc_html_e( 'Status could not be refreshed. Reconnecting…', 'development-assistant' ); ?></p>
			</form>
			<h2><?php esc_html_e( 'Status', 'development-assistant' ); ?></h2>
			<div id="da-mail-health-status" role="status" aria-live="polite"><?php echo $this->status_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Generated escaped markup only. ?></div>
			<p class="description"><?php esc_html_e( 'A successful send confirms acceptance by the mail service. Delivery to Inbox cannot be verified automatically.', 'development-assistant' ); ?></p>
		</div>
		<?php
	}
}

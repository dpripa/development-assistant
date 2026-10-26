<?php
namespace WPDevAssist\MailHealth;

defined( 'ABSPATH' ) || exit;

// PHPMailer's public API uses capitalized property names.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/** Observe without retaining message data or changing ordinary sends. */
class Monitor {
	protected Store $store;
	protected array $contexts = array();
	protected array $results  = array();
	protected string $probe   = '';
	protected array $timeouts = array();

	public function __construct( Store $store ) {
		$this->store = $store;
		add_filter( 'wp_mail', array( $this, 'start' ), PHP_INT_MAX );
		add_filter( 'pre_wp_mail', array( $this, 'short_circuit' ), PHP_INT_MAX, 2 );
		add_action( 'phpmailer_init', array( $this, 'mailer' ), PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', array( $this, 'succeeded' ), PHP_INT_MAX );
		add_action( 'wp_mail_failed', array( $this, 'failed' ), PHP_INT_MAX );
		add_action( 'wp_mail_smtp_mailcatcher_send_failed', array( $this, 'wp_mail_smtp_failed' ), PHP_INT_MAX, 2 );
		add_action( 'wp_mail_smtp_mailcatcher_smtp_send_after', array( $this, 'wp_mail_smtp_sent' ), PHP_INT_MAX );
	}

	public function begin_probe( string $id ): void {
		$this->probe = $id;
	}

	public function end_probe( string $id ): array {
		$result = $this->results[ $id ] ?? array(
			'status'      => 'unobservable',
			'error'       => '',
			'route'       => '',
			'transport'   => 'unknown',
			'retry_after' => 0,
			'diagnostic'  => array(),
		);
		unset( $this->results[ $id ] );
		foreach ( $this->timeouts as $saved ) {
			$saved[0]->Timeout                      = $saved[1];
			$saved[0]->getSMTPInstance()->Timelimit = $saved[2];
			$saved[0]->getSMTPInstance()->Timeout   = $saved[3];
		}
		$this->timeouts = array();
		$this->contexts = array_values(
			array_filter(
				$this->contexts,
				static function ( array $context ) use ( $id ): bool {
					return $id !== $context['id'];
				}
			)
		);
		$this->probe    = '';
		return $result;
	}

	protected function key( array $data ): string {
		return hash( 'sha256', (string) ( $data['subject'] ?? '' ) . "\0" . (string) ( $data['message'] ?? '' ) );
	}

	protected function test_id( array $data ): string {
		$headers = $data['headers'] ?? array();
		foreach ( is_array( $headers ) ? $headers : explode( "\n", $headers ) as $name => $value ) {
			$line = is_string( $name ) ? $name . ': ' . $value : $value;
			if ( preg_match( '/^X-Development-Assistant-Test:\s*([a-f0-9-]{36})\s*$/i', trim( $line ), $match ) ) {
				return $match[1];
			}
		}
		return '';
	}

	public function start( array $data ): array {
		$this->store->sync_identity();
		if ( ! $this->store->read()['config']['enabled'] ) {
			return $data;
		}
		$this->contexts[] = array(
			'key'        => $this->key( $data ),
			'id'         => $this->test_id( $data ),
			'blog'       => get_current_blog_id(),
			'generation' => $this->store->read()['generation'],
			'route'      => '',
			'transport'  => 'unknown',
			'observable' => false,
		);
		// Missing hooks/custom wp_mail implementations must not grow request memory forever.
		$this->contexts = array_slice( $this->contexts, -50 );
		return $data;
	}

	public function short_circuit( $preempted, array $data ) {
		if ( null !== $preempted ) {
			$this->complete( $data, 'unobservable', '' );
		}
		return $preempted;
	}

	public function mailer( $mailer ): void {
		$key = $this->key(
			array(
				'subject' => $mailer->Subject,
				'message' => $mailer->Body,
			)
		);
		for ( $index = count( $this->contexts ) - 1; $index >= 0; --$index ) {
			if ( $this->contexts[ $index ]['key'] !== $key || get_current_blog_id() !== $this->contexts[ $index ]['blog'] ) {
				continue;
			}
			$transport = in_array( $mailer->Mailer, array( 'smtp', 'mail', 'sendmail', 'qmail' ), true ) ? $mailer->Mailer : 'unknown';
			$core      = in_array( get_class( $mailer ), array( 'WP_PHPMailer', 'PHPMailer\\PHPMailer\\PHPMailer', 'PHPMailer' ), true );
			// Replaced send() implementations may queue, suppress, or use API/fallback routes.
			$this->contexts[ $index ]['observable'] = $core;
			$this->contexts[ $index ]['transport']  = $core ? $transport : 'unknown';
			$this->contexts[ $index ]['route']      = hash( 'sha256', wp_json_encode( array( get_class( $mailer ), $transport, $mailer->Host, $mailer->Port, $mailer->SMTPSecure, $mailer->From ) ) );
			if ( $this->probe && $this->probe === $this->contexts[ $index ]['id'] && 'smtp' === $transport ) {
				$this->timeouts[]                     = array( $mailer, $mailer->Timeout, $mailer->getSMTPInstance()->Timelimit, $mailer->getSMTPInstance()->Timeout );
				$timeout                              = State::TIMEOUT;
				$mailer->Timeout                      = min( max( 1, $mailer->Timeout ), $timeout );
				$mailer->getSMTPInstance()->Timelimit = min( max( 1, $mailer->getSMTPInstance()->Timelimit ), $timeout );
			}
			break;
		}
	}

	/** Retain an observed primary failure if a backup connection later succeeds. */
	public function wp_mail_smtp_failed( string $message, $mailer ): void {
		if ( ! is_a( $mailer, 'WPMailSMTP\\MailCatcherInterface' ) ) {
			return;
		}
		$key = $this->key(
			array(
				'subject' => $mailer->Subject,
				'message' => $mailer->Body,
			)
		);
		for ( $index = count( $this->contexts ) - 1; $index >= 0; --$index ) {
			if ( $key === $this->contexts[ $index ]['key'] && get_current_blog_id() === $this->contexts[ $index ]['blog'] ) {
				$this->contexts[ $index ]['primary_error'] = State::error_category( $message );
				break;
			}
		}
	}

	/** WP Mail SMTP 4.9+ fires this only after actual SMTP/PHP-mail acceptance. */
	public function wp_mail_smtp_sent( $sent ): void {
		global $phpmailer;
		if ( true !== $sent || ! defined( 'WPMS_PLUGIN_VER' ) || version_compare( WPMS_PLUGIN_VER, '4.9.0', '<' ) || ! is_a( $phpmailer, 'WPMailSMTP\\MailCatcherInterface' ) ) {
			return;
		}
		$data = array(
			'subject' => $phpmailer->Subject,
			'message' => $phpmailer->Body,
		);
		$key  = $this->key( $data );
		for ( $index = count( $this->contexts ) - 1; $index >= 0; --$index ) {
			if ( $key === $this->contexts[ $index ]['key'] && get_current_blog_id() === $this->contexts[ $index ]['blog'] ) {
				$this->contexts[ $index ]['observable'] = true;
				$this->contexts[ $index ]['transport']  = in_array( $phpmailer->Mailer, array( 'smtp', 'mail', 'sendmail', 'qmail' ), true ) ? $phpmailer->Mailer : 'unknown';
				break;
			}
		}
		$this->complete( $data, 'accepted', '' );
	}

	public function succeeded( array $data ): void {
		$this->complete( $data, 'accepted', '' );
	}

	public function failed( \WP_Error $error ): void {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		global $phpmailer;
		$prefix = 'Invalid address:';
		if ( is_object( $phpmailer ) && method_exists( $phpmailer, 'getTranslations' ) ) {
			$translations = $phpmailer->getTranslations();
			if ( is_string( $translations['invalid_address'] ?? null ) ) {
				$prefix = $translations['invalid_address'];
			}
		}
		$category = State::error_category( $error->get_error_message(), $prefix );
		if ( 'unknown' === $category ) {
			$key = $this->key( $data );
			foreach ( array_reverse( $this->contexts ) as $context ) {
				if ( $key === $context['key'] && 'smtp' === $context['transport'] && get_current_blog_id() === $context['blog'] && is_object( $phpmailer ) && method_exists( $phpmailer, 'getSMTPInstance' ) ) {
					// PHPMailer exceptions can omit the server response (e.g. quota rejection).
					$smtp_error = $phpmailer->getSMTPInstance()->getError();
					$category   = State::error_category( (string) ( $smtp_error['detail'] ?? '' ) );
					break;
				}
			}
		}
		$diagnostic = array();
		if ( 'invalid_sender' === $category ) {
			$sender = State::sender_from_error( $error->get_error_message(), $prefix );
			if ( $sender ) {
				$diagnostic['sender'] = $sender;
			}
		}
		$this->complete( $data, 'failed', $category, min( 3600, max( 0, (int) ( $data['retry_after'] ?? 0 ) ) ), $diagnostic );
	}

	protected function complete( array $data, string $status, string $category, int $retry_after = 0, array $diagnostic = array() ): void {
		$key     = $this->key( $data );
		$context = null;
		for ( $index = count( $this->contexts ) - 1; $index >= 0; --$index ) {
			if ( $this->contexts[ $index ]['key'] === $key && get_current_blog_id() === $this->contexts[ $index ]['blog'] ) {
				$context = $this->contexts[ $index ];
				array_splice( $this->contexts, $index, 1 );
				break;
			}
		}
		if ( null === $context ) {
			return;
		}
		if ( 'accepted' === $status && ! $context['observable'] ) {
			$status = 'unobservable';
		}
		$result = array(
			'status'        => $status,
			'error'         => $category,
			'route'         => $context['route'],
			'transport'     => $context['transport'],
			'retry_after'   => $retry_after,
			'primary_error' => 'accepted' === $status ? ( $context['primary_error'] ?? '' ) : '',
		);
		if ( $this->probe && $this->probe === $context['id'] ) {
			// Ordinary site messages must never persist customer sender addresses.
			$result['diagnostic']            = $diagnostic;
			$this->results[ $context['id'] ] = $result;
			return;
		}
		$now = $this->store->now();
		$this->store->change(
			static function ( array $state ) use ( $context, $result, $now ): ?array {
				if ( $state['generation'] !== $context['generation'] ) {
					return null;
				}
				return self::observe( $state, $result, $now, false );
			}
		);
	}

	public static function observe( array $state, array $result, int $now, bool $probe ): array {
		$state['visibility'] = 'unobservable' === $result['status'] ? 'limited' : ( $result['route'] ? 'instrumented' : 'limited' );
		if ( $state['route'] && $result['route'] && $state['route'] !== $result['route'] ) {
			// A different route cannot keep the previous route's evidence fresh.
			$state['observed_at'] = 0;
			$state['transport']   = 'unknown';
		}
		if ( $result['route'] && ( $probe || ! $state['incident'] || ! $state['route'] ) ) {
			$state['route'] = $result['route'];
		}
		if ( 'accepted' === $result['status'] ) {
			$state['last_success'] = $now;
			if ( ! $state['incident'] || ( $probe && empty( $result['primary_error'] ) ) ) {
				$state['transport']   = 'accepted';
				$state['observed_at'] = $now;
				if ( $probe && $state['incident'] ) {
					$state['recovered_at'] = $now;
				}
				$state['incident']   = '';
				$state['error']      = '';
				$state['diagnostic'] = array();
			}
		} elseif ( 'failed' === $result['status'] ) {
			$state['last_failure'] = $now;
			if ( State::site_error( $result['error'] ) ) {
				$state['incident']    = $state['incident'] ? $state['incident'] : wp_generate_uuid4();
				$state['transport']   = 'failed';
				$state['observed_at'] = $now;
				$state['error']       = $result['error'];
				$state['diagnostic']  = $probe ? ( $result['diagnostic'] ?? array() ) : array();
			}
		} elseif ( $probe && ! $state['incident'] ) {
			$state['transport']   = 'unobservable';
			$state['observed_at'] = 0;
		}
		if ( ! empty( $result['primary_error'] ) && State::site_error( $result['primary_error'] ) ) {
			$state['incident']    = $state['incident'] ? $state['incident'] : wp_generate_uuid4();
			$state['error']       = $result['primary_error'];
			$state['diagnostic']  = array();
			$state['transport']   = 'accepted';
			$state['observed_at'] = $now;
		}
		return State::event( $state, $result['status'] . ( $result['error'] ? ':' . $result['error'] : '' ), $now );
	}
}

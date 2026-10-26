<?php
namespace WPDevAssist\MailHealth;

defined( 'ABSPATH' ) || exit;

/** Evidence rules; no transport or persistence side effects. */
class State {
	public const VERSION       = 2;
	public const INTERVAL      = HOUR_IN_SECONDS;
	public const TTL           = 2 * HOUR_IN_SECONDS;
	public const TIMEOUT       = 15;
	public const QUEUE_TIMEOUT = 30;

	public static function defaults(): array {
		return array(
			'version'      => self::VERSION,
			'generation'   => '',
			'config'       => array(
				'enabled'   => false,
				'recipient' => '',
				'identity'  => '',
			),
			'route'        => '',
			'visibility'   => 'unknown',
			'lease'        => array(),
			'transport'    => 'unknown',
			'observed_at'  => 0,
			'checked_at'   => 0,
			'last_success' => 0,
			'last_failure' => 0,
			'error'        => '',
			'diagnostic'   => array(),
			'incident'     => '',
			'recovered_at' => 0,
			'attempt'      => array(),
			'events'       => array(),
			'sends'        => array(),
			'heartbeat'    => 0,
			'due'          => 0,
			'retry_root'   => '',
		);
	}

	public static function prune( array $state, int $now ): array {
		$state['events'] = array_slice(
			array_values(
				array_filter(
					$state['events'],
					static function ( array $event ) use ( $now ): bool {
						return $event['time'] >= $now - 7 * DAY_IN_SECONDS;
					}
				)
			),
			-20
		);
		$state['sends']  = array_values(
			array_filter(
				$state['sends'],
				static function ( int $sent ) use ( $now ): bool {
					return $sent > $now - HOUR_IN_SECONDS;
				}
			)
		);
		return $state;
	}

	public static function event( array $state, string $kind, int $now, string $id = '' ): array {
		$state['events'][] = array(
			'time' => $now,
			'kind' => $kind,
			'id'   => $id,
		);
		return self::prune( $state, $now );
	}

	/** A public, read-time view must never expose worker ownership tokens. */
	public static function view( array $state, int $now ): array {
		$state = self::prune( $state, $now );
		unset( $state['sends'], $state['revision'], $state['lease'] );
		unset( $state['attempt']['token'] );
		$state['freshness'] = ! $state['observed_at'] ? 'never_checked' : ( $now - $state['observed_at'] >= self::TTL ? 'stale' : 'fresh' );
		$state['scheduler'] = ! $state['config']['enabled'] ? 'disabled' : 'scheduled';
		if ( $state['config']['enabled'] && ( ! $state['due'] || $now >= $state['due'] + self::QUEUE_TIMEOUT ) ) {
			$state['scheduler'] = 'overdue';
		}
		$attempt = $state['attempt'];
		if ( 'worker_unavailable' === ( $attempt['status'] ?? '' ) ) {
			$state['scheduler'] = 'failed';
		}
		if ( ! empty( $attempt ) && in_array( $attempt['status'], array( 'pending', 'running' ), true ) ) {
			$deadline = 'running' === $attempt['status'] ? $attempt['started'] + 120 : $attempt['due'] + self::QUEUE_TIMEOUT;
			if ( $now >= $deadline ) {
				$state['attempt']['status'] = 'worker_unavailable';
				$state['scheduler']         = 'failed';
			} elseif ( 'running' === $attempt['status'] ) {
				$state['scheduler'] = 'running';
			}
		}
		return $state;
	}

	/** Keep internal evidence separate while presenting a single understandable status. */
	public static function summary( array $state, int $now ): string {
		$state = self::view( $state, $now );
		if ( ! $state['config']['enabled'] ) {
			return 'off';
		}
		if ( ! is_email( $state['config']['recipient'] ) ) {
			return 'recipient';
		}
		if ( in_array( $state['scheduler'], array( 'overdue', 'failed' ), true ) ) {
			return 'delayed';
		}
		if ( $state['observed_at'] && 'stale' === $state['freshness'] ) {
			return 'stale';
		}
		if ( $state['incident'] ) {
			return 'accepted' === $state['transport'] ? 'degraded' : 'failed';
		}
		if ( 'failed' === ( $state['attempt']['status'] ?? '' ) ) {
			return 'failed';
		}
		if ( 'limited' === $state['visibility'] || 'unobservable' === $state['transport'] ) {
			return 'unknown';
		}
		if ( 'accepted' === $state['transport'] && 'fresh' === $state['freshness'] ) {
			return 'ok';
		}
		return 'checking';
	}

	/** Store a fixed category only: raw provider errors can contain credentials and PII. */
	public static function error_category( string $message, string $invalid_address = 'Invalid address:' ): string {
		if ( preg_match( self::invalid_sender_pattern( $invalid_address ), $message ) ) {
			return 'invalid_sender';
		}
		$patterns = array(
			'recipient'      => '/recipient|rcpt|invalid address|address rejected/i',
			'message'        => '/attachment|file access|message body|empty message|encoding/i',
			'quota'          => '/quota|daily limit|sending limit|insufficient credit/i',
			'throttled'      => '/rate limit|throttl|too many|\b4[25][01]\b/i',
			'authentication' => '/authenticat|oauth|invalid_grant|password|\b535\b/i',
			'tls'            => '/certificate|starttls|ssl|tls|crypto/i',
			'dns'            => '/getaddrinfo|resolve|name or service|dns/i',
			'timeout'        => '/timed out|timeout/i',
			'connection'     => '/connect|network|unreachable|refused/i',
			'sender'         => '/sender|relay|from address/i',
		);
		foreach ( $patterns as $category => $pattern ) {
			if ( preg_match( $pattern, $message ) ) {
				return $category;
			}
		}
		return 'unknown';
	}

	/** Store only a bounded address-shaped value from the plugin's own probe. */
	public static function safe_sender( $sender ): string {
		if ( ! is_string( $sender ) || strlen( $sender ) > 254 || ! preg_match( '/\A[a-z0-9._%+\-]+@[a-z0-9.\-]+\z/i', $sender ) ) {
			return '';
		}
		return $sender;
	}

	public static function sender_from_error( string $message, string $invalid_address = 'Invalid address:' ): string {
		if ( ! preg_match( self::invalid_sender_pattern( $invalid_address, true ), $message, $match ) ) {
			return '';
		}
		return self::safe_sender( trim( $match[1] ) );
	}

	protected static function invalid_sender_pattern( string $invalid_address, bool $address = false ): string {
		$prefix = '(?:Invalid address:|' . preg_quote( $invalid_address, '/' ) . ')';
		return $address ? '/\A' . $prefix . '[ \t]*\(From\):[ \t]*([^\r\n]+)\z/i' : '/(?:\A|\r?\n)' . $prefix . '[ \t]*\(From\):/i';
	}

	public static function site_error( string $category ): bool {
		return 'invalid_sender' === $category || self::transport_error( $category );
	}

	public static function transport_error( string $category ): bool {
		return in_array( $category, array( 'quota', 'throttled', 'authentication', 'tls', 'dns', 'timeout', 'connection', 'sender' ), true );
	}
}

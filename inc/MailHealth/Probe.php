<?php
namespace WPDevAssist\MailHealth;

defined( 'ABSPATH' ) || exit;

class Probe {
	protected Store $store;
	protected Monitor $monitor;

	public function __construct( Store $store, Monitor $monitor ) {
		$this->store   = $store;
		$this->monitor = $monitor;
	}

	public function run( string $id, string $generation ): void {
		$this->store->sync_identity();
		$now     = $this->store->now();
		$token   = wp_generate_uuid4();
		$claimed = $this->store->change(
			static function ( array $state ) use ( $id, $generation, $now, $token ): ?array {
				$state   = State::prune( $state, $now );
				$attempt = $state['attempt'];
				if ( ! $state['config']['enabled'] || ( ! empty( $state['lease']['expires'] ) && $state['lease']['expires'] >= $now ) || $state['generation'] !== $generation || ( $attempt['id'] ?? '' ) !== $id || 'pending' !== $attempt['status'] || count( $state['sends'] ) >= 6 ) {
					return null;
				}
				$state['attempt']['status']  = 'running';
				$state['attempt']['started'] = $now;
				$state['attempt']['token']   = $token;
				$state['lease']              = array(
					'token'   => $token,
					'expires' => $now + 120,
				);
				$state['heartbeat']          = $now;
				$state['sends'][]            = $now;
				return $state;
			}
		);
		if ( ! $claimed ) {
			return;
		}
		$state = $this->store->read();
		if ( $state['generation'] !== $generation || ( $state['attempt']['token'] ?? '' ) !== $token ) {
			return;
		}
		$this->monitor->begin_probe( $id );
		$sent   = false;
		$thrown = false;
		try {
			$sent = wp_mail(
				$state['config']['recipient'],
				sprintf( __( 'Mail health test %s', 'development-assistant' ), $id ),
				sprintf( __( "Site: %1\$s\nTest: %2\$s\nTime (UTC): %3\$s\n\nThis is an automatic email health check from Development Assistant. No action is required.", 'development-assistant' ), home_url(), $id, gmdate( 'Y-m-d H:i:s', $now ) ),
				array( 'Content-Type: text/plain; charset=UTF-8', 'X-Development-Assistant-Test: ' . $id )
			);
		} catch ( \Throwable $error ) {
			// The transport may throw sensitive diagnostics. Do not persist the exception.
			$thrown = true;
		} finally {
			$result = $this->monitor->end_probe( $id );
		}
		if ( $thrown || ! $sent ) {
			$result['status'] = 'failed';
			$result['error']  = $result['error'] ? $result['error'] : 'unknown';
		}
		$finished = $this->store->now();
		$this->store->change(
			static function ( array $current ) use ( $id, $generation, $token, $result, $finished ): ?array {
				$attempt = $current['attempt'];
				if ( $current['generation'] !== $generation || ( $attempt['id'] ?? '' ) !== $id || ( $attempt['token'] ?? '' ) !== $token || 'running' !== $attempt['status'] || $finished > $attempt['started'] + 120 ) {
					return null;
				}
				$current                           = Monitor::observe( $current, $result, $finished, true );
				$current['attempt']['status']      = $result['status'];
				$current['attempt']['finished']    = $finished;
				$current['attempt']['error']       = $result['error'];
				$current['attempt']['diagnostic']  = $result['diagnostic'] ?? array();
				$current['attempt']['transport']   = $result['transport'];
				$current['attempt']['retry_after'] = $result['retry_after'];
				$current['attempt']['fallback']    = ! empty( $result['primary_error'] );
				unset( $current['attempt']['token'] );
				$current['lease']      = array();
				$current['checked_at'] = $finished;
				$current['retry_root'] = 'failed' === $result['status'] && State::transport_error( $result['error'] ) ? $attempt['root'] : '';
				return State::event( $current, 'probe_' . $result['status'], $finished, $id );
			}
		);
	}
}

<?php
namespace WPDevAssist\MailHealth;

defined( 'ABSPATH' ) || exit;

class Scheduler {
	public const TICK  = 'wp_dev_assist_mail_health_tick';
	public const WORK  = 'wp_dev_assist_mail_health_work';
	public const RETRY = 'wp_dev_assist_mail_health_retry';
	protected Store $store;
	protected Probe $probe;

	public function __construct( Store $store, Probe $probe ) {
		$this->store = $store;
		$this->probe = $probe;
		add_action( 'init', array( $this, 'ensure' ) );
		add_action( self::TICK, array( $this, 'tick' ), 10, 1 );
		add_action( self::WORK, array( $this, 'work' ), 10, 2 );
		add_action( self::RETRY, array( $this, 'retry' ), 10, 4 );
		add_action( 'updated_option', array( $this, 'option_changed' ), 10, 1 );
		add_action( 'added_option', array( $this, 'option_changed' ), 10, 1 );
		add_action( 'deleted_option', array( $this, 'option_changed' ), 10, 1 );
		add_action( 'activated_plugin', array( $this, 'plugins_changed' ) );
		add_action( 'deactivated_plugin', array( $this, 'plugins_changed' ) );
	}

	public function ensure(): void {
		$this->store->sync_identity();
		$state = $this->store->read();
		if ( ! $state['config']['enabled'] ) {
			$this->clear();
			return;
		}
		if ( ! $state['attempt'] && is_email( $state['config']['recipient'] ) ) {
			$this->request();
		}
		// If a cron entry was lost, restore this attempt without resetting its age.
		if ( 'pending' === ( $state['attempt']['status'] ?? '' ) ) {
			$work_args = array( $state['attempt']['id'], $state['generation'] );
			if ( ! wp_next_scheduled( self::WORK, $work_args ) ) {
				wp_schedule_single_event( $this->store->now(), self::WORK, $work_args );
			}
		}
		$args = array( $state['generation'] );
		$next = wp_next_scheduled( self::TICK, $args );
		if ( ! $next ) {
			$next = $this->store->now() + State::INTERVAL;
			if ( ! wp_schedule_single_event( $next, self::TICK, $args ) ) {
				return;
			}
		}
		if ( $state['due'] !== (int) $next ) {
			$this->store->change(
				static function ( array $current ) use ( $state, $next ): ?array {
					if ( $current['generation'] !== $state['generation'] ) {
						return null;
					}
					$current['due'] = (int) $next;
					return $current;
				}
			);
		}
	}

	/** Reserve one attempt atomically. Never send in an admin request. */
	public function request( string $source = 'scheduled', int $step = 0, string $root = '', int $failed_at = 0 ): string {
		$this->store->sync_identity();
		$now        = $this->store->now();
		$id         = wp_generate_uuid4();
		$generation = '';
		$queued     = $this->store->change(
			static function ( array $state ) use ( $source, $step, $root, $failed_at, $now, $id, &$generation ): ?array {
				$state = State::prune( $state, $now );
				if ( ! $state['config']['enabled'] || ( ! empty( $state['lease']['expires'] ) && $state['lease']['expires'] >= $now ) || ! is_email( $state['config']['recipient'] ) || count( $state['sends'] ) >= 6 ) {
					return null;
				}
				$attempt = $state['attempt'];
				if ( $attempt && ( ( 'pending' === $attempt['status'] && $attempt['due'] + State::QUEUE_TIMEOUT > $now ) || ( 'running' === $attempt['status'] && $attempt['started'] + 120 >= $now ) ) ) {
					return null;
				}
				if ( 'retry' === $source && ( ! $root || $state['retry_root'] !== $root || $step > 2 ) ) {
					return null;
				}
				$generation       = $state['generation'];
				$state['attempt'] = array(
					'id'        => $id,
					'status'    => 'pending',
					'source'    => $source,
					'due'       => $now,
					'started'   => 0,
					'finished'  => 0,
					'step'      => $step,
					'root'      => $root ? $root : $id,
					'failed_at' => $failed_at,
				);
				if ( 'retry' !== $source ) {
					$state['retry_root'] = '';
				}
				return State::event( $state, 'queued', $now, $id );
			}
		);
		if ( ! $queued ) {
			return '';
		}
		if ( ! wp_schedule_single_event( $now, self::WORK, array( $id, $generation ) ) ) {
			$this->store->change(
				static function ( array $state ) use ( $id ): ?array {
					if ( ( $state['attempt']['id'] ?? '' ) !== $id ) {
						return null;
					}
					$state['attempt']['status'] = 'worker_unavailable';
					return $state;
				}
			);
			return '';
		}
		return $id;
	}

	public function tick( string $generation ): void {
		$state = $this->store->read();
		if ( $state['generation'] !== $generation ) {
			return;
		}
		$this->request( 'scheduled' );
		$this->ensure();
	}

	public function work( string $id, string $generation ): void {
		$this->probe->run( $id, $generation );
		$state   = $this->store->read();
		$attempt = $state['attempt'];
		if ( $state['generation'] !== $generation || ( $attempt['id'] ?? '' ) !== $id || ! $state['retry_root'] || $attempt['step'] >= 2 || 'failed' !== $attempt['status'] ) {
			return;
		}
		$step      = $attempt['step'] + 1;
		$failed_at = $attempt['failed_at'] ? $attempt['failed_at'] : $attempt['finished'];
		$due       = max( $this->store->now() + 1, $failed_at + ( 1 === $step ? 300 : 900 ), $this->store->now() + $attempt['retry_after'] );
		$args      = array( $generation, $attempt['root'], $step, $failed_at );
		if ( ! wp_next_scheduled( self::RETRY, $args ) ) {
			wp_schedule_single_event( $due, self::RETRY, $args );
		}
	}

	public function retry( string $generation, string $root, int $step, int $failed_at ): void {
		if ( $this->store->read()['generation'] === $generation ) {
			$this->request( 'retry', $step, $root, $failed_at );
		}
	}

	public function clear(): void {
		foreach ( array( self::TICK, self::WORK, self::RETRY ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	public function deactivate( bool $reset, bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			$offset = 0;
			do {
				$ids = get_sites(
					array(
						'fields'     => 'ids',
						'number'     => 100,
						'offset'     => $offset,
						'network_id' => get_current_network_id(),
					)
				);
				foreach ( $ids as $blog_id ) {
					switch_to_blog( $blog_id );
					try {
						$this->deactivate( 'yes' === get_option( \WPDevAssist\Setting::RESET_KEY, \WPDevAssist\Setting::RESET_DEFAULT ) );
					} finally {
						restore_current_blog();
					}
				}
				$count   = count( $ids );
				$offset += $count;
			} while ( 100 === $count );
			return;
		}
		$this->clear();
		if ( $reset ) {
			delete_option( Store::OPTION );
		} else {
			$this->store->change(
				static function ( array $state ): array {
					$state['generation'] = wp_generate_uuid4();
					$state['attempt']    = array();
					$state['retry_root'] = '';
					$state['due']        = 0;
					return $state;
				}
			);
		}
	}

	public function option_changed( string $option ): void {
		if ( 'admin_email' === $option ) {
			$this->store->sync_identity();
			$this->clear();
		} elseif ( in_array( $option, array( 'wp_mail_smtp', 'wp_mail_smtp_pro', 'swpsmtp_options', 'fluentmail-settings', 'postman_options', 'easy_wp_smtp' ), true ) ) {
			$this->store->invalidate();
			$this->clear();
		}
	}

	public function plugins_changed(): void {
		$this->store->invalidate();
		$this->clear();
	}
}

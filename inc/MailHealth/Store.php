<?php
namespace WPDevAssist\MailHealth;

defined( 'ABSPATH' ) || exit;

/** Atomic per-blog state updates, including worker ownership and send limits. */
class Store {
	public const OPTION = 'wp_dev_assist_mail_health';

	public function now(): int {
		return time();
	}

	public function identity(): string {
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		return hash( 'sha256', get_current_blog_id() . '|' . home_url() . '|' . site_url() . '|' . $environment );
	}

	public function read(): array {
		global $wpdb;
		// Read the database, not an object-cache snapshot: CAS must use current state.
		$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$value = null === $raw ? array() : maybe_unserialize( $raw );
		return is_array( $value ) ? array_replace( State::defaults(), $value ) : State::defaults();
	}

	/** The callback returns the next state, or null to decline an operation. */
	public function change( callable $callback ): bool {
		global $wpdb;
		for ( $try = 0; $try < 8; ++$try ) {
			$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$state = null === $raw ? State::defaults() : maybe_unserialize( $raw );
			$state = is_array( $state ) ? array_replace( State::defaults(), $state ) : State::defaults();
			$next  = $callback( $state );
			if ( null === $next ) {
				return false;
			}
			$next             = State::prune( $next, $this->now() );
			$next['revision'] = wp_generate_uuid4();
			if ( null === $raw ) {
				if ( add_option( self::OPTION, $next, '', false ) ) {
					return true;
				}
			} else {
				$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $next ), self::OPTION, $raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				if ( 1 === $updated ) {
					return true;
				}
			}
		}
		return false;
	}

	/** The only user setting is enabled; recipient and timing come from the site. */
	public function configure( bool $enabled ): bool {
		$next = array(
			'enabled'   => $enabled,
			'recipient' => (string) get_option( 'admin_email', '' ),
			'identity'  => $this->identity(),
		);
		return $this->change(
			static function ( array $state ) use ( $next ): ?array {
				if ( $next === $state['config'] && State::VERSION === $state['version'] ) {
					return $state;
				}
				$clean               = State::defaults();
				$clean['sends']      = $state['sends'];
				$clean['lease']      = $state['lease'];
				$clean['config']     = $next;
				$clean['generation'] = wp_generate_uuid4();
				return $clean;
			}
		);
	}

	public function sync_identity(): void {
		$state  = $this->read();
		$cloned = $state['config']['identity'] && $this->identity() !== $state['config']['identity'];
		if ( $cloned || State::VERSION !== $state['version'] || (string) get_option( 'admin_email', '' ) !== $state['config']['recipient'] ) {
			$this->configure( ! $cloned && $state['config']['enabled'] );
		}
	}

	/** Invalidate evidence on known mailer changes; never persist mailer settings. */
	public function invalidate(): void {
		$this->change(
			static function ( array $state ): array {
				$config              = $state['config'];
				$sends               = $state['sends'];
				$lease               = $state['lease'];
				$state               = State::defaults();
				$state['sends']      = $sends;
				$state['lease']      = $lease;
				$state['config']     = $config;
				$state['generation'] = wp_generate_uuid4();
				return $state;
			}
		);
	}
}

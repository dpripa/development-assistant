<?php

declare(strict_types=1);

// PHPMailer's property names; deliberate uncached assertions and HTTP test inputs.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase, WordPress.DB.DirectDatabaseQuery, Generic.CodeAnalysis.UnusedFunctionParameter.Found, WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

use WPDevAssist\MailHealth\State;
use WPDevAssist\MailHealth\Store;
use WPDevAssist\MailHealth\Monitor;
use WPDevAssist\MailHealth\Probe;
use WPDevAssist\MailHealth\Scheduler;
use WPDevAssist\Setting\MailHealth;
use WPDevAssist\Tests\Support\TestableMailHealth;

class MailHealthTest extends WP_UnitTestCase {
	private TestableMailHealth $store;
	private Monitor $monitor;
	private Probe $probe;
	private Scheduler $scheduler;
	private MailHealth $page;
	private array $fixture = array();
	private $configure_mailer;

	public function set_up(): void {
		parent::set_up();
		global $wp_filter;
		foreach ( $wp_filter as $hook => $registered ) {
			foreach ( $registered->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && ( $function[0] instanceof Monitor || $function[0] instanceof Scheduler || $function[0] instanceof MailHealth ) ) {
						remove_filter( $hook, $function, $priority );
					}
				}
			}
		}
		delete_option( Store::OPTION );
		update_option( 'admin_email', 'health@example.org' );
		$this->store     = new TestableMailHealth();
		$this->monitor   = new Monitor( $this->store );
		$this->probe     = new Probe( $this->store, $this->monitor );
		$this->scheduler = new Scheduler( $this->store, $this->probe );
		$this->page      = new MailHealth( $this->store, $this->scheduler, \WPDevAssist\app()->asset() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		if ( $this->fixture ) {
			if ( isset( $GLOBALS['phpmailer'] ) ) {
				$GLOBALS['phpmailer']->smtpClose();
			}
			TestableMailHealth::finish_smtp( $this->fixture );
		}
		if ( $this->configure_mailer ) {
			remove_action( 'phpmailer_init', $this->configure_mailer, 100 );
		}
		$this->scheduler->clear();
		delete_option( Store::OPTION );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function enable(): void {
		$this->assertTrue( $this->page->set_enabled( true ) );
	}

	private function smtp( string $mode = 'success' ): void {
		$this->fixture          = TestableMailHealth::smtp( $mode );
		$port                   = $this->fixture[2];
		$this->configure_mailer = static function ( $mailer ) use ( $port, $mode ): void {
			$mailer->isSMTP();
			$mailer->Host                         = '127.0.0.1';
			$mailer->Port                         = $port;
			$mailer->SMTPAutoTLS                  = false;
			$mailer->SMTPAuth                     = 'authentication' === $mode;
			$mailer->SMTPSecure                   = 'tls' === $mode ? 'tls' : '';
			$mailer->Username                     = 'fixture';
			$mailer->Password                     = 'fixture-secret';
			$mailer->Timeout                      = 20;
			$mailer->getSMTPInstance()->Timelimit = 20;
		};
		add_action( 'phpmailer_init', $this->configure_mailer, 100 );
		unset( $GLOBALS['phpmailer'] );
	}

	private function run_probe(): array {
		$state = $this->store->read();
		$id    = 'pending' === ( $state['attempt']['status'] ?? '' ) ? $state['attempt']['id'] : $this->scheduler->request();
		$this->assertNotEmpty( $id );
		$this->scheduler->work( $id, $this->store->read()['generation'] );
		return $this->store->read();
	}

	public function test_off_disables_jobs_observation_and_notices(): void {
		$this->scheduler->ensure();
		add_filter( 'pre_wp_mail', '__return_true' );
		$this->assertTrue( wp_mail( 'private@example.org', 'Private subject', 'Private body' ) );
		$state = $this->store->read();
		$this->assertSame( '', $this->scheduler->request() );
		$this->assertEmpty( $state['events'] );
		$this->assertEmpty( $state['attempt'] );
		$this->assertSame( 'off', State::summary( $state, $this->store->now() ) );
		ob_start();
		$this->page->notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_switch_on_automatically_queues_one_check_to_the_administrator(): void {
		$this->enable();
		$state = $this->store->read();
		$this->assertSame( 'health@example.org', $state['config']['recipient'] );
		$this->assertSame( array( 'enabled', 'recipient', 'identity' ), array_keys( $state['config'] ) );
		$this->assertSame( 'pending', $state['attempt']['status'] );
		$this->assertEmpty( $state['sends'] );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::WORK, array( $state['attempt']['id'], $state['generation'] ) ) );
		$this->scheduler->ensure();
		$this->assertSame( $state['attempt']['id'], $this->store->read()['attempt']['id'] );
		$this->assertSame( $this->store->now() + 3600, wp_next_scheduled( Scheduler::TICK, array( $state['generation'] ) ) );
		global $wpdb;
		$this->assertNotContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name=%s", Store::OPTION ) ), array( 'yes', 'on', 'auto-on', 'auto' ) );
	}

	public function test_actual_smtp_check_works_without_codes_or_confirmation(): void {
		$this->smtp();
		$this->enable();
		$state = $this->run_probe();
		$this->assertSame( 'ok', State::summary( $state, $this->store->now() ) );
		$this->assertSame( 'accepted', $state['attempt']['status'] );
		$this->assertSame( 20, $GLOBALS['phpmailer']->Timeout );
		$this->assertSame( 20, $GLOBALS['phpmailer']->getSMTPInstance()->Timelimit );
		$body          = TestableMailHealth::finish_smtp( $this->fixture );
		$this->fixture = array();
		$this->assertStringContainsString( 'No action is required.', $body );
		$this->assertStringNotContainsString( 'Receipt code', $body );
		$this->assertArrayNotHasKey( 'tickets', $state );
		$this->assertArrayNotHasKey( 'delivery', $state );
		$this->assertStringNotContainsString( 'fixture-secret', wp_json_encode( $state ) );
	}

	/** @dataProvider smtp_failure_modes */
	public function test_real_smtp_failures_and_automatic_retries( string $mode, string $category, bool $incident ): void {
		$this->smtp( $mode );
		$this->enable();
		$state = $this->run_probe();
		$this->assertSame( 'failed', $state['attempt']['status'] );
		$this->assertSame( $category, $state['attempt']['error'] );
		$this->assertSame( $incident, ! empty( $state['incident'] ) );
		$this->assertSame( 'failed', State::summary( $state, $this->store->now() ) );
		$args = array( $state['generation'], $state['attempt']['root'], 1, $state['attempt']['finished'] );
		$this->assertSame( $incident ? $this->store->now() + 300 : false, wp_next_scheduled( Scheduler::RETRY, $args ) );
	}

	public function smtp_failure_modes(): array {
		return array( array( 'authentication', 'authentication', true ), array( 'recipient', 'recipient', false ), array( 'sender', 'sender', true ), array( 'tls', 'tls', true ), array( 'quota', 'quota', true ) );
	}

	public function test_queued_or_suppressed_success_does_not_become_healthy(): void {
		$this->enable();
		add_filter( 'pre_wp_mail', '__return_true' );
		$state = $this->run_probe();
		$this->assertSame( 'unknown', State::summary( $state, $this->store->now() ) );
		$this->assertSame( 0, $state['last_success'] );
	}

	public function test_unexplained_failure_does_not_claim_a_global_smtp_outage(): void {
		$this->enable();
		add_filter( 'pre_wp_mail', '__return_false' );
		$state = $this->run_probe();
		$this->assertSame( 'failed', State::summary( $state, $this->store->now() ) );
		$this->assertEmpty( $state['incident'] );
		$this->assertSame( 'unknown', $state['attempt']['error'] );
	}

	public function test_status_expires_without_a_running_cron(): void {
		$this->enable();
		$now = $this->store->now();
		$this->store->change(
			static function ( array $state ) use ( $now ): array {
				$state['transport']   = 'accepted';
				$state['observed_at'] = $now;
				$state['attempt']     = array();
				$state['due']         = $now + 10000;
				return $state;
			}
		);
		$this->assertSame( 'ok', State::summary( $this->store->read(), $now ) );
		$this->store->clock += 7201;
		$this->assertSame( 'stale', State::summary( $this->store->read(), $this->store->now() ) );
	}

	public function test_missing_worker_is_delayed_not_smtp_failure(): void {
		$this->enable();
		$this->store->clock += 31;
		$state               = $this->store->read();
		$this->assertSame( 'delayed', State::summary( $state, $this->store->now() ) );
		$this->assertEmpty( $state['incident'] );
	}

	public function test_pending_check_times_out_without_polling_resetting_its_age(): void {
		$this->enable();
		$attempt             = $this->store->read()['attempt'];
		$this->store->clock += 29;
		$this->assertSame( 'checking', State::summary( $this->store->read(), $this->store->now() ) );
		++$this->store->clock;
		$this->assertSame( 'delayed', State::summary( $this->store->read(), $this->store->now() ) );
		$this->scheduler->ensure();
		$this->assertSame( $attempt, $this->store->read()['attempt'] );
		$this->assertSame( 'delayed', State::summary( $this->store->read(), $this->store->now() ) );
	}

	public function test_lost_cron_entry_is_restored_without_restarting_the_wait(): void {
		$this->enable();
		$state = $this->store->read();
		$args  = array( $state['attempt']['id'], $state['generation'] );
		wp_unschedule_event( $state['attempt']['due'], Scheduler::WORK, $args );
		$this->store->clock += 31;
		$this->scheduler->ensure();
		$this->assertNotFalse( wp_next_scheduled( Scheduler::WORK, $args ) );
		$this->assertSame( $state['attempt'], $this->store->read()['attempt'] );
		$this->assertSame( 'delayed', State::summary( $this->store->read(), $this->store->now() ) );
	}

	public function test_delayed_worker_can_recover_after_the_cron_runner_resumes(): void {
		$this->smtp();
		$this->enable();
		$before              = $this->store->read();
		$this->store->clock += 1800;
		$this->assertSame( 'delayed', State::summary( $this->store->read(), $this->store->now() ) );
		$this->scheduler->work( $before['attempt']['id'], $before['generation'] );
		$state = $this->store->read();
		$this->assertSame( 'ok', State::summary( $state, $this->store->now() ) );
		$this->assertCount( 1, $state['sends'] );
		$this->scheduler->work( $before['attempt']['id'], $before['generation'] );
		$this->assertCount( 1, $this->store->read()['sends'] );
	}

	public function test_generated_local_cron_filter_only_rewrites_the_local_cron_origin(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/init-wp.sh' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 1, preg_match( "/<<'PHP_CRON'\n(.*?)\nPHP_CRON/s", $source, $match ) );
		$file        = tempnam( sys_get_temp_dir(), 'da-local-cron-' );
		$environment = getenv( 'WP_ENVIRONMENT_TYPE' );
		try {
			file_put_contents( $file, $match[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			require $file;
			update_option( 'siteurl', 'https://localhost:21601' );
			putenv( 'WP_ENVIRONMENT_TYPE=development' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restored test environment.
			$request = array(
				'url'  => 'https://localhost:21601/wp-cron.php?doing_wp_cron=123.456',
				'args' => array(
					'blocking'  => false,
					'timeout'   => 0.01,
					'sslverify' => true,
					'headers'   => array( 'X-Test' => 'kept' ),
				),
			);
			$result  = apply_filters( 'cron_request', $request );
			$this->assertSame( 'http://wordpress/wp-cron.php?doing_wp_cron=123.456', $result['url'] );
			$this->assertSame( 'localhost:21601', $result['args']['headers']['Host'] );
			$this->assertSame( 'https', $result['args']['headers']['X-Forwarded-Proto'] );
			$this->assertSame( 'kept', $result['args']['headers']['X-Test'] );
			$this->assertTrue( $result['args']['sslverify'] );
			$this->assertFalse( $result['args']['blocking'] );
			foreach ( array( 'https://example.org/wp-cron.php', 'https://localhost:21602/wp-cron.php', 'https://localhost:21601/other.php', 'https://fixture:example@localhost:21601/wp-cron.php' ) as $url ) {
				$other        = $request;
				$other['url'] = $url;
				$this->assertSame( $other, apply_filters( 'cron_request', $other ) );
			}
			putenv( 'WP_ENVIRONMENT_TYPE=production' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restored test environment.
			$this->assertSame( $request, apply_filters( 'cron_request', $request ) );
		} finally {
			putenv( false === $environment ? 'WP_ENVIRONMENT_TYPE' : 'WP_ENVIRONMENT_TYPE=' . $environment ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restored test environment.
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}


	public function test_send_cap_and_duplicate_worker_guard_survive_recipient_changes(): void {
		$this->enable();
		$this->assertSame( '', $this->scheduler->request() );
		$now = $this->store->now();
		$this->store->change(
			static function ( array $state ) use ( $now ): array {
				$state['sends']   = array_fill( 0, 6, $now );
				$state['attempt'] = array();
				return $state;
			}
		);
		update_option( 'admin_email', 'new@example.org' );
		$this->scheduler->ensure();
		$this->assertSame( '', $this->scheduler->request() );
		$this->assertSame( 'new@example.org', $this->store->read()['config']['recipient'] );
		$this->store->clock += 3601;
		$this->scheduler->ensure();
		$this->assertSame( 'pending', $this->store->read()['attempt']['status'] );
	}

	public function test_new_admin_email_invalidates_old_jobs_and_queues_a_new_check(): void {
		$this->enable();
		$old = $this->store->read();
		update_option( 'admin_email', 'new@example.org' );
		$this->scheduler->ensure();
		$new = $this->store->read();
		$this->assertNotSame( $old['generation'], $new['generation'] );
		$this->assertSame( 'new@example.org', $new['config']['recipient'] );
		$this->probe->run( $old['attempt']['id'], $old['generation'] );
		$this->assertEmpty( $this->store->read()['sends'] );
	}

	public function test_invalid_site_admin_email_has_an_actionable_status(): void {
		add_filter(
			'pre_option_admin_email',
			static function (): string {
				return 'invalid';
			}
		);
		$this->enable();
		$this->assertSame( 'recipient', State::summary( $this->store->read(), $this->store->now() ) );
		$this->assertEmpty( $this->store->read()['attempt'] );
	}

	public function test_late_worker_cannot_overwrite_an_off_switch(): void {
		$this->enable();
		$disable = function ( $preempted ) {
			$this->page->set_enabled( false );
			return true;
		};
		add_filter( 'pre_wp_mail', $disable );
		$this->run_probe();
		$this->assertSame( 'off', State::summary( $this->store->read(), $this->store->now() ) );
		$this->assertEmpty( $this->store->read()['attempt'] );
	}

	public function test_reconfiguration_does_not_release_a_live_lease(): void {
		$this->enable();
		$change = function ( $preempted ) {
			update_option( 'admin_email', 'changed@example.org' );
			$this->assertSame( '', $this->scheduler->request() );
			return true;
		};
		add_filter( 'pre_wp_mail', $change );
		$this->run_probe();
		$this->assertNotEmpty( $this->store->read()['lease'] );
		$this->store->clock += 121;
		$this->scheduler->ensure();
		$this->assertSame( 'pending', $this->store->read()['attempt']['status'] );
	}

	public function test_over_deadline_worker_cannot_release_a_new_attempt(): void {
		$this->enable();
		$replacement = '';
		$delay       = function ( $preempted ) use ( &$replacement ) {
			$this->store->clock += 121;
			$replacement         = $this->scheduler->request();
			return true;
		};
		add_filter( 'pre_wp_mail', $delay );
		$this->run_probe();
		$state = $this->store->read();
		$this->assertNotEmpty( $replacement );
		$this->assertSame( $replacement, $state['attempt']['id'] );
		$this->assertSame( 'pending', $state['attempt']['status'] );
	}

	public function test_atomic_state_update_retries_without_losing_peer_writes(): void {
		$this->enable();
		$first = true;
		$this->store->change(
			function ( array $state ) use ( &$first ): array {
				if ( $first ) {
						$first = false;
						$this->store->change(
							function ( array $peer ): array {
								return State::event( $peer, 'peer_write', $this->store->now() );
							}
						);
				}
				return State::event( $state, 'our_write', $this->store->now() );
			}
		);
		$this->assertSame( array( 'peer_write', 'our_write' ), array_slice( array_column( $this->store->read()['events'], 'kind' ), -2 ) );
	}

	public function test_transport_retries_honor_limits_and_provider_hints(): void {
		$this->enable();
		$failure = static function ( $preempted, array $data ) {
			$data['retry_after'] = 600;
			do_action( 'wp_mail_failed', new WP_Error( 'fixture', 'SMTP connect failed secret@example.org token=hidden', $data ) );
			return false;
		};
		add_filter( 'pre_wp_mail', $failure, 10, 2 );
		$state     = $this->run_probe();
		$root      = $state['attempt']['root'];
		$failed_at = $state['attempt']['finished'];
		$args      = array( $state['generation'], $root, 1, $failed_at );
		$this->assertSame( $this->store->now() + 600, wp_next_scheduled( Scheduler::RETRY, $args ) );
		for ( $step = 1; $step <= 2; ++$step ) {
			$this->store->clock += 600;
			$this->scheduler->retry( $state['generation'], $root, $step, $failed_at );
			$next = $this->store->read();
			$this->scheduler->work( $next['attempt']['id'], $next['generation'] );
		}
		$this->assertCount( 3, $this->store->read()['sends'] );
		$this->assertSame( '', $this->scheduler->request( 'retry', 3, $root, $failed_at ) );
		$this->assertStringNotContainsString( 'secret@example.org', wp_json_encode( $this->store->read() ) );
	}

	public function test_ordinary_success_does_not_erase_a_transport_incident(): void {
		$state    = State::defaults();
		$state    = Monitor::observe(
			$state,
			array(
				'status' => 'failed',
				'error'  => 'authentication',
				'route'  => 'route',
			),
			100,
			false
		);
		$incident = $state['incident'];
		$result   = array(
			'status' => 'accepted',
			'error'  => '',
			'route'  => 'route',
		);
		$state    = Monitor::observe( $state, $result, 101, false );
		$this->assertSame( $incident, $state['incident'] );
		$state = Monitor::observe( $state, $result, 102, true );
		$this->assertEmpty( $state['incident'] );
		$this->assertSame( 102, $state['recovered_at'] );
	}

	public function test_old_configuration_migrates_to_one_switch_and_drops_confirmation_state(): void {
		$this->store->change(
			function ( array $state ): array {
				$state['version']  = 1;
				$state['config']   = array(
					'enabled'   => true,
					'recipient' => 'old@example.org',
					'identity'  => $this->store->identity(),
					'interval'  => 86400,
					'ttl'       => 172800,
					'timeout'   => 30,
				);
				$state['tickets']  = array( 'legacy-code-hash' );
				$state['delivery'] = array( 'status' => 'receipt_confirmed' );
				return $state;
			}
		);
		$this->scheduler->ensure();
		$state = $this->store->read();
		$this->assertSame( State::VERSION, $state['version'] );
		$this->assertTrue( $state['config']['enabled'] );
		$this->assertSame( 'health@example.org', $state['config']['recipient'] );
		$this->assertCount( 3, $state['config'] );
		$this->assertArrayNotHasKey( 'tickets', $state );
		$this->assertArrayNotHasKey( 'delivery', $state );
	}

	public function test_clone_disables_inherited_monitoring(): void {
		$this->enable();
		$this->store->change(
			static function ( array $state ): array {
				$state['config']['identity'] = 'another-site';
				return $state;
			}
		);
		$this->scheduler->ensure();
		$this->assertSame( 'off', State::summary( $this->store->read(), $this->store->now() ) );
	}

	public function test_disabled_and_deactivated_workers_cannot_send(): void {
		$this->enable();
		$old = $this->store->read();
		$this->page->set_enabled( false );
		$this->probe->run( $old['attempt']['id'], $old['generation'] );
		$this->assertEmpty( $this->store->read()['sends'] );
		$this->scheduler->deactivate( false );
		$this->assertFalse( $this->store->read()['config']['enabled'] );
		$this->scheduler->deactivate( true );
		$this->assertFalse( get_option( Store::OPTION ) );
	}

	public function test_mailer_bookkeeping_preserves_an_attempt_but_configuration_invalidates_it(): void {
		$this->enable();
		$id = $this->store->read()['attempt']['id'];
		$this->scheduler->option_changed( 'wp_mail_smtp_debug_events_db_version' );
		$this->assertSame( $id, $this->store->read()['attempt']['id'] );
		$this->scheduler->option_changed( 'wp_mail_smtp' );
		$this->scheduler->ensure();
		$this->assertNotSame( $id, $this->store->read()['attempt']['id'] );
	}

	public function test_one_checkbox_and_no_manual_controls_or_technical_table(): void {
		$this->enable();
		ob_start();
		$this->page->render();
		$html = ob_get_clean();
		$this->assertSame( 1, substr_count( $html, 'type="checkbox"' ) );
		$this->assertSame( 1, substr_count( $html, '<form ' ) );
		foreach ( array( 'type="email"', 'type="number"', 'name="recipient"', 'name="test_id"', 'name="code"', '<table', 'Queue a mail test', 'Refresh status', 'Confirm receipt' ) as $unwanted ) {
			$this->assertStringNotContainsString( $unwanted, $html );
		}
		$this->assertStringContainsString( 'data-status-url=', $html );
		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertEmpty( $this->store->read()['sends'] );
	}

	public function test_submenu_registration_and_non_admin_access(): void {
		do_action( 'admin_menu', '' );
		$this->assertNotEmpty( get_plugin_page_hook( MailHealth::PAGE, \WPDevAssist\KEY ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $this->page->set_enabled( true ) );
		ob_start();
		$this->page->notice();
		$this->assertSame( '', ob_get_clean() );
	}

	/** Invoke JSON endpoints with WordPress' test wp_die handler, preserving request globals. */
	private function endpoint( string $method, array $request, string $http_method = 'POST' ): array {
		$old_server                = $_SERVER;
		$old_post                  = $_POST;
		$old_request               = $_REQUEST;
		$_SERVER['REQUEST_METHOD'] = $http_method;
		$_POST                     = $request;
		$_REQUEST                  = $request;
		$terminated                = false;
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
		ob_start();
		try {
			$this->page->$method();
		} catch ( WPDieException $error ) {
			$terminated = true;
		} finally {
			$output = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );
			$_SERVER  = $old_server;
			$_POST    = $old_post;
			$_REQUEST = $old_request;
		}
		$this->assertTrue( $terminated, 'The endpoint must terminate through wp_die.' );
		return array( json_decode( $output, true ), $output );
	}

	public function test_ajax_toggle_and_status_are_protected_and_status_never_sends(): void {
		$toggle = array(
			'_wpnonce'  => wp_create_nonce( MailHealth::PAGE . '_toggle' ),
			'enabled'   => 'yes',
			'recipient' => 'ignored@example.org',
			'interval'  => '1',
		);
		$result = $this->endpoint( 'toggle', $toggle )[0];
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['data']['enabled'] );
		$this->assertSame( 'health@example.org', $this->store->read()['config']['recipient'] );
		$before = $this->store->read();
		$result = $this->endpoint( 'status', array( 'nonce' => wp_create_nonce( MailHealth::PAGE . '_status' ) ), 'GET' )[0];
		$this->assertTrue( $result['success'] );
		$this->assertSame( $before, $this->store->read() );
		$this->assertSame( array( 'enabled', 'html' ), array_keys( $result['data'] ) );
		$toggle['enabled'] = 'no';
		$this->endpoint( 'toggle', $toggle );
		$this->assertFalse( $this->store->read()['config']['enabled'] );
	}

	public function test_http_rejects_get_and_bad_nonce_without_changing_the_switch(): void {
		$request = array(
			'_wpnonce' => 'invalid',
			'enabled'  => 'yes',
		);
		$this->endpoint( 'toggle', $request, 'GET' );
		$this->endpoint( 'toggle', $request );
		$this->assertFalse( $this->store->read()['config']['enabled'] );
	}

	public function test_missing_core_success_hook_does_not_make_a_false_success(): void {
		$this->smtp();
		$this->enable();
		remove_action( 'wp_mail_succeeded', array( $this->monitor, 'succeeded' ), PHP_INT_MAX );
		$state = $this->run_probe();
		$this->assertSame( 'unknown', State::summary( $state, $this->store->now() ) );
	}

	public function test_replaced_mailer_returning_true_remains_unobservable(): void {
		$this->enable();
		$GLOBALS['phpmailer'] = new class( true ) extends \PHPMailer\PHPMailer\PHPMailer {
			public function send() {
				return true;
			}
		};
		$state                = $this->run_probe();
		$this->assertSame( 'unknown', State::summary( $state, $this->store->now() ) );
	}

	public function test_network_state_and_cleanup_are_per_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires the multisite test run.' );
		}
		$this->enable();
		$generation = $this->store->read()['generation'];
		$blog       = self::factory()->blog->create();
		add_user_to_blog( $blog, get_current_user_id(), 'administrator' );
		switch_to_blog( $blog );
		try {
			$this->assertFalse( $this->store->read()['config']['enabled'] );
			update_option( 'admin_email', 'subsite@example.org' );
			$this->enable();
			$this->assertSame( 'subsite@example.org', $this->store->read()['config']['recipient'] );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( $generation, $this->store->read()['generation'] );
		$this->scheduler->deactivate( true, true );
		$this->assertFalse( get_option( Store::OPTION ) );
		switch_to_blog( $blog );
		try {
			$this->assertFalse( get_option( Store::OPTION ) );
		} finally {
			restore_current_blog();
		}
	}

	/** @dataProvider wp_mail_smtp_modes */
	public function test_wp_mail_smtp_490_compatibility( bool $blocked, bool $queued = false ): void {
		if ( ! function_exists( 'wp_mail_smtp' ) ) {
			$this->markTestSkipped( 'Requires the isolated WP Mail SMTP bootstrap.' );
		}
		$this->assertSame( '4.9.0', WPMS_PLUGIN_VER );
		$options = array(
			'mail'    => array(
				'mailer'     => 'smtp',
				'from_email' => 'site@example.org',
				'from_name'  => 'Fixture',
			),
			'smtp'    => array(
				'host'       => '127.0.0.1',
				'port'       => 25,
				'encryption' => 'none',
				'autotls'    => false,
				'auth'       => false,
			),
			'general' => array( 'do_not_send' => $blocked ),
		);
		if ( ! $blocked && ! $queued ) {
			$this->smtp();
			$options['smtp']['port'] = $this->fixture[2];
		}
		if ( $queued ) {
			add_filter( 'wp_mail_smtp_queue_is_enabled', '__return_true' );
			add_filter( 'wp_mail_smtp_mail_catcher_send_enqueue_email', '__return_true' );
			( new \WPMailSMTP\Queue\Migration() )->init();
		}
		\WPMailSMTP\Options::init()->set( $options );
		wp_mail_smtp()->replace_phpmailer();
		$this->enable();
		$state = $this->run_probe();
		$this->assertSame( $queued ? 'unobservable' : ( $blocked ? 'failed' : 'accepted' ), $state['attempt']['status'] );
		$this->assertSame( $queued ? 'unknown' : ( $blocked ? 'failed' : 'ok' ), State::summary( $state, $this->store->now() ) );
	}

	public function wp_mail_smtp_modes(): array {
		return array( array( false ), array( true ), array( false, true ) );
	}

	/** @dataProvider mailpit_transports */
	public function test_local_mailpit_capture( bool $smtp ): void {
		if ( '1' !== getenv( 'DA_MAILPIT_TEST' ) ) {
			$this->markTestSkipped( 'Requires local Mailpit.' );
		}
		$this->configure_mailer = static function ( $mailer ) use ( $smtp ): void {
			if ( ! $smtp ) {
				$mailer->isMail();
				return;
			}
			$mailer->isSMTP();
			$mailer->Host        = 'mailpit';
			$mailer->Port        = 1025;
			$mailer->SMTPAutoTLS = false;
			$mailer->SMTPAuth    = false;
			$mailer->SMTPSecure  = '';
		};
		add_action( 'phpmailer_init', $this->configure_mailer, 100 );
		unset( $GLOBALS['phpmailer'] );
		$this->enable();
		$state = $this->run_probe();
		$this->assertSame( 'ok', State::summary( $state, $this->store->now() ) );
		$response = wp_remote_get( add_query_arg( 'query', 'subject:' . $state['attempt']['id'], 'http://mailpit:8025/api/v1/search' ) );
		$this->assertNotWPError( $response );
		$messages = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->assertCount( 1, $messages['messages'] );
	}

	public function mailpit_transports(): array {
		return array( array( true ), array( false ) );
	}

	public function test_invalid_from_address_is_a_sender_error(): void {
		$this->assertSame( 'invalid_sender', State::error_category( 'Invalid address: (From): wordpress@localhost' ) );
		$this->assertSame( 'recipient', State::error_category( 'Invalid address: (to): invalid' ) );
	}

	public function test_real_invalid_sender_shows_address_stage_and_recovery_action(): void {
		update_option( 'home', 'https://localhost:21601' );
		add_filter(
			'wp_mail_from',
			static function (): string {
				return 'wordpress@localhost';
			}
		);
		unset( $GLOBALS['phpmailer'] );
		$this->enable();
		$state = $this->run_probe();
		$this->assertSame( 'invalid_sender', $state['error'] );
		$this->assertSame( array( 'sender' => 'wordpress@localhost' ), $state['diagnostic'] );
		$this->assertSame( $state['diagnostic'], $state['attempt']['diagnostic'] );
		$this->assertSame( '', $state['route'] );
		$this->assertSame( '', $state['retry_root'], 'Local validation is not a temporary SMTP transport failure.' );
		$html = $this->page->status_html();
		$this->assertStringContainsString( 'Invalid sender address', $html );
		$this->assertStringContainsString( 'wordpress@localhost', $html );
		$this->assertStringContainsString( 'before handing it to the mail service', $html );
		$this->assertStringContainsString( 'What to check:', $html );
		$this->assertStringContainsString( 'SMTP plugin or site mail configuration', $html );
		$this->assertStringContainsString( 'wordpress@example.test', $html );
		ob_start();
		$this->page->notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'Invalid sender address', $notice );
		$this->assertStringContainsString( 'Set a valid From address', $notice );
	}

	public function test_invalid_sender_diagnosis_supports_translated_phpmailer_errors(): void {
		$translate = static function ( string $translation, string $text, string $domain ): string {
			return 'default' === $domain && 'Invalid address:' === $text ? 'Некоректна адреса:' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );
		add_filter(
			'wp_mail_from',
			static function (): string {
				return 'wordpress@localhost';
			}
		);
		unset( $GLOBALS['phpmailer'] );
		try {
			$this->enable();
			$state = $this->run_probe();
			$this->assertSame( 'invalid_sender', $state['error'] );
			$this->assertSame( array( 'sender' => 'wordpress@localhost' ), $state['diagnostic'] );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
			if ( isset( $GLOBALS['phpmailer'] ) ) {
				$GLOBALS['phpmailer']->setLanguage();
			}
		}
	}

	public function test_ordinary_message_sender_is_not_persisted_in_diagnostics(): void {
		$this->enable();
		add_filter(
			'wp_mail_from',
			static function (): string {
				return 'private-customer@local';
			}
		);
		unset( $GLOBALS['phpmailer'] );
		$this->assertFalse( wp_mail( 'private-recipient@example.org', 'Private subject', 'Private body' ) );
		$state = $this->store->read();
		$this->assertSame( 'invalid_sender', $state['error'] );
		$this->assertEmpty( $state['diagnostic'] );
		$this->assertStringNotContainsString( 'private-customer', wp_json_encode( $state ) );
		$this->assertStringNotContainsString( 'private-recipient', wp_json_encode( $state ) );
		$this->assertStringNotContainsString( 'Private', wp_json_encode( $state ) );
	}

	public function test_sender_extraction_drops_unbounded_raw_or_unsafe_values(): void {
		$this->assertSame( 'wordpress@localhost', State::sender_from_error( 'Invalid address: (From): wordpress@localhost' ) );
		foreach ( array( 'Invalid address: (From): <script>alert(1)</script>@localhost', "Invalid address: (From): site@localhost\nAUTH password=secret", 'Invalid address: (From): https://user:secret@localhost', 'Invalid address: (From): ' . str_repeat( 'x', 260 ) . '@localhost', 'Invalid address: (From): bearer-secret', 'Mailer failure with token=secret' ) as $message ) {
			$this->assertSame( '', State::sender_from_error( $message ) );
		}
		$this->assertSame( '', State::safe_sender( array( 'unexpected' ) ) );
		$this->assertSame( '', State::safe_sender( 'site@localhost<script>' ) );
	}

	public function test_recovery_clears_previous_sender_diagnostics(): void {
		$this->enable();
		$this->store->change(
			function ( array $state ): array {
				$state['error']       = 'invalid_sender';
				$state['diagnostic']  = array( 'sender' => 'old@localhost' );
				$state['incident']    = 'old-incident';
				$state['transport']   = 'failed';
				$state['observed_at'] = $this->store->now();
				return $state;
			}
		);
		$this->smtp();
		$state = $this->run_probe();
		$this->assertSame( 'ok', State::summary( $state, $this->store->now() ) );
		$this->assertEmpty( $state['diagnostic'] );
		$this->assertEmpty( $state['attempt']['diagnostic'] );
		$this->assertStringNotContainsString( 'old@localhost', wp_json_encode( $state ) );
	}

	/** @dataProvider diagnostic_guidance */
	public function test_failure_status_explains_the_cause_and_action( string $category, string $cause, string $action ): void {
		$this->enable();
		$this->store->change(
			static function ( array $state ) use ( $category ): array {
				$state['attempt']['status'] = 'failed';
				$state['attempt']['error']  = $category;
				return $state;
			}
		);
		$html = $this->page->status_html();
		$this->assertStringContainsString( $cause, $html );
		$this->assertStringContainsString( 'What to check:', $html );
		$this->assertStringContainsString( $action, $html );
	}

	public function diagnostic_guidance(): array {
		return array(
			array( 'authentication', 'could not authenticate', 'SMTP credentials' ),
			array( 'tls', 'TLS or certificate', 'encryption mode' ),
			array( 'dns', 'could not resolve', 'DNS configuration' ),
			array( 'connection', 'failed while connecting', 'outbound firewall' ),
			array( 'timeout', 'transport time limit', 'hosting network' ),
			array( 'quota', 'exhausted sending quota', 'provider dashboard' ),
			array( 'throttled', 'limiting sending requests', 'automatic retry' ),
			array( 'recipient', 'invalid or rejected', 'Administration Email Address' ),
			array( 'message', 'attachment, or encoding', 'plugin that generated' ),
			array( 'sender', 'could not be used or was rejected', 'allowed to send for that domain' ),
			array( 'unknown', 'does not identify the cause', 'mail plugin diagnostics' ),
		);
	}

	public function test_delayed_check_is_explained_as_a_scheduler_problem(): void {
		$this->enable();
		$this->store->clock += 31;
		$html                = $this->page->status_html();
		$this->assertStringContainsString( 'Mail service availability is still unknown', $html );
		$this->assertStringContainsString( 'hosting cron runner', $html );
		$this->assertStringNotContainsString( 'SMTP credentials', $html );
	}


	public function test_history_is_bounded_and_contains_no_worker_token_in_public_view(): void {
		$state = State::defaults();
		for ( $i = 0; $i < 40; ++$i ) {
			$state = State::event( $state, 'test', $this->store->now() + $i );
		}
		$this->assertCount( 20, $state['events'] );
		$this->assertEmpty( State::prune( $state, $this->store->now() + 8 * DAY_IN_SECONDS )['events'] );
		$state['lease'] = array( 'token' => 'private' );
		$this->assertArrayNotHasKey( 'lease', State::view( $state, $this->store->now() ) );
	}
}

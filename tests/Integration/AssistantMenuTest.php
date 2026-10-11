<?php

declare(strict_types=1);

use WPDevAssist\Setting;

require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

class AssistantMenuTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		update_option( Setting::ENABLE_ASSISTANT_KEY, 'yes' );
	}

	public function tear_down(): void {
		delete_option( Setting::ENABLE_ASSISTANT_KEY );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_menu_exposes_status_and_admin_actions(): void {
		$admin_bar = $this->build_admin_bar();
		$root      = $admin_bar->get_node( 'wp_dev_assist_assistant' );
		$summary   = $admin_bar->get_node( 'wp_dev_assist_assistant_group_0_summary' );

		$this->assertNotNull( $root );
		$this->assertStringContainsString( 'Development Assistant', $root->title );
		$this->assertStringContainsString( 'screen-reader-text', $root->title );
		$this->assertStringNotContainsString( 'ab-label', $root->title );
		$this->assertStringNotContainsString( 'da-assistant__indicator', $root->title );
		$this->assertStringContainsString( 'da-assistant_', $root->meta['class'] );
		$this->assertSame( 0, $root->meta['tabindex'] );
		$this->assertFalse( $root->href );
		$this->assertNotNull( $summary );
		$this->assertStringContainsString( 'WP Debug', $summary->title );
		$this->assertStringNotContainsString( 'production', strtolower( $summary->title ) );

		$action_urls = array();

		for ( $index = 0; $index < 6; $index++ ) {
			$action = $admin_bar->get_node( 'wp_dev_assist_assistant_group_0_action_' . $index );

			if ( null !== $action && $action->href ) {
				$action_urls[] = html_entity_decode( $action->href );
			}
		}

		$this->assertNotEmpty( $action_urls );
		$this->assertEmpty(
			array_filter(
				$action_urls,
				static function ( string $url ): bool {
					return 0 !== strpos( $url, admin_url() );
				}
			)
		);
		$this->assertNotEmpty(
			array_filter(
				$action_urls,
				static function ( string $url ): bool {
					return false !== strpos( $url, '_wpnonce=' );
				}
			)
		);
	}

	public function test_menu_is_hidden_when_disabled(): void {
		update_option( Setting::ENABLE_ASSISTANT_KEY, 'no' );

		$this->assertNull( $this->build_admin_bar()->get_node( 'wp_dev_assist_assistant' ) );
	}

	public function test_delete_log_action_always_requires_confirmation(): void {
		$this->assert_delete_log_confirmation( 'Are you sure to delete the debug.log file? This action is irreversible.' );
	}

	public function test_delete_log_confirmation_preserves_translated_special_characters(): void {
		$confirmation = "Видалити \"debug.log\"? It's irreversible.\nC:\\logs\\debug.log\r\n<script>alert('test')</script> &quot; % 😊";
		$translate    = static function ( string $translation, string $text, string $domain ) use ( $confirmation ): string {
			if ( 'development-assistant' === $domain && 'Are you sure to delete the debug.log file? This action is irreversible.' === $text ) {
				return $confirmation;
			}

			return $translation;
		};

		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$this->assert_delete_log_confirmation( $confirmation );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}
	}

	private function assert_delete_log_confirmation( string $confirmation ): void {
		$debug_log_path    = WP_CONTENT_DIR . '/debug.log';
		$created_test_log  = false;
		$existing_log_file = file_exists( $debug_log_path );

		try {
			if ( ! $existing_log_file ) {
				$created_test_log = false !== file_put_contents( $debug_log_path, 'test log' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$this->assertTrue( $created_test_log, 'Could not create the test debug log.' );
			}

			$delete_action = null;

			foreach ( $this->build_admin_bar()->get_nodes() as $node ) {
				if ( 'Delete log file' === wp_strip_all_tags( $node->title ) ) {
					$delete_action = $node;
					break;
				}
			}

			$this->assertNotNull( $delete_action );
			$this->assertArrayHasKey( 'onclick', $delete_action->meta );
			$this->assertSame(
				1,
				preg_match( '/^return confirm\(decodeURIComponent\("([A-Za-z0-9_.~%\-]*)"\)\)$/', $delete_action->meta['onclick'], $matches )
			);
			$this->assertSame( $confirmation, rawurldecode( $matches[1] ) );

			ob_start();
			$this->build_admin_bar()->render();
			$rendered_admin_bar = ob_get_clean();

			preg_match_all( '/onclick=\'return confirm\(decodeURIComponent\(&quot;([A-Za-z0-9_.~%\-]*)&quot;\)\)\'/', $rendered_admin_bar, $rendered_matches );
			$this->assertContains( $confirmation, array_map( 'rawurldecode', $rendered_matches[1] ) );
		} finally {
			if ( $created_test_log && file_exists( $debug_log_path ) ) {
				unlink( $debug_log_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	public function test_menu_is_hidden_from_users_without_administrator_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->assertNull( $this->build_admin_bar()->get_node( 'wp_dev_assist_assistant' ) );
	}

	private function build_admin_bar(): WP_Admin_Bar {
		$admin_bar = new WP_Admin_Bar();
		$admin_bar->initialize();
		do_action_ref_array( 'admin_bar_menu', array( &$admin_bar ) );

		return $admin_bar;
	}
}

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
		$this->assertFalse( $root->href );
		$this->assertNotNull( $summary );
		$this->assertStringContainsString( 'WP Debug', $summary->title );

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

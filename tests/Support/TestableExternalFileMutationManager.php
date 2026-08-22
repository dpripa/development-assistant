<?php

declare(strict_types=1);

namespace WPDevAssist\Tests\Support;

use WPDevAssist\ExternalFileMutationManager;

class TestableExternalFileMutationManager extends ExternalFileMutationManager {
	private string $fixture_root;

	public function __construct( string $fixture_root ) {
		$this->fixture_root = $fixture_root;
	}

	public function get_target_path( string $target ) {
		if ( static::TARGET_WP_CONFIG === $target ) {
			return $this->fixture_root . '/wp-config.php';
		}

		if ( static::TARGET_HTACCESS === $target ) {
			return $this->fixture_root . '/.htaccess';
		}

		return parent::get_target_path( $target );
	}

	protected function get_recovery_directory_path(): string {
		return $this->fixture_root . '/recovery';
	}

	protected function get_debug_log_path(): string {
		return $this->fixture_root . '/debug.log';
	}
}

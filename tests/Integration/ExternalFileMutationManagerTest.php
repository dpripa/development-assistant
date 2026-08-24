<?php

declare(strict_types=1);

use WPDevAssist\ExternalFileMutationManager;
use WPDevAssist\Htaccess;
use WPDevAssist\AdminNotice;
use WPDevAssist\DebugConfigEditor;
use WPDevAssist\WPDebug;
use WPDevAssist\Tests\Support\TestableExternalFileMutationManager;

class ExternalFileMutationManagerTest extends WP_UnitTestCase {
	private string $fixture_root;
	private TestableExternalFileMutationManager $manager;

	public function set_up(): void {
		parent::set_up();

		$this->fixture_root = sys_get_temp_dir() . '/da-external-files-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->fixture_root, 0700, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$this->manager = new TestableExternalFileMutationManager( $this->fixture_root );
	}

	public function tear_down(): void {
		$this->remove_fixture_tree( $this->fixture_root );
		parent::tear_down();
	}

	public function test_configuration_transaction_preserves_metadata_and_creates_protected_baseline(): void {
		$config_path = $this->fixture_root . '/wp-config.php';
		$config      = "<?php\ndefine( 'DB_PASSWORD', 'secret' );\ndefine( 'WP_DEBUG', false );\n\$table_prefix = 'wp_';\n";
		file_put_contents( $config_path, $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		chmod( $config_path, 0640 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		$original_owner = fileowner( $config_path );
		$original_group = filegroup( $config_path );

		$result = $this->manager->mutate(
			ExternalFileMutationManager::TARGET_WP_CONFIG,
			static function ( string $content ): string {
				return str_replace( "define( 'WP_DEBUG', false );", "define( 'WP_DEBUG', true );", $content );
			},
			static function ( string $content ): bool {
				return false !== strpos( $content, "define( 'WP_DEBUG', true );" );
			}
		);

		$this->assertSame( array( 'changed' => true ), $result );
		$this->assertSame( 0640, fileperms( $config_path ) & 0777 );
		$this->assertSame( $original_owner, fileowner( $config_path ) );
		$this->assertSame( $original_group, filegroup( $config_path ) );
		$this->assertStringContainsString( "define( 'WP_DEBUG', true );", (string) file_get_contents( $config_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$baseline_path    = $this->fixture_root . '/recovery/wp-config-baseline.php';
		$baseline_payload = (string) file_get_contents( $baseline_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 0600, fileperms( $baseline_path ) & 0777 );
		$this->assertStringStartsWith( "<?php exit; __halt_compiler(); ?>\n", $baseline_payload );
		$this->assertStringNotContainsString( 'secret', $baseline_payload );
	}

	public function test_invalid_or_rejected_mutations_leave_configuration_unchanged(): void {
		$config_path = $this->create_config_fixture();

		$invalid = $this->manager->mutate(
			ExternalFileMutationManager::TARGET_WP_CONFIG,
			static function () {
				return false;
			},
			'__return_true'
		);
		$this->assertWPError( $invalid );
		$this->assertSame( $this->config_fixture_content(), file_get_contents( $config_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$rejected = $this->manager->mutate(
			ExternalFileMutationManager::TARGET_WP_CONFIG,
			static function ( string $content ): string {
				return $content . 'invalid';
			},
			'__return_false'
		);
		$this->assertWPError( $rejected );
		$this->assertSame( $this->config_fixture_content(), file_get_contents( $config_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$thrown = $this->manager->mutate(
			ExternalFileMutationManager::TARGET_WP_CONFIG,
			static function ( string $content ): string {
				return $content . "\n// candidate\n";
			},
			static function (): bool {
				throw new RuntimeException( 'Fixture validator failure.' );
			}
		);
		$this->assertWPError( $thrown );
		$this->assertSame( $this->config_fixture_content(), file_get_contents( $config_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_corrupted_baseline_blocks_the_next_mutation(): void {
		$config_path = $this->create_config_fixture();
		$first       = $this->manager->mutate(
			ExternalFileMutationManager::TARGET_WP_CONFIG,
			static function ( string $content ): string {
				return $content . "// first change\n";
			},
			'__return_true'
		);
		$this->assertSame( array( 'changed' => true ), $first );

		$before = (string) file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		file_put_contents( $this->fixture_root . '/recovery/wp-config-baseline.php', 'corrupted' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = $this->manager->mutate(
			ExternalFileMutationManager::TARGET_WP_CONFIG,
			static function ( string $content ): string {
				return $content . "// blocked\n";
			},
			'__return_true'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'external_file_backup_invalid', $result->get_error_code() );
		$this->assertSame( $before, file_get_contents( $config_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_wordpress_file_modification_policy_blocks_changes(): void {
		$config_path = $this->create_config_fixture();
		add_filter( 'file_mod_allowed', '__return_false' );

		try {
			$result = $this->manager->mutate(
				ExternalFileMutationManager::TARGET_WP_CONFIG,
				static function ( string $content ): string {
					return $content . 'changed';
				},
				'__return_true'
			);
		} finally {
			remove_filter( 'file_mod_allowed', '__return_false' );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'external_file_modification_disallowed', $result->get_error_code() );
		$this->assertSame( $this->config_fixture_content(), file_get_contents( $config_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_htaccess_marker_changes_preserve_surrounding_rules_and_reject_malformed_markers(): void {
		$path     = $this->fixture_root . '/.htaccess';
		$original = "# BEGIN WordPress\nwordpress-rules\n# END WordPress\n";
		file_put_contents( $path, $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$htaccess = new Htaccess( $this->manager );

		$this->assertTrue( $htaccess->replace( 'wp_dev_assist_debug_log', 'Require all denied' ) );
		$this->assertStringContainsString( '# BEGIN WordPress', (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertTrue( $htaccess->remove( 'wp_dev_assist_debug_log' ) );
		$this->assertSame( $original, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$malformed = "# BEGIN broken\nmissing end\n";
		file_put_contents( $path, $malformed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = $htaccess->replace( 'broken', 'new' );
		$this->assertWPError( $result );
		$this->assertSame( $malformed, file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public function test_debug_log_access_directives_are_written_as_valid_separate_lines(): void {
		$path     = $this->fixture_root . '/.htaccess';
		$original = "# BEGIN WordPress\n# END WordPress\n";
		file_put_contents( $path, $original ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$wp_debug = new WPDebug(
			$this->createMock( AdminNotice::class ),
			$this->manager,
			new Htaccess( $this->manager ),
			new DebugConfigEditor()
		);

		$this->assertNull( $wp_debug->add_htaccess_directives() );

		$content = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( '\\n', $content );
		$this->assertStringNotContainsString( '\\t', $content );
		$this->assertStringContainsString(
			"<If \"%{REQUEST_URI} =~ m#^/wp-content/debug.log#\">\n"
			. "\t<IfModule mod_authz_core.c>\n"
			. "\t\tRequire all denied\n"
			. "\t</IfModule>\n"
			. "\t<IfModule !mod_authz_core.c>\n"
			. "\t\tOrder deny,allow\n"
			. "\t\tDeny from all\n"
			. "\t</IfModule>\n"
			. '</If>',
			$content
		);
	}

	public function test_registered_debug_log_and_temporary_archive_cleanup(): void {
		$debug_log = $this->fixture_root . '/debug.log';
		file_put_contents( $debug_log, 'log' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertNull( $this->manager->delete_debug_log() );
		$this->assertFileDoesNotExist( $debug_log );

		$archive = $this->manager->create_temporary_archive( 'plugin.zip' );
		$this->assertIsString( $archive );
		$this->assertFileExists( $archive );
		$this->assertFalse( $this->manager->delete_temporary_archive( $archive . '.unregistered' ) );
		$this->assertTrue( $this->manager->delete_temporary_archive( $archive ) );
		$this->assertFileDoesNotExist( $archive );
	}

	private function create_config_fixture(): string {
		$path = $this->fixture_root . '/wp-config.php';
		file_put_contents( $path, $this->config_fixture_content() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $path;
	}

	private function config_fixture_content(): string {
		return "<?php\ndefine( 'WP_DEBUG', false );\n\$table_prefix = 'wp_';\n";
	}

	private function remove_fixture_tree( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				chmod( $item->getPathname(), 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

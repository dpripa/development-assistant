<?php

declare(strict_types=1);

use WPDevAssist\PluginsScreen\DownloadExclusions;
use WPDevAssist\PluginsScreen\Downloader;
use WPDevAssist\Setting;
use WPDevAssist\Setting\Control;

class PluginDownloadExclusionsTest extends WP_UnitTestCase {
	private string $fixture_root;
	private Downloader $downloader;

	public function set_up(): void {
		parent::set_up();
		$this->fixture_root = sys_get_temp_dir() . '/da-download-exclusions-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->fixture_root, 0700, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		$this->downloader = ( new ReflectionClass( Downloader::class ) )->newInstanceWithoutConstructor();
		delete_option( DownloadExclusions::OPTION );
	}

	public function tear_down(): void {
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->fixture_root, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $files as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				rmdir( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				unlink( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		rmdir( $this->fixture_root ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		delete_option( DownloadExclusions::OPTION );
		unregister_setting( Setting::KEY, DownloadExclusions::OPTION );
		parent::tear_down();
	}

	public function test_setting_registration_persistence_empty_list_and_rendering(): void {
		$app_setting = new ReflectionProperty( WPDevAssist\App::class, 'setting' );
		$app_setting->setAccessible( true );
		$setting = $app_setting->getValue( WPDevAssist\app() );
		$section = new ReflectionMethod( Setting::class, 'add_plugin_screen_section' );
		$section->setAccessible( true );
		$section->invoke( $setting, Setting::KEY . '_plugins_screen' );

		global $wp_settings_fields;
		$field = $wp_settings_fields[ Setting::KEY ][ Setting::KEY . '_plugins_screen' ][ DownloadExclusions::OPTION ];
		$this->assertSame( 'render_textarea', $field['callback'][1] );
		$this->assertTrue( ( new DownloadExclusions() )->matches( 'nested/node_modules/file.js' ) );

		update_option( DownloadExclusions::OPTION, " node_modules \r\n\nassets\\source/\nnode_modules\n.DS_Store " );
		$this->assertSame( "node_modules\nassets/source/\n.DS_Store", get_option( DownloadExclusions::OPTION ) );
		$this->assertTrue( ( new DownloadExclusions() )->matches( 'assets/source/file.js' ) );

		ob_start();
		call_user_func( $field['callback'], $field['args'] );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'node_modules', $html );
		$this->assertStringContainsString( 'aria-describedby=', $html );

		update_option( DownloadExclusions::OPTION, '' );
		$this->assertSame( '', get_option( DownloadExclusions::OPTION ) );
		$this->assertFalse( ( new DownloadExclusions() )->matches( 'node_modules/file.js' ) );
		$setting->add_default_options();
		$this->assertSame( '', get_option( DownloadExclusions::OPTION ) );

		update_option( DownloadExclusions::OPTION, '</textarea><script>alert(1)</script>' );
		ob_start();
		( new Control() )->render_textarea( $field['args'] );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;/textarea&gt;', $html );
	}

	/** @dataProvider matching_cases */
	public function test_literal_matching( string $rules, string $path, bool $directory, bool $expected ): void {
		update_option( DownloadExclusions::OPTION, $rules );
		$this->assertSame( $expected, ( new DownloadExclusions() )->matches( $path, $directory ) );
	}

	public function matching_cases(): array {
		return array(
			array( 'node_modules', 'node_modules/index.js', false, true ),
			array( 'node_modules', 'nested/node_modules/index.js', false, true ),
			array( 'node_modules', 'node_modules', false, true ),
			array( 'node_modules', 'node_modules-extra/index.js', false, false ),
			array( 'node_modules', 'Node_Modules/index.js', false, false ),
			array( '.DS_Store', 'nested/.DS_Store', false, true ),
			array( 'assets/source', 'assets/source/index.js', false, true ),
			array( 'assets/source', 'nested/assets/source/index.js', false, false ),
			array( 'assets/source', 'assets/source-map/index.js', false, false ),
			array( 'config/local.json', 'config/local.json', false, true ),
			array( 'config/local.json', 'nested/config/local.json', false, false ),
			array( 'cache/', 'cache', false, false ),
			array( 'cache/', 'cache', true, true ),
			array( 'cache/', 'nested/cache/file', false, true ),
			array( 'assets/source/', 'assets/source', false, false ),
			array( 'assets/source/', 'assets/source', true, true ),
			array( 'assets/source/', 'nested/assets/source', true, false ),
			array( '*.map', 'bundle.map', false, false ),
			array( '*.map', '*.map', false, true ),
			array( '', 'node_modules/file', false, false ),
		);
	}

	/** @dataProvider invalid_cases */
	public function test_invalid_input_keeps_previous_list( $input ): void {
		update_option( DownloadExclusions::OPTION, 'custom/' );
		$this->assertSame( 'custom/', DownloadExclusions::sanitize( $input ) );
		$errors = get_settings_errors( DownloadExclusions::OPTION );
		$this->assertSame( 'invalid_download_exclusions', end( $errors )['code'] );
	}

	public function invalid_cases(): array {
		return array(
			array( '/etc/passwd' ),
			array( '\\absolute' ),
			array( '\\\\server\\share' ),
			array( 'C:\\files' ),
			array( 'C:files' ),
			array( '../outside' ),
			array( 'nested/../outside' ),
			array( './folder' ),
			array( '.' ),
			array( "file\0name" ),
			array( "file\tname" ),
			array( array( 'node_modules' ) ),
			array( null ),
		);
	}

	public function test_zip_contents_across_plugins_and_source_files_unchanged(): void {
		$files = array( 'main.php', 'node_modules/index.js', 'nested/node_modules/index.js', 'node_modules-extra/index.js', 'vendor/autoload.php', 'assets/bundle.js', 'assets/source/index.js', 'nested/assets/source/index.js', 'config/local.json', '.DS_Store', 'nested/.DS_Store' );
		foreach ( array( 'first', 'second' ) as $plugin ) {
			$source = $this->create_plugin( $plugin, $files );
			$before = $this->source_hashes( $source['path'], $files );
			$names  = $this->archive( $source );
			$this->assertNotContains( $plugin . '/node_modules/index.js', $names );
			$this->assertNotContains( $plugin . '/nested/node_modules/index.js', $names );
			$this->assertContains( $plugin . '/vendor/autoload.php', $names );
			$this->assertContains( $plugin . '/assets/bundle.js', $names );

			update_option( DownloadExclusions::OPTION, "node_modules\nassets/source/\nconfig/local.json\n.DS_Store" );
			$this->assertSame(
				array( $plugin . '/assets/bundle.js', $plugin . '/main.php', $plugin . '/nested/assets/source/index.js', $plugin . '/node_modules-extra/index.js', $plugin . '/vendor/autoload.php' ),
				$this->archive( $source )
			);
			update_option( DownloadExclusions::OPTION, '' );
			$this->assertCount( count( $files ), $this->archive( $source ) );
			$this->assertSame( $before, $this->source_hashes( $source['path'], $files ) );
			delete_option( DownloadExclusions::OPTION );
		}
	}

	public function test_expanded_defaults_exclude_development_junk_and_preserve_saved_lists(): void {
		$junk_files = array(
			'node_modules/index.js',
			'.git/config',
			'.svn/entries',
			'.hg/store/data',
			'.idea/workspace.xml',
			'.vscode/settings.json',
			'.DS_Store',
			'Thumbs.db',
			'desktop.ini',
			'__MACOSX/metadata',
			'.phpunit.cache/test-results',
			'.phpunit.result.cache',
			'.eslintcache',
			'.stylelintcache',
			'.sass-cache/cache',
			'npm-debug.log',
			'yarn-debug.log',
			'yarn-error.log',
			'.pnpm-debug.log',
		);
		$junk_files = array_merge(
			$junk_files,
			array_map(
				static function ( string $file ): string {
					return 'nested/' . $file;
				},
				$junk_files
			)
		);
		$keep_files = array(
			'main.php',
			'vendor/autoload.php',
			'dist/plugin.js',
			'build/plugin.js',
			'assets/bundle.js',
			'cache/runtime.php',
			'logs/runtime.log',
			'node_modules-extra/index.js',
			'Thumbs.db.backup',
		);
		$source     = $this->create_plugin( 'defaults', array_merge( $junk_files, $keep_files ) );
		$expected   = array_map(
			static function ( string $file ): string {
				return 'defaults/' . $file;
			},
			$keep_files
		);
		sort( $expected );
		$this->assertSame( $expected, $this->archive( $source ) );
		$this->assertTrue( ( new DownloadExclusions() )->matches( '.git' ) );
		$this->assertFalse( ( new DownloadExclusions() )->matches( '.idea' ) );

		update_option( DownloadExclusions::OPTION, 'node_modules' );
		$this->assertContains( 'defaults/.git/config', $this->archive( $source ) );
		$this->assertNotContains( 'defaults/node_modules/index.js', $this->archive( $source ) );
		$this->assertSame( 'node_modules', get_option( DownloadExclusions::OPTION ) );
		update_option( DownloadExclusions::OPTION, '.DS_Store' );
		$this->assertContains( 'defaults/node_modules/index.js', $this->archive( $source ) );
		$this->assertNotContains( 'defaults/.DS_Store', $this->archive( $source ) );
		$this->assertSame( '.DS_Store', get_option( DownloadExclusions::OPTION ) );
		update_option( DownloadExclusions::OPTION, '' );
		$this->assertCount( count( $junk_files ) + count( $keep_files ), $this->archive( $source ) );
		$this->assertSame( '', get_option( DownloadExclusions::OPTION ) );
	}

	public function test_excluded_tree_is_not_traversed_and_other_invalid_files_still_fail(): void {
		$source = $this->create_plugin( 'pruning', array( 'main.php', 'node_modules/keep.js' ) );
		symlink( $this->fixture_root . '/missing', $source['path'] . '/node_modules/broken' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_symlink
		$this->assertSame( array( 'pruning/main.php' ), $this->archive( $source ) );
		update_option( DownloadExclusions::OPTION, '' );
		$this->assertSame( 'plugin_file_invalid', $this->archive_error( $source )->get_error_code() );
	}

	public function test_main_file_and_ancestor_exclusions_fail_for_directory_and_single_file_plugins(): void {
		$source = $this->create_plugin( 'protected', array( 'main.php' ) );
		update_option( DownloadExclusions::OPTION, 'main.php' );
		$this->assertSame( 'plugin_main_file_excluded', $this->archive_error( $source )->get_error_code() );
		$source['main_file'] = 'nested/main.php';
		update_option( DownloadExclusions::OPTION, 'nested/' );
		$this->assertSame( 'plugin_main_file_excluded', $this->archive_error( $source )->get_error_code() );

		$source['path']          .= '/main.php';
		$source['main_file']      = 'main.php';
		$source['is_single_file'] = true;
		update_option( DownloadExclusions::OPTION, '' );
		$this->assertSame( array( 'main.php' ), $this->archive( $source ) );
		update_option( DownloadExclusions::OPTION, 'main.php' );
		$this->assertSame( 'plugin_main_file_excluded', $this->archive_error( $source )->get_error_code() );
	}

	public function test_nonexcluded_external_symlink_still_fails_boundary_check(): void {
		$source = $this->create_plugin( 'boundary', array( 'main.php' ) );
		file_put_contents( $this->fixture_root . '/outside.php', '<?php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		symlink( $this->fixture_root . '/outside.php', $source['path'] . '/outside.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_symlink
		$this->assertSame( 'plugin_file_invalid', $this->archive_error( $source )->get_error_code() );
	}

	private function create_plugin( string $name, array $files ): array {
		$directory = $this->fixture_root . '/' . $name;
		foreach ( $files as $file ) {
			wp_mkdir_p( dirname( $directory . '/' . $file ) );
			file_put_contents( $directory . '/' . $file, '<?php // fixture' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return array(
			'path'           => $directory,
			'plugins_root'   => $this->fixture_root,
			'archive_root'   => $name,
			'is_single_file' => false,
			'main_file'      => 'main.php',
			'requested_file' => 'main.php',
		);
	}

	private function source_hashes( string $directory, array $files ): array {
		return array_map(
			static function ( string $file ) use ( $directory ): string {
				return hash_file( 'sha256', $directory . '/' . $file );
			},
			$files
		);
	}

	/** @return array|WP_Error */
	private function build_archive( array $source ) {
		$archive_path = $this->fixture_root . '/plugin.zip';
		$zip          = new ZipArchive();
		$this->assertTrue( $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$method = new ReflectionMethod( Downloader::class, 'add_plugin_files' );
		$method->setAccessible( true );
		try {
			$error = $method->invoke( $this->downloader, $zip, $source );
		} finally {
			$zip->close();
		}
		if ( $error instanceof WP_Error ) {
			return $error;
		}
		$this->assertTrue( $zip->open( $archive_path ) );
		$names = array();
		for ( $index = 0; $index < $zip->numFiles; $index++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$names[] = $zip->getNameIndex( $index );
		}
		$zip->close();
		sort( $names );
		return $names;
	}

	private function archive( array $source ): array {
		$result = $this->build_archive( $source );
		$this->assertIsArray( $result );
		return $result;
	}

	private function archive_error( array $source ): WP_Error {
		$result = $this->build_archive( $source );
		$this->assertInstanceOf( WP_Error::class, $result );
		return $result;
	}
}

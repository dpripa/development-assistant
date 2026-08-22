<?php

declare(strict_types=1);

use WPDevAssist\DebugConfigEditor;

class DebugConfigEditorTest extends WP_UnitTestCase {
	/**
	 * @dataProvider supported_boolean_declarations
	 */
	public function test_updates_supported_boolean_declarations( string $declaration, string $expected ): void {
		$editor  = new DebugConfigEditor();
		$config  = "<?php\n// prefix\n{$declaration}\n\$table_prefix = 'wp_';\n// suffix\n";
		$updated = $editor->set( $config, 'WP_DEBUG', true );

		$this->assertIsString( $updated );
		$this->assertStringContainsString( $expected, $updated );
		$this->assertStringContainsString( "// prefix\n", $updated );
		$this->assertStringContainsString( "// suffix\n", $updated );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function supported_boolean_declarations(): array {
		return array(
			'define with single quotes' => array( "define( 'WP_DEBUG', false );", "define( 'WP_DEBUG', true );" ),
			'define with double quotes' => array( 'define( "WP_DEBUG" , FALSE );', 'define( "WP_DEBUG" , true );' ),
			'compact const'             => array( 'const WP_DEBUG=false;', 'const WP_DEBUG=true;' ),
			'spaced const'              => array( "const   WP_DEBUG\t = FALSE ;", "const   WP_DEBUG\t = true ;" ),
		);
	}

	public function test_preserves_custom_debug_log_path_across_disable_and_restore(): void {
		$editor     = new DebugConfigEditor();
		$config     = "<?php\nconst WP_DEBUG_LOG = '/tmp/custom.log';\n\$table_prefix = 'wp_';\n";
		$definition = $editor->inspect( $config, 'WP_DEBUG_LOG' );

		$this->assertIsArray( $definition );
		$this->assertSame( 'string', $definition['type'] );
		$this->assertSame( '/tmp/custom.log', $definition['value'] );

		$disabled = $editor->set( $config, 'WP_DEBUG_LOG', false );
		$this->assertIsString( $disabled );
		$restored = $editor->set( $disabled, 'WP_DEBUG_LOG', '/tmp/custom.log' );
		$this->assertSame( $config, $restored );
	}

	public function test_inserts_and_removes_a_missing_constant_at_the_safe_anchor(): void {
		$editor   = new DebugConfigEditor();
		$config   = "<?php\n// keep\n\$table_prefix = 'wp_';\n";
		$inserted = $editor->set( $config, 'WP_DEBUG_DISPLAY', false );

		$this->assertIsString( $inserted );
		$this->assertStringContainsString( "define( 'WP_DEBUG_DISPLAY', false );\n\$table_prefix", $inserted );
		$this->assertSame( $config, $editor->remove( $inserted, 'WP_DEBUG_DISPLAY' ) );
	}

	public function test_rejects_dynamic_and_duplicate_definitions(): void {
		$editor  = new DebugConfigEditor();
		$dynamic = $editor->set(
			"<?php\ndefine( 'WP_DEBUG', (bool) getenv('WP_DEBUG') );\n\$table_prefix = 'wp_';\n",
			'WP_DEBUG',
			true
		);

		$this->assertWPError( $dynamic );
		$this->assertSame( 'debug_constant_dynamic', $dynamic->get_error_code() );

		$duplicate = $editor->set(
			"<?php\ndefine( 'WP_DEBUG', true );\nconst WP_DEBUG = false;\n\$table_prefix = 'wp_';\n",
			'WP_DEBUG',
			false
		);

		$this->assertWPError( $duplicate );
		$this->assertSame( 'debug_constant_duplicate', $duplicate->get_error_code() );
	}

	public function test_ignores_comment_tokens_when_finding_constants_and_anchor(): void {
		$editor          = new DebugConfigEditor();
		$commented       = "<?php\n// define( 'WP_DEBUG', true );\n\$table_prefix = 'wp_';\n";
		$comment_updated = $editor->set( $commented, 'WP_DEBUG', false );

		$this->assertIsString( $comment_updated );
		$this->assertStringContainsString( "// define( 'WP_DEBUG', true );", $comment_updated );

		$anchor_config = "<?php\n// \$table_prefix is configured below.\n\$table_prefix = 'wp_';\n";
		$anchored      = $editor->set( $anchor_config, 'WP_DEBUG', true );
		$this->assertIsString( $anchored );
		$this->assertStringContainsString(
			"// \$table_prefix is configured below.\ndefine( 'WP_DEBUG', true );\n\$table_prefix",
			$anchored
		);
	}

	public function test_preserves_string_value_matching_the_missing_state_label(): void {
		$editor     = new DebugConfigEditor();
		$config     = "<?php\ndefine( 'WP_DEBUG_LOG', 'missing' );\n\$table_prefix = 'wp_';\n";
		$definition = $editor->inspect( $config, 'WP_DEBUG_LOG' );

		$this->assertIsArray( $definition );
		$this->assertSame( 'string', $definition['type'] );
		$this->assertSame( 'missing', $definition['value'] );
	}
}

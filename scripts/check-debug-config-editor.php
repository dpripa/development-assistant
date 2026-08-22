<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

if (!function_exists('__')) {
	function __(string $message): string {
		return $message;
	}
}

if (!class_exists('WP_Error')) {
	class WP_Error {
		private string $code;
		private string $message;

		public function __construct(string $code, string $message) {
			$this->code = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require dirname(__DIR__) . '/inc/DebugConfigEditor.php';

function assertEditor(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$editor = new WPDevAssist\DebugConfigEditor();
$fixtures = array(
	"define( 'WP_DEBUG', false );" => "define( 'WP_DEBUG', true );",
	'define( "WP_DEBUG" , FALSE );' => 'define( "WP_DEBUG" , true );',
	'const WP_DEBUG=false;' => 'const WP_DEBUG=true;',
	"const   WP_DEBUG\t = FALSE ;" => "const   WP_DEBUG\t = true ;",
);

foreach ($fixtures as $declaration => $expected) {
	$config = "<?php\n// prefix\n{$declaration}\n\$table_prefix = 'wp_';\n// suffix\n";
	$updated = $editor->set($config, 'WP_DEBUG', true);
	assertEditor(is_string($updated), 'A supported boolean fixture returned an error.');
	assertEditor(false !== strpos($updated, $expected), "Fixture was not updated: {$declaration}");
	assertEditor(false !== strpos($updated, "// prefix\n") && false !== strpos($updated, "// suffix\n"), 'Unrelated configuration changed.');
}

$pathConfig = "<?php\nconst WP_DEBUG_LOG = '/tmp/custom.log';\n\$table_prefix = 'wp_';\n";
$pathDefinition = $editor->inspect($pathConfig, 'WP_DEBUG_LOG');
assertEditor(is_array($pathDefinition) && 'string' === $pathDefinition['type'] && '/tmp/custom.log' === $pathDefinition['value'], 'Custom WP_DEBUG_LOG path was not preserved.');
$disabledPath = $editor->set($pathConfig, 'WP_DEBUG_LOG', false);
$restoredPath = $editor->set($disabledPath, 'WP_DEBUG_LOG', '/tmp/custom.log');
assertEditor($pathConfig === $restoredPath, 'Custom WP_DEBUG_LOG path did not restore exactly.');

$missing = "<?php\n// keep\n\$table_prefix = 'wp_';\n";
$inserted = $editor->set($missing, 'WP_DEBUG_DISPLAY', false);
assertEditor(false !== strpos($inserted, "define( 'WP_DEBUG_DISPLAY', false );\n\$table_prefix"), 'Missing constant was not inserted at the safe anchor.');
$removed = $editor->remove($inserted, 'WP_DEBUG_DISPLAY');
assertEditor($missing === $removed, 'Inserted constant was not removed cleanly.');

$dynamic = $editor->set("<?php\ndefine( 'WP_DEBUG', (bool) getenv('WP_DEBUG') );\n\$table_prefix = 'wp_';\n", 'WP_DEBUG', true);
assertEditor($dynamic instanceof WP_Error && 'debug_constant_dynamic' === $dynamic->get_error_code(), 'Dynamic expression was not rejected.');

$duplicate = $editor->set("<?php\ndefine( 'WP_DEBUG', true );\nconst WP_DEBUG = false;\n\$table_prefix = 'wp_';\n", 'WP_DEBUG', false);
assertEditor($duplicate instanceof WP_Error && 'debug_constant_duplicate' === $duplicate->get_error_code(), 'Duplicate constant definitions were not rejected.');

$commentOnly = "<?php\n// define( 'WP_DEBUG', true );\n\$table_prefix = 'wp_';\n";
$commentUpdated = $editor->set($commentOnly, 'WP_DEBUG', false);
assertEditor(false !== strpos($commentUpdated, "// define( 'WP_DEBUG', true );"), 'Commented configuration was rewritten.');

$commentAnchor = "<?php\n// \$table_prefix is configured below.\n\$table_prefix = 'wp_';\n";
$anchored = $editor->set($commentAnchor, 'WP_DEBUG', true);
assertEditor(false !== strpos($anchored, "// \$table_prefix is configured below.\ndefine( 'WP_DEBUG', true );\n\$table_prefix"), 'A comment was mistaken for the safe insertion anchor.');

$literalMissingPath = "<?php\ndefine( 'WP_DEBUG_LOG', 'missing' );\n\$table_prefix = 'wp_';\n";
$literalMissing = $editor->inspect($literalMissingPath, 'WP_DEBUG_LOG');
assertEditor(is_array($literalMissing) && 'string' === $literalMissing['type'] && 'missing' === $literalMissing['value'], 'A string path matching the missing-state label was not preserved.');

echo "Debug configuration editor fixtures passed.\n";

<?php

declare(strict_types=1);

$fixtureRoot = sys_get_temp_dir() . '/da-external-files-' . bin2hex(random_bytes(8));
$contentRoot = $fixtureRoot . '/wp-content';
mkdir($contentRoot, 0700, true);

define('ABSPATH', $fixtureRoot . '/');
define('WP_CONTENT_DIR', $contentRoot);

$modsAllowed = true;

function __(string $message): string {
	return $message;
}

function wp_is_file_mod_allowed(string $context): bool {
	global $modsAllowed;

	return $modsAllowed;
}

function get_home_path(): string {
	return ABSPATH;
}

function wp_normalize_path(string $path): string {
	return str_replace('\\', '/', $path);
}

function wp_tempnam(string $filename) {
	return tempnam(sys_get_temp_dir(), 'da-archive-');
}

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

require dirname(__DIR__) . '/inc/ExternalFileMutationManager.php';
require dirname(__DIR__) . '/inc/Htaccess.php';

function assertMutation(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

function removeFixtureTree(string $directory): void {
	if (!is_dir($directory)) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ($items as $item) {
		if ($item->isDir() && !$item->isLink()) {
			rmdir($item->getPathname());
		} else {
			chmod($item->getPathname(), 0600);
			unlink($item->getPathname());
		}
	}

	rmdir($directory);
}

$temporaryArchive = '';

try {
	$manager = new WPDevAssist\ExternalFileMutationManager();
	$configPath = ABSPATH . 'wp-config.php';
	$config = "<?php\ndefine( 'WP_DEBUG', false );\n\$table_prefix = 'wp_';\n";
	file_put_contents($configPath, $config);
	chmod($configPath, 0640);
	$originalOwner = fileowner($configPath);
	$originalGroup = filegroup($configPath);

	$result = $manager->mutate(
		WPDevAssist\ExternalFileMutationManager::TARGET_WP_CONFIG,
		static function (string $content): string {
			return str_replace("define( 'WP_DEBUG', false );", "define( 'WP_DEBUG', true );", $content);
		},
		static function (string $content): bool {
			return false !== strpos($content, "define( 'WP_DEBUG', true );");
		}
	);
	assertMutation(is_array($result) && true === $result['changed'], 'Critical configuration transaction failed.');
	assertMutation(0640 === (fileperms($configPath) & 0777), 'Configuration permissions changed.');
	assertMutation($originalOwner === fileowner($configPath), 'Configuration owner changed.');
	assertMutation($originalGroup === filegroup($configPath), 'Configuration group changed.');
	assertMutation(false !== strpos(file_get_contents($configPath), "define( 'WP_DEBUG', true );"), 'Configuration readback failed.');

	$baselinePath = WP_CONTENT_DIR . '/.development-assistant-recovery/wp-config-baseline.php';
	assertMutation(is_file($baselinePath) && 0600 === (fileperms($baselinePath) & 0777), 'Protected baseline backup was not created.');
	$baselinePayload = file_get_contents($baselinePath);
	assertMutation(0 === strpos($baselinePayload, "<?php exit; __halt_compiler(); ?>\n"), 'Backup is not protected against direct execution.');
	assertMutation(false === strpos($baselinePayload, "DB_PASSWORD"), 'Backup payload was stored as directly readable plaintext.');

	$invalidReplacement = $manager->mutate(
		WPDevAssist\ExternalFileMutationManager::TARGET_WP_CONFIG,
		static function (string $content) {
			return false;
		},
		static function (string $content): bool {
			return true;
		}
	);
	assertMutation($invalidReplacement instanceof WP_Error && false !== strpos(file_get_contents($configPath), "define( 'WP_DEBUG', true );"), 'A non-string replacement changed the target.');

	$beforeRejected = file_get_contents($configPath);
	$rejected = $manager->mutate(
		WPDevAssist\ExternalFileMutationManager::TARGET_WP_CONFIG,
		static function (string $content): string {
			return $content . 'invalid';
		},
		static function (string $content): bool {
			return false;
		}
	);
	assertMutation($rejected instanceof WP_Error && $beforeRejected === file_get_contents($configPath), 'Rejected mutation changed the target.');

	$thrownValidation = $manager->mutate(
		WPDevAssist\ExternalFileMutationManager::TARGET_WP_CONFIG,
		static function (string $content): string {
			return $content . "\n// candidate\n";
		},
		static function (string $content): bool {
			throw new RuntimeException('fixture validator failure');
		}
	);
	assertMutation($thrownValidation instanceof WP_Error && $beforeRejected === file_get_contents($configPath), 'A validator exception changed the target.');

	file_put_contents($baselinePath, 'corrupted');
	$corruptedBaseline = $manager->mutate(
		WPDevAssist\ExternalFileMutationManager::TARGET_WP_CONFIG,
		static function (string $content): string {
			return $content . "\n// blocked by corrupted baseline\n";
		},
		static function (string $content): bool {
			return true;
		}
	);
	assertMutation($corruptedBaseline instanceof WP_Error && 'external_file_backup_invalid' === $corruptedBaseline->get_error_code(), 'A corrupted baseline did not stop configuration mutation.');
	assertMutation($beforeRejected === file_get_contents($configPath), 'A corrupted baseline allowed the target to change.');
	file_put_contents($baselinePath, $baselinePayload);

	$modsAllowed = false;
	$disallowed = $manager->mutate(
		WPDevAssist\ExternalFileMutationManager::TARGET_WP_CONFIG,
		static function (string $content): string {
			return $content . 'changed';
		},
		static function (string $content): bool {
			return true;
		}
	);
	assertMutation($disallowed instanceof WP_Error && 'external_file_modification_disallowed' === $disallowed->get_error_code(), 'DISALLOW_FILE_MODS policy was ignored.');
	$modsAllowed = true;

	$htaccessPath = ABSPATH . '.htaccess';
	$htaccessOriginal = "# BEGIN WordPress\nwordpress-rules\n# END WordPress\n";
	file_put_contents($htaccessPath, $htaccessOriginal);
	$htaccess = new WPDevAssist\Htaccess($manager);
	assertMutation(true === $htaccess->replace('wp_dev_assist_debug_log', "Require all denied"), '.htaccess marker insert failed.');
	assertMutation(false !== strpos(file_get_contents($htaccessPath), '# BEGIN WordPress'), 'Existing .htaccess rules changed.');
	assertMutation(true === $htaccess->remove('wp_dev_assist_debug_log'), '.htaccess marker removal failed.');
	assertMutation($htaccessOriginal === file_get_contents($htaccessPath), '.htaccess did not restore surrounding bytes.');

	file_put_contents($htaccessPath, "# BEGIN broken\nmissing end\n");
	$malformed = $htaccess->replace('broken', 'new');
	assertMutation($malformed instanceof WP_Error && "# BEGIN broken\nmissing end\n" === file_get_contents($htaccessPath), 'Malformed .htaccess markers were rewritten.');

	$debugLog = WP_CONTENT_DIR . '/debug.log';
	file_put_contents($debugLog, 'log');
	assertMutation(null === $manager->delete_debug_log() && !file_exists($debugLog), 'Registered debug log deletion failed.');

	$temporaryArchive = $manager->create_temporary_archive('plugin.zip');
	assertMutation(is_string($temporaryArchive) && is_file($temporaryArchive), 'Registered temporary archive creation failed.');
	assertMutation(false === $manager->delete_temporary_archive($temporaryArchive . '.unregistered'), 'Unregistered temporary archive deletion was allowed.');
	assertMutation(true === $manager->delete_temporary_archive($temporaryArchive) && !file_exists($temporaryArchive), 'Registered temporary archive cleanup failed.');
	$temporaryArchive = '';

	echo "External filesystem mutation fixtures passed.\n";
} finally {
	if ('' !== $temporaryArchive && file_exists($temporaryArchive)) {
		unlink($temporaryArchive);
	}

	removeFixtureTree($fixtureRoot);
}

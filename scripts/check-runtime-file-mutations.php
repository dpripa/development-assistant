<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$allowedMutationFile = realpath($root . '/inc/ExternalFileMutationManager.php');
$allowedArchiveFile = realpath($root . '/inc/PluginsScreen/Downloader.php');
$mutatingFunctions = array(
	'chgrp',
	'chmod',
	'chown',
	'copy',
	'file_put_contents',
	'ftruncate',
	'fwrite',
	'mkdir',
	'move_uploaded_file',
	'rename',
	'rmdir',
	'touch',
	'unlink',
	'wp_filesystem',
);
$violations = array();
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator($root . '/inc', RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
	if (!$file->isFile() || 'php' !== strtolower($file->getExtension())) {
		continue;
	}

	$path = $file->getRealPath();
	$content = file_get_contents($path);
	$tokens = token_get_all($content);
	$previousSignificant = null;

	foreach ($tokens as $index => $token) {
		if (is_array($token) && in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
			continue;
		}

		if (is_array($token) && T_STRING === $token[0]) {
			$name = strtolower($token[1]);

			if (
				in_array($name, $mutatingFunctions, true) &&
				$path !== $allowedMutationFile &&
				(!is_array($previousSignificant) || !in_array($previousSignificant[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON), true))
			) {
				$violations[] = sprintf('%s:%d direct %s() call', substr($path, strlen($root) + 1), $token[2], $token[1]);
			}

			if (
				'ziparchive' === $name &&
				is_array($previousSignificant) &&
				T_NEW === $previousSignificant[0] &&
				$path !== $allowedArchiveFile
			) {
				$violations[] = sprintf('%s:%d ZipArchive creation outside the registered temporary-archive policy', substr($path, strlen($root) + 1), $token[2]);
			}
		}

		$previousSignificant = $token;
	}
}

if ($violations) {
	fwrite(STDERR, "Runtime filesystem mutations must use ExternalFileMutationManager:\n");
	fwrite(STDERR, implode("\n", $violations) . "\n");
	exit(1);
}

echo "Runtime filesystem mutation boundary verified.\n";

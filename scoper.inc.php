<?php
use Isolated\Symfony\Component\Finder\Finder;

$libs = array(
	'vendor/omgpress/omgcore',
);

$exclude_classes = array_merge(
	require_once __DIR__ . '/vendor/omgpress/omgcore/scoper.exclude-classes.php',
);

$exclude_functions = array_merge();

$exclude_files = array(
	'LICENSE',
	'.*\\.md',
	'.*\\.dist',
	'Makefile',
	'composer.json',
	'composer.lock',
	'scoper.exclude-classes.php',
	'scoper.exclude-functions.php',
);

$exclude_dirs = array(
	'test',
);

$config = array(
	'prefix'            => str_replace(
		'\\',
		'',
		array_keys(
			json_decode(
				file_get_contents( __DIR__ . '/autoload.json' ), // phpcs:ignore
				true
			)['autoload']['psr-4']
		)[0]
	),
	'finders'           => array(),
	'exclude-classes'   => $exclude_classes,
	'exclude-functions' => $exclude_functions,
);

foreach ( $libs as $lib ) {
	$config['finders'][] = Finder::create()
		->files()
		->ignoreVCS( true )
		->name( '*.php' )
		->notName( '/' . implode( '|', $exclude_files ) . '/' )
		->exclude( $exclude_dirs )
		->in( $lib )
		->append( array( "$lib/composer.json" ) );
}

$config['finders'][] = Finder::create()
	->append( array( 'composer.json' ) );

return $config;

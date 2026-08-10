<?php
function load_plugin() {
	require dirname( __DIR__ ) . '/development-assistant.php';
}

$tests_dir = dirname( __DIR__ ) . '/tmp/wordpress-tests-lib';

if ( ! file_exists( "$tests_dir/includes/functions.php" ) ) {
	echo "Could not find $tests_dir/includes/functions.php, have you run .script/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once "$tests_dir/includes/functions.php";

tests_add_filter( 'muplugins_loaded', 'load_plugin' );

require "$tests_dir/includes/bootstrap.php";

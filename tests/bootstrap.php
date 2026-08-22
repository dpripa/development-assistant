<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$tests_directory = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( $tests_directory . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress PHPUnit library is missing. Run Composer install first.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $tests_directory . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/development-assistant.php';
	}
);

require $tests_directory . '/includes/bootstrap.php';

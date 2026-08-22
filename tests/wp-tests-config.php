<?php

$db_name     = getenv( 'WP_TESTS_DB_NAME' );
$db_user     = getenv( 'WP_TESTS_DB_USER' );
$db_password = getenv( 'WP_TESTS_DB_PASSWORD' );
$db_host     = getenv( 'WP_TESTS_DB_HOST' );
$core_dir    = getenv( 'WP_TESTS_CORE_DIR' );

define( 'DB_NAME', false !== $db_name ? $db_name : 'wordpress_tests' );
define( 'DB_USER', false !== $db_user ? $db_user : 'wordpress_tests' );
define( 'DB_PASSWORD', false !== $db_password ? $db_password : 'wordpress_tests' );
define( 'DB_HOST', false !== $db_host ? $db_host : 'test-db' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = false !== getenv( 'WP_PHPUNIT__TABLE_PREFIX' ) ? getenv( 'WP_PHPUNIT__TABLE_PREFIX' ) : 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'ABSPATH', rtrim( false !== $core_dir ? $core_dir : dirname( __DIR__ ) . '/wp', '/\\' ) . '/' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Development Assistant Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

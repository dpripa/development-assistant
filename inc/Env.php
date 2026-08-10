<?php
namespace WPDevAssist;

defined( 'ABSPATH' ) || exit;

class Env {
	protected array $dev_hosts = array(
		'localhost',
		'local',
		'loc',
		'development',
		'dev',
		'mamp',
	);

	protected array $dev_envs = array(
		'development',
		'local',
	);

	protected bool $is_dev;

	public function __construct() {
		$host         = explode( '.', wp_parse_url( home_url(), PHP_URL_HOST ) );
		$root_host    = end( $host );
		$this->is_dev = in_array( $root_host, $this->dev_hosts, true ) ||
			in_array( wp_get_environment_type(), $this->dev_envs, true ) ||
			(
				defined( 'WP_ENVIRONMENT' ) &&
				in_array( WP_ENVIRONMENT, $this->dev_envs, true )
			);
	}

	public function is_dev(): bool {
		return $this->is_dev;
	}
}

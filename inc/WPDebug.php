<?php
namespace WPDevAssist;

use Exception;
use WPDevAssist\AdminNotice;
use WPDevAssist\Fs;

defined( 'ABSPATH' ) || exit;

class WPDebug {
	protected const CONFIG_FILE_PATH               = ABSPATH . 'wp-config.php';
	protected const ORIGINAL_DEBUG_VALUE_KEY       = KEY . '_original_wp_debug_value';
	protected const ORIGINAL_DEBUG_VALUE_DEFAULT   = 'disabled';
	protected const ORIGINAL_LOG_VALUE_KEY         = KEY . '_original_wp_debug_log_value';
	protected const ORIGINAL_LOG_VALUE_DEFAULT     = 'disabled';
	protected const ORIGINAL_DISPLAY_VALUE_KEY     = KEY . '_original_wp_debug_display_value';
	protected const ORIGINAL_DISPLAY_VALUE_DEFAULT = 'disabled';
	protected const HTACCESS_MARKER                = KEY . '_debug_log';

	protected AdminNotice $admin_notice;
	protected Fs $fs;
	protected Htaccess $htaccess;

	/**
	 * @throws Exception
	 */
	public function __construct( AdminNotice $admin_notice, Fs $fs, Htaccess $htaccess ) {
		$this->admin_notice = $admin_notice;
		$this->fs           = $fs;
		$this->htaccess     = $htaccess;

		if ( ! is_admin() ) {
			return;
		}

		$this->toggle_debug_mode();

		add_action( 'update_option_' . Setting::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, $this->replace_htaccess_directives(), 10, 2 );
	}

	/**
	 * @throws Exception
	 */
	protected function toggle_debug_mode(): void {
		$is_debug_setting_enabled   = 'yes' === get_option( Setting::ENABLE_WP_DEBUG_KEY, Setting::ENABLE_WP_DEBUG_DEFAULT );
		$is_log_setting_enabled     = 'yes' === get_option( Setting::ENABLE_WP_DEBUG_LOG_KEY, Setting::ENABLE_WP_DEBUG_DISPLAY_KEY );
		$is_display_setting_enabled = 'yes' === get_option( Setting::ENABLE_WP_DEBUG_DISPLAY_KEY, Setting::ENABLE_WP_DEBUG_DISPLAY_DEFAULT );

		if (
			$this->is_debug_enabled() === $is_debug_setting_enabled &&
			$this->is_debug_log_enabled() === $is_log_setting_enabled &&
			$this->is_debug_display_enabled() === $is_display_setting_enabled
		) {
			return;
		}

		$config_content = $this->read_config_content();

		if ( empty( $config_content ) ) {
			$this->admin_notice->add_transient(
				sprintf(
					__( 'Can\'t read the %s.', 'development-assistant' ),
					static::CONFIG_FILE_PATH
				),
				'error'
			);

			return;
		}

		if ( $this->is_debug_enabled() !== $is_debug_setting_enabled ) {
			$config_content = $this->update_config_const(
				'WP_DEBUG',
				$is_debug_setting_enabled ? 'enabled' : 'disabled',
				$config_content
			);
		}

		if ( $this->is_debug_log_enabled() !== $is_log_setting_enabled ) {
			$config_content = $this->update_config_const(
				'WP_DEBUG_LOG',
				$is_log_setting_enabled ? 'enabled' : 'disabled',
				$config_content
			);
		}

		if ( $this->is_debug_display_enabled() !== $is_display_setting_enabled ) {
			$config_content = $this->update_config_const(
				'WP_DEBUG_DISPLAY',
				$is_display_setting_enabled ? 'enabled' : 'disabled',
				$config_content
			);
		}

		$this->write_config_content( $config_content );
	}

	/**
	 * @throws Exception
	 */
	protected function update_config_const( string $name, string $value, string $config_content ): string {
		$search = array(
			"define( '" . $name . "', true );",
			"define( '" . $name . "', false );",
			"define('" . $name . "', true);",
			"define('" . $name . "', false);",
			'const ' . $name . ' = true;',
			'const ' . $name . ' = false;',
			'const ' . $name . '=true;',
			'const ' . $name . '=false;',
		);

		switch ( $value ) {
			case 'disabled':
				return str_replace( $search, "define( '" . $name . "', false );", $config_content );

			case 'enabled':
				$config_content = str_replace(
					$search,
					"define( '" . $name . "', true );",
					$config_content,
					$count
				);

				if ( $count ) {
					return $config_content;
				}

				return str_replace(
					'$table_prefix',
					"define( '" . $name . "', true );" . "\r\n" . '$table_prefix', // phpcs:ignore
					$config_content
				);

			case 'missing':
				return str_replace(
					$search,
					'',
					$config_content
				);

			default:
				throw new Exception( esc_html( "\"$value\" is not an allowed value" ) );
		}
	}

	protected function read_config_content(): string {
		return $this->fs->read_text_file( static::CONFIG_FILE_PATH );
	}

	protected function write_config_content( string $content ): bool {
		return $this->fs->write_text_file( static::CONFIG_FILE_PATH, $content );
	}

	public function is_debug_enabled(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}

	public function is_debug_log_enabled(): bool {
		return defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	public function is_debug_display_enabled(): bool {
		return defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
	}

	public function store_original_config_const(): void {
		if ( ! in_array( get_option( static::ORIGINAL_DEBUG_VALUE_KEY ), array( 'enabled', 'disabled', 'missing' ), true ) ) {
			update_option(
				static::ORIGINAL_DEBUG_VALUE_KEY,
				defined( 'WP_DEBUG' ) ? ( WP_DEBUG ? 'enabled' : 'disabled' ) : 'missing'
			);
		}

		if ( ! in_array( get_option( static::ORIGINAL_LOG_VALUE_KEY ), array( 'enabled', 'disabled', 'missing' ), true ) ) {
			update_option(
				static::ORIGINAL_LOG_VALUE_KEY,
				defined( 'WP_DEBUG_LOG' ) ? ( WP_DEBUG_LOG ? 'enabled' : 'disabled' ) : 'missing'
			);
		}

		if ( ! in_array( get_option( static::ORIGINAL_DISPLAY_VALUE_KEY ), array( 'enabled', 'disabled', 'missing' ), true ) ) {
			update_option(
				static::ORIGINAL_DISPLAY_VALUE_KEY,
				defined( 'WP_DEBUG_DISPLAY' ) ? ( WP_DEBUG_DISPLAY ? 'enabled' : 'disabled' ) : 'missing'
			);
		}
	}

	/**
	 * @throws Exception
	 */
	public function reset_config_const(): void {
		$config_content = $this->read_config_content();

		if ( empty( $config_content ) ) {
			$this->admin_notice->add_transient(
				sprintf(
					__( 'Can\'t read the %s.', 'development-assistant' ),
					static::CONFIG_FILE_PATH
				),
				'error'
			);

			return;
		}

		$config_content = $this->update_config_const(
			'WP_DEBUG',
			get_option( static::ORIGINAL_DEBUG_VALUE_KEY, static::ORIGINAL_DEBUG_VALUE_DEFAULT ),
			$config_content
		);
		$config_content = $this->update_config_const(
			'WP_DEBUG_LOG',
			get_option( static::ORIGINAL_LOG_VALUE_KEY, static::ORIGINAL_LOG_VALUE_DEFAULT ),
			$config_content
		);
		$config_content = $this->update_config_const(
			'WP_DEBUG_DISPLAY',
			get_option( static::ORIGINAL_DISPLAY_VALUE_KEY, static::ORIGINAL_DISPLAY_VALUE_DEFAULT ),
			$config_content
		);

		if ( ! $this->write_config_content( $config_content ) ) {
			return;
		}

		delete_option( static::ORIGINAL_DEBUG_VALUE_KEY );
		delete_option( static::ORIGINAL_LOG_VALUE_KEY );
		delete_option( static::ORIGINAL_DISPLAY_VALUE_KEY );
	}

	protected function replace_htaccess_directives(): callable {
		return function ( string $old_value, string $value ): void {
			if ( 'yes' === $value ) {
				if ( ! $this->add_htaccess_directives() ) {
					$this->admin_notice->add_transient(
						__( 'Can\'t add the directives to the .htaccess file', 'development-assistant' ),
						'error'
					);
				}

				return;
			}

			if ( ! $this->remove_htaccess_directives() ) {
				$this->admin_notice->add_transient(
					__( 'Can\'t remove the directives from the .htaccess file', 'development-assistant' ),
					'error'
				);
			}
		};
	}

	public function add_htaccess_directives(): bool {
		return $this->htaccess->replace(
			static::HTACCESS_MARKER,
			'<If "%{REQUEST_URI} =~ m#^/wp-content/debug.log#">
			    <IfModule mod_authz_core.c>
					Require all denied
				</IfModule>
				<IfModule !mod_authz_core.c>
					Order deny,allow
					Deny from all
				</IfModule>
			</If>'
		);
	}

	public function remove_htaccess_directives(): bool {
		return $this->htaccess->remove( static::HTACCESS_MARKER );
	}
}

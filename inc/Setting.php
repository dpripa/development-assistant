<?php
namespace WPDevAssist;

use WPDevAssist\ActionQuery;
use WPDevAssist\AdminNotice;
use WPDevAssist\Asset;
use WPDevAssist\Env;
use WPDevAssist\Fs;
use WPDevAssist\Setting\Page;
use WPDevAssist\Setting\Control;
use WPDevAssist\Setting\DebugLog;
use WPDevAssist\Setting\DevEnv;
use WPDevAssist\Setting\SupportUser;

defined( 'ABSPATH' ) || exit;

class Setting extends Page {
	public const KEY                                      = KEY;
	public const ENABLE_WP_DEBUG_KEY                      = KEY . '_enable_wp_debug';
	public const ENABLE_WP_DEBUG_DEFAULT                  = 'no';
	public const ENABLE_WP_DEBUG_LOG_KEY                  = KEY . '_enable_wp_debug_log';
	public const ENABLE_WP_DEBUG_LOG_DEFAULT              = 'no';
	public const ENABLE_WP_DEBUG_DISPLAY_KEY              = KEY . '_enable_wp_debug_display';
	public const ENABLE_WP_DEBUG_DISPLAY_DEFAULT          = 'no';
	public const DISABLE_DIRECT_ACCESS_TO_LOG_KEY         = KEY . '_disable_direct_access_to_log';
	public const DISABLE_DIRECT_ACCESS_TO_LOG_DEFAULT     = 'no';
	public const ENABLE_ASSISTANT_KEY                     = KEY . '_enable_assistant';
	public const ENABLE_ASSISTANT_DEFAULT                 = 'yes';
	public const ASSISTANT_OPENED_ON_WP_DASHBOARD_KEY     = KEY . '_expanded_on_wp_dashboard';
	public const ASSISTANT_OPENED_ON_WP_DASHBOARD_DEFAULT = 'yes';
	public const ACTIVE_PLUGINS_FIRST_KEY                 = KEY . '_active_plugins_first';
	public const ACTIVE_PLUGINS_FIRST_DEFAULT             = 'yes';
	public const RESET_KEY                                = KEY . '_reset';
	public const RESET_DEFAULT                            = 'yes';
	public const TOGGLE_DEBUG_MODE_QUERY_KEY              = KEY . '_toggle_debug_mode';
	public const DISABLE_DIRECT_ACCESS_TO_LOG_QUERY_KEY   = KEY . '_disable_direct_access_to_log';
	public const ENABLE_DEBUG_LOG_QUERY_KEY               = KEY . '_enable_log';
	public const DISABLE_DEBUG_DISPLAY_QUERY_KEY          = KEY . '_disable_debug_display';
	public const PAGE_TITLE_HOOK                          = KEY . '_settings_page_title';

	protected const SETTING_KEYS = array(
		self::ENABLE_WP_DEBUG_KEY,
		self::ENABLE_WP_DEBUG_LOG_KEY,
		self::ENABLE_WP_DEBUG_DISPLAY_KEY,
		self::DISABLE_DIRECT_ACCESS_TO_LOG_KEY,
		self::ENABLE_ASSISTANT_KEY,
		self::ASSISTANT_OPENED_ON_WP_DASHBOARD_KEY,
		self::ACTIVE_PLUGINS_FIRST_KEY,
		self::RESET_KEY,
	);

	protected Control $control;
	protected Htaccess $htaccess;
	protected WPDebug $wp_debug;
	protected DebugLog $debug_log;
	protected DevEnv $dev_env;
	protected SupportUser $support_user;

	public function __construct(
		ActionQuery $action_query,
		Asset $asset,
		Fs $fs,
		AdminNotice $admin_notice,
		Htaccess $htaccess,
		MailHog $mail_hog,
		WPDebug $wp_debug,
		Env $env
	) {
		$this->control  = new Control();
		$this->htaccess = $htaccess;
		$this->wp_debug = $wp_debug;

		parent::__construct( $asset, $admin_notice );
		$action_query->add( static::TOGGLE_DEBUG_MODE_QUERY_KEY, $this->handle_toggle_debug_mode() );
		$action_query->add( static::DISABLE_DIRECT_ACCESS_TO_LOG_QUERY_KEY, $this->handle_disable_direct_access_to_log() );
		$action_query->add( static::DISABLE_DEBUG_DISPLAY_QUERY_KEY, $this->handle_disable_debug_display() );
		$action_query->add( static::ENABLE_DEBUG_LOG_QUERY_KEY, $this->handle_enable_debug_log() );

		$this->debug_log    = new DebugLog( $action_query, $asset, $admin_notice, $fs );
		$this->dev_env      = new DevEnv( $action_query, $admin_notice, $this->control, $mail_hog, $env );
		$this->support_user = new SupportUser( $action_query, $asset, $admin_notice, $this->control, $env );
	}

	public function debug_log(): DebugLog {
		return $this->debug_log;
	}

	public function dev_env(): DevEnv {
		return $this->dev_env;
	}

	public function support_user(): SupportUser {
		return $this->support_user;
	}

	protected function add_page(): callable {
		return function (): void {
			$page_title = apply_filters(
				static::PAGE_TITLE_HOOK,
				__( 'Development Assistant', 'development-assistant' )
			);

			add_menu_page(
				$page_title,
				$this->get_toplevel_title(),
				'administrator', // phpcs:ignore
				KEY,
				$this->render_page(),
				'dashicons-pets',
				999
			);
			add_submenu_page(
				KEY,
				$page_title,
				__( 'Settings', 'development-assistant' ),
				'administrator', // phpcs:ignore
				KEY,
			);
		};
	}

	protected function get_tabs(): array {
		return array(
			$this->dev_env,
		);
	}

	protected function add_sections(): callable {
		return function (): void {
			$this->add_wp_debug_section( KEY . '_debug' );
			$this->add_assistant_section( KEY . '_assistant' );
			$this->add_plugin_screen_section( KEY . '_plugins_screen' );
			$this->add_reset_section( KEY . '_reset' );
		};
	}

	protected function add_wp_debug_section( string $section_key ): void {
		$this->add_section(
			$section_key,
			esc_html__( 'WP Debug', 'development-assistant' ),
			$this->render_wp_debug_description()
		);
		$this->add_setting(
			$section_key,
			static::ENABLE_WP_DEBUG_KEY,
			wp_kses( __( 'Enable <code>WP_DEBUG</code>', 'development-assistant' ), array( 'code' => array() ) ),
			array( $this->control, 'render_checkbox' ),
			static::ENABLE_WP_DEBUG_DEFAULT
		);
		$this->add_setting(
			$section_key,
			static::ENABLE_WP_DEBUG_LOG_KEY,
			wp_kses( __( 'Enable <code>WP_DEBUG_LOG</code>', 'development-assistant' ), array( 'code' => array() ) ),
			array( $this->control, 'render_checkbox' ),
			static::ENABLE_WP_DEBUG_LOG_DEFAULT
		);

		$args = array();

		if ( 'yes' !== get_option( Setting\DevEnv::ENABLE_KEY, Setting\DevEnv::ENABLE_DEFAULT ) ) {
			$args['description'] = '<b class="da-setting__error-text">' . esc_html__( 'Warning!', 'development-assistant' ) . '</b> ' . wp_kses( __( 'Enabling error display may cause the entire interface blocking due to the display of these error messages, as well as a critical security issues. <b>Highly recommended to keep it disabled in production environment.</b>', 'development-assistant' ), array( 'b' => array() ) );
		}

		$this->add_setting(
			$section_key,
			static::ENABLE_WP_DEBUG_DISPLAY_KEY,
			wp_kses( __( 'Enable <code>WP_DEBUG_DISPLAY</code>', 'development-assistant' ), array( 'code' => array() ) ),
			array( $this->control, 'render_checkbox' ),
			static::ENABLE_WP_DEBUG_DISPLAY_DEFAULT,
			$args
		);

		$args = array(
			'description' => sprintf(
				wp_kses( __( 'Public access via %s to the <code>debug.log</code> file will be disabled.', 'development-assistant' ), array( 'code' => array() ) ),
				'<a href="' . esc_url( $this->debug_log->get_public_url() ) . '" target="_blank">' . esc_html__( 'the link', 'development-assistant' ) . '</a>'
			),
		);

		if ( ! $this->htaccess->exists() ) {
			$args['disabled']    = true;
			$args['description'] = wp_kses( __( '<code>.htaccess</code> file is required (only supported on Apache HTTP Server).', 'development-assistant' ), array( 'code' => array() ) );
		}

		$this->add_setting(
			$section_key,
			static::DISABLE_DIRECT_ACCESS_TO_LOG_KEY,
			wp_kses( __( 'Disable direct access', 'development-assistant' ), array( 'code' => array() ) ),
			array( $this->control, 'render_checkbox' ),
			static::DISABLE_DIRECT_ACCESS_TO_LOG_DEFAULT,
			$args
		);
	}

	protected function render_wp_debug_description(): callable {
		return function (): void {
			echo wp_kses( __( 'These options allow you to safely control the debug constants without the need to manually edit the <code>wp-config.php</code>.', 'development-assistant' ), array( 'code' => array() ) );
			?>
			<div style="margin-top: 5px;">
				<a href="<?php echo esc_url( $this->debug_log->get_page_url() ); ?>">
					<?php
					echo wp_kses( __( 'Go to <code>debug.log</code>', 'development-assistant' ), array( 'code' => array() ) );
					?>
				</a>
			</div>
			<?php
		};
	}

	protected function add_assistant_section( string $section_key ): void {
		$this->add_section(
			$section_key,
			esc_html__( 'Assistant Panel', 'development-assistant' )
		);
		$this->add_setting(
			$section_key,
			static::ENABLE_ASSISTANT_KEY,
			esc_html__( 'Enable Assistant Panel', 'development-assistant' ),
			array( $this->control, 'render_checkbox' ),
			static::ENABLE_ASSISTANT_DEFAULT
		);
		$this->add_setting(
			$section_key,
			static::ASSISTANT_OPENED_ON_WP_DASHBOARD_KEY,
			esc_html__( 'Opened by default on the WordPress Dashboard', 'development-assistant' ),
			array( $this->control, 'render_checkbox' ),
			static::ASSISTANT_OPENED_ON_WP_DASHBOARD_DEFAULT
		);
	}

	protected function add_plugin_screen_section( string $section_key ): void {
		$this->add_section(
			$section_key,
			esc_html__( 'Plugins Screen', 'development-assistant' )
		);
		$this->add_setting(
			$section_key,
			static::ACTIVE_PLUGINS_FIRST_KEY,
			esc_html__( 'Show active plugins first', 'development-assistant' ),
			array( $this->control, 'render_checkbox' ),
			static::ACTIVE_PLUGINS_FIRST_DEFAULT
		);
	}

	protected function add_reset_section( string $key ): void {
		$this->add_section(
			$key,
			esc_html__( 'Reset', 'development-assistant' )
		);
		$this->add_setting(
			$key,
			static::RESET_KEY,
			esc_html__( 'Reset plugin data when deactivated', 'development-assistant' ),
			array( $this->control, 'render_checkbox' ),
			static::RESET_DEFAULT,
			array(
				'description' => sprintf(
					esc_html__( 'It\'ll make look like the plugin was never installed and will undo any possible changes that may have been made using it %s.', 'development-assistant' ),
					'<i>' . esc_html__( '(the only exception is deleted data or files, it cannot be recovered)', 'development-assistant' ) . '</i>'
				),
			),
		);
	}

	public function add_default_options(): void {
		parent::add_default_options();

		if ( ! in_array( get_option( static::ENABLE_WP_DEBUG_KEY ), array( 'yes', 'no' ), true ) ) {
			update_option(
				static::ENABLE_WP_DEBUG_KEY,
				$this->wp_debug->is_debug_enabled() ? 'yes' : 'no'
			);
		}

		if ( ! in_array( get_option( static::ENABLE_WP_DEBUG_LOG_KEY ), array( 'yes', 'no' ), true ) ) {
			update_option(
				static::ENABLE_WP_DEBUG_LOG_KEY,
				$this->wp_debug->is_debug_log_enabled() ? 'yes' : 'no'
			);
		}

		if ( ! in_array( get_option( static::ENABLE_WP_DEBUG_DISPLAY_KEY ), array( 'yes', 'no' ), true ) ) {
			update_option(
				static::ENABLE_WP_DEBUG_DISPLAY_KEY,
				$this->wp_debug->is_debug_display_enabled() ? 'yes' : 'no'
			);
		}
	}

	protected function handle_toggle_debug_mode(): callable {
		return function ( array $data ): void {
			$value      = sanitize_text_field( wp_unslash( $data[ static::TOGGLE_DEBUG_MODE_QUERY_KEY ] ) );
			$is_dev_env = 'yes' === get_option( Setting\DevEnv::ENABLE_KEY, Setting\DevEnv::ENABLE_DEFAULT );

			if ( 'yes' !== $value && 'no' !== $value ) {
				return;
			}

			update_option( static::ENABLE_WP_DEBUG_KEY, $value );
			update_option( static::ENABLE_WP_DEBUG_LOG_KEY, $value );

			if ( $is_dev_env || 'yes' !== $value ) {
				update_option( static::ENABLE_WP_DEBUG_DISPLAY_KEY, $value );
			}

			if ( ! $is_dev_env && $this->htaccess->exists() && 'yes' === $value ) {
				update_option( static::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, 'yes' );
			}

			if ( 'yes' === $value ) {
				$message = __( 'Debug mode enabled.', 'development-assistant' );
			} else {
				$message = __( 'Debug mode disabled.', 'development-assistant' );
			}

			$this->admin_notice->add_transient( $message, 'success' );
		};
	}

	protected function handle_disable_direct_access_to_log(): callable {
		return function (): void {
			if ( ! $this->htaccess->exists() ) {
				return;
			}

			update_option( static::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, 'yes' );
			$this->admin_notice->add_transient( __( 'Direct access to the <code>debug.log</code> file disabled.', 'development-assistant' ), 'success' );
		};
	}

	protected function handle_disable_debug_display(): callable {
		return function (): void {
			update_option( static::ENABLE_WP_DEBUG_DISPLAY_KEY, 'no' );
			$this->admin_notice->add_transient( __( '<code>WP_DEBUG_DISPLAY</code> disabled.', 'development-assistant' ), 'success' );
		};
	}

	protected function handle_enable_debug_log(): callable {
		return function (): void {
			update_option( static::ENABLE_WP_DEBUG_KEY, 'yes' );
			update_option( static::ENABLE_WP_DEBUG_LOG_KEY, 'yes' );
			$this->admin_notice->add_transient( __( '<code>WP_DEBUG_LOG</code> enabled.', 'development-assistant' ), 'success' );
		};
	}
}

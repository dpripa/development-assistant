<?php
namespace WPDevAssist;

use WP_Error;
use WPDevAssist\ActionQuery;
use WPDevAssist\AdminNotice;
use WPDevAssist\Asset;
use WPDevAssist\Fs;
use WPDevAssist\Setting\Page;
use WPDevAssist\Setting\Control;
use WPDevAssist\Setting\DebugLog;
use WPDevAssist\Setting\SupportUser;

defined( 'ABSPATH' ) || exit;

class Setting extends Page {
	public const KEY                                    = KEY;
	public const ENABLE_WP_DEBUG_KEY                    = KEY . '_enable_wp_debug';
	public const ENABLE_WP_DEBUG_DEFAULT                = 'no';
	public const ENABLE_WP_DEBUG_LOG_KEY                = KEY . '_enable_wp_debug_log';
	public const ENABLE_WP_DEBUG_LOG_DEFAULT            = 'no';
	public const ENABLE_WP_DEBUG_DISPLAY_KEY            = KEY . '_enable_wp_debug_display';
	public const ENABLE_WP_DEBUG_DISPLAY_DEFAULT        = 'no';
	public const DISABLE_DIRECT_ACCESS_TO_LOG_KEY       = KEY . '_disable_direct_access_to_log';
	public const DISABLE_DIRECT_ACCESS_TO_LOG_DEFAULT   = 'no';
	public const ENABLE_ASSISTANT_KEY                   = KEY . '_enable_assistant';
	public const ENABLE_ASSISTANT_DEFAULT               = 'yes';
	public const ACTIVE_PLUGINS_FIRST_KEY               = KEY . '_active_plugins_first';
	public const ACTIVE_PLUGINS_FIRST_DEFAULT           = 'yes';
	public const RESET_KEY                              = KEY . '_reset';
	public const RESET_DEFAULT                          = 'yes';
	public const TOGGLE_DEBUG_MODE_QUERY_KEY            = KEY . '_toggle_debug_mode';
	public const DISABLE_DIRECT_ACCESS_TO_LOG_QUERY_KEY = KEY . '_disable_direct_access_to_log';
	public const ENABLE_DEBUG_LOG_QUERY_KEY             = KEY . '_enable_log';
	public const DISABLE_DEBUG_DISPLAY_QUERY_KEY        = KEY . '_disable_debug_display';
	public const PAGE_TITLE_HOOK                        = KEY . '_settings_page_title';

	protected const SETTING_KEYS = array(
		self::ENABLE_WP_DEBUG_KEY,
		self::ENABLE_WP_DEBUG_LOG_KEY,
		self::ENABLE_WP_DEBUG_DISPLAY_KEY,
		self::DISABLE_DIRECT_ACCESS_TO_LOG_KEY,
		self::ENABLE_ASSISTANT_KEY,
		// Legacy Assistant Panel option, kept only so reset removes it.
		KEY . '_expanded_on_wp_dashboard',
		self::ACTIVE_PLUGINS_FIRST_KEY,
		self::RESET_KEY,
		// Legacy development-environment and MailHog options, kept only so reset removes them.
		KEY . '_force_dev_env',
		KEY . '_redirect_to_mail_hog',
		KEY . '_mail_hog_http_address',
		KEY . '_mail_hog_smtp_address',
	);

	protected Control $control;
	protected Htaccess $htaccess;
	protected WPDebug $wp_debug;
	protected DebugLog $debug_log;
	protected SupportUser $support_user;

	public function __construct(
		ActionQuery $action_query,
		Asset $asset,
		Fs $fs,
		ExternalFileMutationManager $file_mutations,
		AdminNotice $admin_notice,
		Htaccess $htaccess,
		WPDebug $wp_debug
	) {
		$this->control  = new Control();
		$this->htaccess = $htaccess;
		$this->wp_debug = $wp_debug;

		parent::__construct( $asset, $admin_notice );
		$action_query->add( static::TOGGLE_DEBUG_MODE_QUERY_KEY, $this->handle_toggle_debug_mode() );
		$action_query->add( static::DISABLE_DIRECT_ACCESS_TO_LOG_QUERY_KEY, $this->handle_disable_direct_access_to_log() );
		$action_query->add( static::DISABLE_DEBUG_DISPLAY_QUERY_KEY, $this->handle_disable_debug_display() );
		$action_query->add( static::ENABLE_DEBUG_LOG_QUERY_KEY, $this->handle_enable_debug_log() );

		$this->debug_log    = new DebugLog( $action_query, $asset, $admin_notice, $fs, $file_mutations );
		$this->support_user = new SupportUser( $action_query, $asset, $admin_notice, $this->control );
	}

	public function debug_log(): DebugLog {
		return $this->debug_log;
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
			static::ENABLE_WP_DEBUG_DEFAULT,
			array(),
			$this->sanitize_debug_option( 'WP_DEBUG', static::ENABLE_WP_DEBUG_KEY )
		);
		$this->add_setting(
			$section_key,
			static::ENABLE_WP_DEBUG_LOG_KEY,
			wp_kses( __( 'Enable <code>WP_DEBUG_LOG</code>', 'development-assistant' ), array( 'code' => array() ) ),
			array( $this->control, 'render_checkbox' ),
			static::ENABLE_WP_DEBUG_LOG_DEFAULT,
			array(),
			$this->sanitize_debug_option( 'WP_DEBUG_LOG', static::ENABLE_WP_DEBUG_LOG_KEY )
		);

		$args = array(
			'description' => '<b class="da-setting__error-text">' . esc_html__( 'Warning!', 'development-assistant' ) . '</b> ' . esc_html__( 'Enabling error display may cause the entire interface blocking due to the display of these error messages, as well as a critical security issues.', 'development-assistant' ),
		);

		$this->add_setting(
			$section_key,
			static::ENABLE_WP_DEBUG_DISPLAY_KEY,
			wp_kses( __( 'Enable <code>WP_DEBUG_DISPLAY</code>', 'development-assistant' ), array( 'code' => array() ) ),
			array( $this->control, 'render_checkbox' ),
			static::ENABLE_WP_DEBUG_DISPLAY_DEFAULT,
			$args,
			$this->sanitize_debug_option( 'WP_DEBUG_DISPLAY', static::ENABLE_WP_DEBUG_DISPLAY_KEY )
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
			$args,
			$this->sanitize_htaccess_option()
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
			esc_html__( 'Assistant Menu', 'development-assistant' )
		);
		$this->add_setting(
			$section_key,
			static::ENABLE_ASSISTANT_KEY,
			esc_html__( 'Show Development Assistant in the admin bar', 'development-assistant' ),
			array( $this->control, 'render_checkbox' ),
			static::ENABLE_ASSISTANT_DEFAULT
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
					esc_html__( 'It\'ll undo any possible changes that may have been made using Development Assistant %s.', 'development-assistant' ),
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
			$value = sanitize_text_field( wp_unslash( $data[ static::TOGGLE_DEBUG_MODE_QUERY_KEY ] ) );

			if ( 'yes' !== $value && 'no' !== $value ) {
				return;
			}

			$htaccess_added = false;

			if ( 'yes' === $value && $this->htaccess->exists() ) {
				$error = $this->wp_debug->add_htaccess_directives();

				if ( $error instanceof WP_Error ) {
					$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

					return;
				}

				$htaccess_added = 'yes' !== get_option( static::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, static::DISABLE_DIRECT_ACCESS_TO_LOG_DEFAULT );
			}

			$debug_values = array(
				'WP_DEBUG'     => $value,
				'WP_DEBUG_LOG' => $value,
			);

			if ( 'yes' !== $value ) {
				$debug_values['WP_DEBUG_DISPLAY'] = $value;
			}

			$error = $this->wp_debug->apply_debug_values( $debug_values );

			if ( $error instanceof WP_Error ) {
				if ( $htaccess_added ) {
					$this->wp_debug->remove_htaccess_directives();
				}

				$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

				return;
			}

			update_option( static::ENABLE_WP_DEBUG_KEY, $value );
			update_option( static::ENABLE_WP_DEBUG_LOG_KEY, $value );

			if ( 'yes' !== $value ) {
				update_option( static::ENABLE_WP_DEBUG_DISPLAY_KEY, $value );
			} elseif ( $this->htaccess->exists() ) {
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

			$error = $this->wp_debug->add_htaccess_directives();

			if ( $error instanceof WP_Error ) {
				$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

				return;
			}

			update_option( static::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, 'yes' );
			$this->admin_notice->add_transient( __( 'Direct access to the <code>debug.log</code> file disabled.', 'development-assistant' ), 'success' );
		};
	}

	protected function handle_disable_debug_display(): callable {
		return function (): void {
			$error = $this->wp_debug->apply_debug_values( array( 'WP_DEBUG_DISPLAY' => 'no' ) );

			if ( $error instanceof WP_Error ) {
				$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

				return;
			}

			update_option( static::ENABLE_WP_DEBUG_DISPLAY_KEY, 'no' );
			$this->admin_notice->add_transient( __( '<code>WP_DEBUG_DISPLAY</code> disabled.', 'development-assistant' ), 'success' );
		};
	}

	protected function handle_enable_debug_log(): callable {
		return function (): void {
			$htaccess_added = false;

			if ( $this->htaccess->exists() ) {
				$error = $this->wp_debug->add_htaccess_directives();

				if ( $error instanceof WP_Error ) {
					$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

					return;
				}

				$htaccess_added = 'yes' !== get_option( static::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, static::DISABLE_DIRECT_ACCESS_TO_LOG_DEFAULT );
			}

			$error = $this->wp_debug->apply_debug_values(
				array(
					'WP_DEBUG'     => 'yes',
					'WP_DEBUG_LOG' => 'yes',
				)
			);

			if ( $error instanceof WP_Error ) {
				if ( $htaccess_added ) {
					$this->wp_debug->remove_htaccess_directives();
				}

				$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

				return;
			}

			update_option( static::ENABLE_WP_DEBUG_KEY, 'yes' );
			update_option( static::ENABLE_WP_DEBUG_LOG_KEY, 'yes' );

			if ( $this->htaccess->exists() ) {
				update_option( static::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, 'yes' );
			}

			$this->admin_notice->add_transient( __( '<code>WP_DEBUG_LOG</code> enabled.', 'development-assistant' ), 'success' );
		};
	}

	protected function sanitize_debug_option( string $constant, string $option ): callable {
		return function ( $value ) use ( $constant, $option ): string {
			$value = 'yes' === $value ? 'yes' : 'no';
			$old   = get_option( $option, 'no' );

			if ( $old === $value ) {
				return $value;
			}

			$error = $this->wp_debug->apply_debug_values( array( $constant => $value ) );

			if ( $error instanceof WP_Error ) {
				add_settings_error( static::KEY, $error->get_error_code(), $error->get_error_message(), 'error' );

				return $old;
			}

			return $value;
		};
	}

	protected function sanitize_htaccess_option(): callable {
		return function ( $value ): string {
			$value = 'yes' === $value ? 'yes' : 'no';
			$old   = get_option( static::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, static::DISABLE_DIRECT_ACCESS_TO_LOG_DEFAULT );

			if ( $old === $value ) {
				return $value;
			}

			$error = 'yes' === $value ? $this->wp_debug->add_htaccess_directives() : $this->wp_debug->remove_htaccess_directives();

			if ( $error instanceof WP_Error ) {
				add_settings_error( static::KEY, $error->get_error_code(), $error->get_error_message(), 'error' );

				return $old;
			}

			return $value;
		};
	}
}

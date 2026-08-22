<?php
namespace WPDevAssist;

use Exception;

defined( 'ABSPATH' ) || exit;

class App {
	protected static ?self $instance = null;

	protected ActionQuery $action_query;
	protected AdminNotice $admin_notice;
	protected Asset $asset;
	protected ExternalFileMutationManager $file_mutations;
	protected Fs $fs;
	protected Assistant $assistant;
	protected Htaccess $htaccess;
	protected PluginsScreen $plugins_screen;
	protected Setting $setting;
	protected WPDebug $wp_debug;

	public static function get_instance(): self {
		if ( ! static::$instance instanceof self ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * @throws Exception
	 */
	public function __construct() {
		$this->action_query   = new ActionQuery();
		$this->admin_notice   = new AdminNotice( KEY );
		$this->fs             = new Fs();
		$this->asset          = new Asset( KEY, $this->fs );
		$this->file_mutations = new ExternalFileMutationManager();

		$this->htaccess       = new Htaccess( $this->file_mutations );
		$this->wp_debug       = new WPDebug( $this->admin_notice, $this->file_mutations, $this->htaccess, new DebugConfigEditor() );
		$this->setting        = new Setting(
			$this->action_query,
			$this->asset,
			$this->fs,
			$this->file_mutations,
			$this->admin_notice,
			$this->htaccess,
			$this->wp_debug
		);
		$this->plugins_screen = new PluginsScreen(
			$this->action_query,
			$this->asset,
			$this->admin_notice,
			$this->setting,
			$this->file_mutations
		);
		$this->assistant      = new Assistant(
			$this->asset,
			$this->action_query,
			$this->setting,
			$this->htaccess
		);

		register_activation_hook( ROOT_FILE, $this->activate() );
		register_deactivation_hook( ROOT_FILE, $this->deactivate() );
		add_action( 'init', $this->init() );
	}

	public function action_query(): ActionQuery {
		return $this->action_query;
	}

	public function admin_notice(): AdminNotice {
		return $this->admin_notice;
	}

	public function asset(): Asset {
		return $this->asset;
	}

	public function fs(): Fs {
		return $this->fs;
	}

	protected function init(): callable {
		return function (): void {
			load_plugin_textdomain(
				'development-assistant',
				false,
				dirname( plugin_basename( ROOT_FILE ) ) . '/lang'
			);
		};
	}

	protected function activate(): callable {
		return function (): void {
			$this->setting->add_default_options();
			$this->setting->debug_log()->store_original_file_existence();
			$this->setting->support_user()->add_default_options();
			$this->wp_debug->store_original_config_const();

			if ( 'yes' === get_option( Setting::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, Setting::DISABLE_DIRECT_ACCESS_TO_LOG_DEFAULT ) ) {
				$error = $this->wp_debug->add_htaccess_directives();

				if ( $error instanceof \WP_Error ) {
					$this->admin_notice->add_transient( $error->get_error_message(), 'error' );
				}
			}
		};
	}

	protected function deactivate(): callable {
		return function (): void {
			$error = $this->wp_debug->remove_htaccess_directives();

			if ( $error instanceof \WP_Error ) {
				$this->admin_notice->add_transient( $error->get_error_message(), 'error' );
			}

			if ( 'yes' !== get_option( Setting::RESET_KEY, Setting::RESET_DEFAULT ) ) {
				return;
			}

			$this->admin_notice->reset();
			$this->wp_debug->reset_config_const();
			$this->setting->debug_log()->delete_file_if_originally_not_exists();
			$this->setting->support_user()->reset();
			$this->setting->reset();
		};
	}
}

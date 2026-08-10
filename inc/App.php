<?php
namespace WPDevAssist;

defined( 'ABSPATH' ) || exit;

class App {
	protected static ?self $instance = null;

	protected ActionQuery $action_query;
	protected AdminNotice $admin_notice;
	protected Asset $asset;
	protected Env $env;
	protected Fs $fs;
	protected Assistant $assistant;
	protected Htaccess $htaccess;
	protected MailHog $mail_hog;
	protected PluginsScreen $plugins_screen;
	protected Setting $setting;
	protected WPDebug $wp_debug;

	public static function get_instance(): self {
		if ( ! static::$instance instanceof self ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	public function __construct() {
		$this->action_query = new ActionQuery();
		$this->admin_notice = new AdminNotice( KEY );
		$this->fs           = new Fs();
		$this->asset        = new Asset( KEY, $this->fs );
		$this->env          = new Env();

		$this->htaccess       = new Htaccess( $this->fs );
		$this->mail_hog       = new MailHog( $this->action_query, $this->admin_notice );
		$this->wp_debug       = new WPDebug( $this->admin_notice, $this->fs, $this->htaccess );
		$this->setting        = new Setting(
			$this->action_query,
			$this->asset,
			$this->fs,
			$this->admin_notice,
			$this->htaccess,
			$this->mail_hog,
			$this->wp_debug,
			$this->env
		);
		$this->plugins_screen = new PluginsScreen(
			$this->action_query,
			$this->asset,
			$this->admin_notice,
			$this->setting
		);
		$this->assistant      = new Assistant(
			$this->asset,
			$this->action_query,
			$this->setting,
			$this->htaccess,
			$this->mail_hog
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

	public function env(): Env {
		return $this->env;
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
				$this->wp_debug->add_htaccess_directives();
			}
		};
	}

	protected function deactivate(): callable {
		return function (): void {
			$this->wp_debug->remove_htaccess_directives();

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

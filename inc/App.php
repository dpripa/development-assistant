<?php
namespace WPDevAssist;

use WPDevAssist\OmgCore\Core;
use WPDevAssist\OmgCore\Dependency;
use WPDevAssist\OmgCore\Logger;

defined( 'ABSPATH' ) || exit;

class App extends Core {
	protected Assistant $assistant;
	protected Htaccess $htaccess;
	protected MailHog $mail_hog;
	protected PluginsScreen $plugins_screen;
	protected Setting $setting;
	protected WPDebug $wp_debug;

	public function __construct() {
		parent::__construct( ROOT_FILE, KEY );

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
	}

	protected function activate(): callable {
		return function (): void {
			parent::activate()();
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

			parent::deactivate()();
			$this->wp_debug->reset_config_const();
			$this->setting->debug_log()->delete_file_if_originally_not_exists();
			$this->setting->support_user()->reset();
			$this->setting->reset();
		};
	}

	protected function get_core_i18n(): callable {
		return function (): array {
			return array(
				Dependency::class => array(
					'notice_title_required_singular'      => __( 'The <b>%1$s</b> plugin%2$s is <b>required</b> for the <b>%3$s</b> features to function.', 'development-assistant' ),
					'notice_title_optional_singular'      => __( 'The <b>%1$s</b> plugin%2$s is <b>recommended</b> for the all <b>%3$s</b> features to function.', 'development-assistant' ),
					'notice_title_required_plural'        => __( 'The following plugins are <b>required</b> for the <b>%s"/b> features to function:', 'development-assistant' ),
					'notice_title_optional_plural'        => __( 'The following plugins are <b>recommended</b> for the all <b>%s</b> features to function:', 'development-assistant' ),
					'notice_item_not_installed'           => __( 'not installed', 'development-assistant' ),
					'notice_item_undefiled_installation_url' => __( 'not installed, can\'t be installed automatically', 'development-assistant' ),
					'notice_btn_activate'                 => __( 'Activate', 'development-assistant' ),
					'notice_btn_install_and_activate'     => __( 'Install and activate', 'development-assistant' ),
					'notice_btn_activate_only_required'   => __( 'Activate only required', 'development-assistant' ),
					'notice_btn_install_and_activate_only_required' => __( 'Install and activate only required', 'development-assistant' ),
					'notice_success_activate'             => __( 'Required plugin(s) activated.', 'development-assistant' ),
					'notice_success_install_and_activate' => __( 'Required plugin(s) installed and activated.', 'development-assistant' ),
					'notice_error_install'                => __( 'The "%1$s" plugin can\'t be installed automatically. Please install it manually.', 'development-assistant' ),
				),
				Logger::class     => array(
					'notice_delete_log_error'         => __( 'An error occurred while trying to delete %s log file(s).', 'development-assistant' ),
					'notice_delete_log_all_success'   => __( 'All %s log files have been successfully deleted.', 'development-assistant' ),
					'notice_delete_log_group_success' => __( 'The %1$s %2$s log file has been successfully deleted.', 'development-assistant' ),
				),
			);
		};
	}
}

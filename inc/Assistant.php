<?php
namespace WPDevAssist;

use Exception;
use WPDevAssist\ActionQuery;
use WPDevAssist\Asset;

defined( 'ABSPATH' ) || exit;

class Assistant {
	public const TITLE_HOOK = KEY . '_assistant_panel_title';

	protected Asset $asset;
	protected ActionQuery $action_query;
	protected Setting $setting;
	protected Htaccess $htaccess;
	protected MailHog $mail_hog;

	public function __construct(
		Asset $asset,
		ActionQuery $action_query,
		Setting $setting,
		Htaccess $htaccess,
		MailHog $mail_hog
	) {
		$this->asset        = $asset;
		$this->action_query = $action_query;
		$this->setting      = $setting;
		$this->htaccess     = $htaccess;
		$this->mail_hog     = $mail_hog;

		add_action( 'admin_init', $this->init(), 1 );
	}

	/**
	 * @return Assistant\Section[]
	 * @throws Exception
	 */
	protected function get_sections(): array {
		$sections = array(
			new Assistant\WPDebug( $this->action_query, $this->setting->debug_log(), $this->htaccess ),
		);

		if ( 'yes' === get_option( Setting\DevEnv::ENABLE_KEY, Setting\DevEnv::ENABLE_DEFAULT ) ) {
			$sections[] = new Assistant\MailHog( $this->action_query, $this->mail_hog, $this->setting->dev_env() );
		}

		if (
			apply_filters( Setting\SupportUser::ENABLE_HOOK, true ) &&
			'yes' === get_option( Setting\SupportUser::ENABLE_KEY, Setting\SupportUser::ENABLE_DEFAULT )
		) {
			$sections[] = new Assistant\SupportUser( $this->action_query, $this->setting->support_user() );
		}

		return $sections;
	}

	protected function init(): callable {
		return function (): void {
			if (
				! current_user_can( 'administrator' ) || // phpcs:ignore
				'yes' !== get_option( Setting::ENABLE_ASSISTANT_KEY, Setting::ENABLE_ASSISTANT_DEFAULT )
			) {
				return;
			}

			add_action( 'admin_enqueue_scripts', $this->enqueue_scripts() );
			add_action( 'admin_notices', $this->render() );
		};
	}

	protected function render(): callable {
		return function (): void {
			global $pagenow;

			$sections          = $this->get_sections();
			$is_forced_be_open = false;

			foreach ( $sections as $section ) {
				if ( $section->is_forces_panel_be_open() ) {
					$is_forced_be_open = true;
					break;
				}
			}

			$is_open = $is_forced_be_open ||
				( 'yes' === get_option( Setting::ASSISTANT_OPENED_ON_WP_DASHBOARD_KEY, Setting::ASSISTANT_OPENED_ON_WP_DASHBOARD_DEFAULT ) && 'index.php' === $pagenow );
			?>
			<div class="da-assistant <?php echo $is_open ? 'da-assistant_open' : ''; ?>">
				<button class="da-assistant__header" type="button">
				<span class="da-assistant__header-content">
					<span class="da-assistant__icon dashicons dashicons-pets"></span>
					<span class="da-assistant__title">
						<?php
						echo esc_html(
							apply_filters(
								static::TITLE_HOOK,
								__( 'Assistant Panel', 'development-assistant' )
							)
						);
						?>
					</span>
					<span class="da-assistant__statuses">
						<?php
						foreach ( $sections as $section ) {
							if ( $section->configure_status() ) {
								$section->render_status();
							}
						}
						?>
					</span>
				</span>
					<span class="da-assistant__arrow-down"></span>
				</button>
				<?php
				foreach ( $sections as $section ) {
					$section->render();
				}
				?>
			</div>
			<?php
		};
	}

	protected function enqueue_scripts(): callable {
		return function (): void {
			$this->asset
				->enqueue_style( 'assistant' )
				->enqueue_script( 'assistant', array( 'jquery' ) );
		};
	}
}

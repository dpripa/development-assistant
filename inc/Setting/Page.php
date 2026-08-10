<?php
namespace WPDevAssist\Setting;

use WPDevAssist\Asset;
use WPDevAssist\AdminNotice;

defined( 'ABSPATH' ) || exit;

abstract class Page extends BasePage {
	protected Asset $asset;
	protected AdminNotice $admin_notice;

	public function __construct( Asset $asset, AdminNotice $admin_notice ) {
		$this->asset        = $asset;
		$this->admin_notice = $admin_notice;

		add_action( 'admin_menu', $this->add_page() );
		add_action( 'admin_enqueue_scripts', $this->enqueue_assets() );
		parent::__construct( $admin_notice );
	}

	public function get_toplevel_title( bool $lowercase = false ): string {
		$title = __( 'DevAssistant', 'development-assistant' );

		return $lowercase ? mb_strtolower( $title ) : $title;
	}

	abstract protected function add_page(): callable;

	protected function get_general_tab_title(): string {
		return __( 'General', 'development-assistant' );
	}

	/**
	 * @return string[]
	 */
	protected function get_tabs(): array {
		return array();
	}

	protected function render_page(): callable {
		return function (): void {
			?>
			<div class="da-setting-page wrap">
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<?php
				$this->render_tabs();
				$this->render_content();
				?>
			</div>
			<?php
		};
	}

	protected function render_tabs(): void {
		$tabs = $this->get_tabs();

		if ( $tabs ) {
			?>
			<div class="nav-tab-wrapper">
				<?php
				$this->render_tab_link( $this->get_page_url(), $this->get_general_tab_title(), $this->is_current() );

				foreach ( $tabs as $tab ) {
					/** @var Tab $tab */
					$this->render_tab_link( $tab->get_url(), $tab->get_title(), $tab->is_current() );
				}
				?>
			</div>
			<?php
		}
	}

	protected function render_tab_link( string $url, string $title, bool $is_active ): void {
		if ( $is_active ) {
			?>
			<span class="nav-tab nav-tab-active" style="cursor: default;">
				<?php echo esc_html( $title ); ?>
			</span>
			<?php
		} else {
			?>
			<a href="<?php echo esc_url( $url ); ?>" class="nav-tab">
				<?php echo esc_html( $title ); ?>
			</a>
			<?php
		}
	}

	protected function render_content(): void {
		$option_group = isset( $_GET['tab'] ) ? // phpcs:ignore
			sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : // phpcs:ignore
			(
				isset( $_GET['page'] ) ? // phpcs:ignore
					sanitize_text_field( wp_unslash( $_GET['page'] ) ) : // phpcs:ignore
					''
			);
		?>
		<form method="post" action="<?php echo esc_url( get_admin_url( null, 'options.php' ) ); ?>">
			<?php
			settings_fields( $option_group );
			do_settings_sections( $option_group );
			submit_button();
			?>
		</form>
		<?php
	}

	public function get_page_url(): string {
		return add_query_arg( array( 'page' => static::KEY ), get_admin_url( null, 'admin.php' ) );
	}

	public function add_default_options(): void {
		foreach ( $this->get_tabs() as $tab ) {
			/** @var Tab $tab */
			$tab->add_default_options();
		}
	}

	protected function enqueue_assets(): callable {
		return function (): void {
			if ( ! $this->is_setting_page() ) {
				return;
			}

			$this->asset->enqueue_style( 'setting' );
		};
	}

	public function is_current(): bool {
		return $this->is_setting_page() && empty( $_GET['tab'] ); // phpcs:ignore
	}

	public function reset(): void {
		foreach ( $this->get_tabs() as $tab ) {
			/** @var Tab $tab */
			$tab->reset();
		}

		parent::reset();
	}
}

<?php
namespace WPDevAssist;

use WPDevAssist\ActionQuery;
use WPDevAssist\AdminNotice;
use WPDevAssist\Asset;
use WPDevAssist\PluginsScreen\ActivationManager;
use WPDevAssist\PluginsScreen\Downloader;

defined( 'ABSPATH' ) || exit;

class PluginsScreen {
	protected const COLUMN_KEY = KEY . '_dev_actions';

	protected ActionQuery $action_query;
	protected Asset $asset;
	protected AdminNotice $admin_notice;
	protected Setting $setting;
	protected ActivationManager $activation_manager;
	protected Downloader $downloader;

	public function __construct( ActionQuery $action_query, Asset $asset, AdminNotice $admin_notice, Setting $setting ) {
		$this->asset              = $asset;
		$this->setting            = $setting;
		$this->activation_manager = new ActivationManager( $action_query, $admin_notice );
		$this->downloader         = new Downloader( $action_query, $admin_notice );

		if ( 'yes' === get_option( Setting::ACTIVE_PLUGINS_FIRST_KEY, Setting::ACTIVE_PLUGINS_FIRST_DEFAULT ) ) {
			add_action( 'admin_head-plugins.php', $this->sort_plugins_by_status() );
		}

		add_filter( 'plugin_action_links_' . plugin_basename( ROOT_FILE ), $this->add_plugin_actions() );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( ROOT_FILE ), $this->add_plugin_actions() );
		add_filter( 'manage_plugins_columns', $this->add_column() );
		add_action( 'manage_plugins_custom_column', $this->render_column(), 10, 2 );
		add_action( 'admin_enqueue_scripts', $this->enqueue_assets() );
	}

	protected function add_plugin_actions(): callable {
		return function ( array $actions ): array {
			if ( ! current_user_can( 'administrator' ) ) { // phpcs:ignore
				return $actions;
			}

			return array_merge(
				array(
					'settings' => '<a href="' . $this->setting->get_page_url() . '">' . esc_html__( 'Settings', 'development-assistant' ) . '</a>',
				),
				$actions
			);
		};
	}

	protected function add_column(): callable {
		return function ( array $columns ): array {
			if ( ! current_user_can( 'administrator' ) ) { // phpcs:ignore
				return $columns;
			}

			$columns[ static::COLUMN_KEY ] = __( 'Development Assistant', 'development-assistant' );

			return $columns;
		};
	}

	protected function render_column(): callable {
		return function ( string $column_name, string $plugin_file ): void {
			if (
				! current_user_can( 'administrator' ) || // phpcs:ignore
				static::COLUMN_KEY !== $column_name ||
				plugin_basename( ROOT_FILE ) === $plugin_file
			) {
				return;
			}
			?>
			<ul class="da-dev-actions-list">
				<?php if ( is_plugin_active( $plugin_file ) ) { ?>
					<li>
						<a href="<?php echo esc_url( $this->activation_manager->get_deactivation_url( array( $plugin_file ) ) ); ?>">
							<?php echo esc_html__( 'Temporarily deactivate', 'development-assistant' ); ?>
						</a>
					</li>
					<?php
				} elseif ( $this->activation_manager->is_temporarily_deactivated( $plugin_file ) ) {
					?>
					<li><?php echo esc_html__( 'Temporarily deactivated', 'development-assistant' ); ?></li>
					<?php
				}

				if ( $this->downloader->is_available() ) {
					?>
					<li>
						<a href="<?php echo esc_url( $this->downloader->get_url( $plugin_file ) ); ?>">
							<?php echo esc_html__( 'Download', 'development-assistant' ); ?>
						</a>
					</li>
				<?php } ?>
			</ul>
			<?php
		};
	}

	protected function enqueue_assets(): callable {
		return function (): void {
			global $current_screen;

			if (
				'plugins' !== $current_screen->id ||
				! current_user_can( 'administrator' ) // phpcs:ignore
			) {
				return;
			}

			$this->asset
				->enqueue_style( 'plugins-screen' )
				->enqueue_script(
					'plugins-screen',
					array( 'jquery' ),
					array(
						'has_deactivated_plugins'      => empty( get_option( PluginsScreen\ActivationManager::DEACTIVATED_KEY ) ) ? 'no' : 'yes',
						'plugin_activation_url'        => $this->activation_manager->get_activation_url(),
						'plugin_activation_title'      => __( 'Activate temporarily deactivated plugins', 'development-assistant' ),
						'reset'                        => get_option( Setting::RESET_KEY, Setting::RESET_DEFAULT ),
						'deactivation_reset_query_key' => PluginsScreen\ActivationManager::DEACTIVATION_RESET_QUERY_KEY,
						'deactivation_confirm_message' => __( 'Are you sure to deactivate Development Assistant without resetting? You can enable it in the plugin settings.', 'development-assistant' ),
					)
				);
		};
	}

	protected function sort_plugins_by_status(): callable {
		return function (): void {
			global $wp_list_table, $status;

			if ( in_array( $status, array( 'active', 'inactive', 'recently_activated', 'mustuse' ), true ) ) {
				return;
			}

			uksort(
				$wp_list_table->items,
				function ( $a, $b ): int {
					global $wp_list_table;

					$a_active = is_plugin_active( $a );
					$b_active = is_plugin_active( $b );

					if ( $a_active && ! $b_active ) {
						return -1;
					} elseif ( ! $a_active && $b_active ) {
						return 1;
					} else {
						return strcasecmp( $wp_list_table->items[ $a ]['Name'], $wp_list_table->items[ $b ]['Name'] );
					}
				}
			);
		};
	}
}

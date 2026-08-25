<?php
namespace WPDevAssist;

use Exception;
use WP_Admin_Bar;
use WPDevAssist\ActionQuery;
use WPDevAssist\Asset;

defined( 'ABSPATH' ) || exit;

class Assistant {
	public const TITLE_HOOK = KEY . '_assistant_panel_title';
	protected const MENU_ID = KEY . '_assistant';

	protected Asset $asset;
	protected ActionQuery $action_query;
	protected Setting $setting;
	protected Htaccess $htaccess;

	public function __construct(
		Asset $asset,
		ActionQuery $action_query,
		Setting $setting,
		Htaccess $htaccess
	) {
		$this->asset        = $asset;
		$this->action_query = $action_query;
		$this->setting      = $setting;
		$this->htaccess     = $htaccess;

		add_action( 'admin_bar_menu', $this->add_menu(), 90 );
		add_action( 'admin_enqueue_scripts', $this->enqueue_assets() );
		add_action( 'wp_enqueue_scripts', $this->enqueue_assets() );
	}

	/**
	 * @return Assistant\Section[]
	 * @throws Exception
	 */
	protected function get_sections(): array {
		$action_base_url = $this->setting->get_page_url();
		$sections        = array(
			new Assistant\WPDebug( $this->action_query, $this->setting->debug_log(), $this->htaccess, $action_base_url ),
		);

		if (
			apply_filters( Setting\SupportUser::ENABLE_HOOK, true ) &&
			'yes' === get_option( Setting\SupportUser::ENABLE_KEY, Setting\SupportUser::ENABLE_DEFAULT )
		) {
			$sections[] = new Assistant\SupportUser( $this->action_query, $this->setting->support_user(), $action_base_url );
		}

		return $sections;
	}

	protected function is_available(): bool {
		return current_user_can( 'administrator' ) && // phpcs:ignore
			'yes' === get_option( Setting::ENABLE_ASSISTANT_KEY, Setting::ENABLE_ASSISTANT_DEFAULT );
	}

	protected function add_menu(): callable {
		return function ( WP_Admin_Bar $admin_bar ): void {
			if ( ! $this->is_available() ) {
				return;
			}

			$sections     = $this->get_sections();
			$status_level = 'success';

			foreach ( $sections as $section ) {
				$section->configure_status();

				if ( $this->get_status_priority( $section->get_status_level() ) > $this->get_status_priority( $status_level ) ) {
					$status_level = $section->get_status_level();
				}
			}

			$title = apply_filters(
				static::TITLE_HOOK,
				__( 'Development Assistant', 'development-assistant' )
			);

			$admin_bar->add_node(
				array(
					'id'    => static::MENU_ID,
					'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html( $title ) . '</span>',
					'meta'  => array(
						'class'      => 'da-assistant da-assistant_' . $status_level,
						'menu_title' => esc_attr__( 'Development Assistant', 'development-assistant' ),
						'tabindex'   => 0,
					),
				)
			);

			foreach ( $sections as $index => $section ) {
				$this->add_section( $admin_bar, $section, $index );
			}

			$admin_bar->add_node(
				array(
					'id'     => static::MENU_ID . '_settings',
					'parent' => static::MENU_ID,
					'title'  => esc_html__( 'Settings', 'development-assistant' ),
					'href'   => $this->setting->get_page_url(),
					'meta'   => array( 'class' => 'da-assistant__settings' ),
				)
			);
		};
	}

	protected function add_section( WP_Admin_Bar $admin_bar, Assistant\Section $section, int $index ): void {
		$group_id = static::MENU_ID . '_group_' . $index;

		$admin_bar->add_group(
			array(
				'id'     => $group_id,
				'parent' => static::MENU_ID,
				'meta'   => array(
					'class' => 'da-assistant__group da-assistant__group_' . $section->get_status_level(),
				),
			)
		);

		$admin_bar->add_node(
			array(
				'id'     => $group_id . '_summary',
				'parent' => $group_id,
				'title'  => $this->get_section_markup( $section ),
				'meta'   => array( 'class' => 'da-assistant__summary' ),
			)
		);

		foreach ( $section->get_controls() as $control_index => $control ) {
			$meta = array(
				'class' => trim( 'da-assistant__action ' . $control->get_class_names() ),
			);

			if ( $control->get_confirm() && ! $control->is_disabled() ) {
				$meta['onclick'] = 'return confirm("' . $control->get_confirm() . '")';
			}

			if ( $control->is_target_blank() ) {
				$meta['target'] = '_blank';
				$meta['rel']    = 'noopener noreferrer';
			}

			$admin_bar->add_node(
				array(
					'id'     => $group_id . '_action_' . $control_index,
					'parent' => $group_id,
					'title'  => wp_kses( $control->get_title(), array( 'code' => array() ) ),
					'href'   => $control->is_disabled() ? false : $control->get_url(),
					'meta'   => $meta,
				)
			);
		}
	}

	protected function get_section_markup( Assistant\Section $section ): string {
		$allowed_html = array(
			'a'    => array( 'href' => array() ),
			'b'    => array(),
			'br'   => array(),
			'code' => array(),
			'li'   => array(),
			'span' => array( 'class' => array() ),
			'ul'   => array( 'class' => array() ),
		);

		return '<span class="da-assistant__section-heading">' .
			'<span class="da-assistant__section-title">' . esc_html( $section->get_title() ) . '</span>' .
			'<span class="da-assistant__status">' . esc_html( $section->get_status_description() ) . '</span>' .
			'</span>' .
			'<span class="da-assistant__content">' . wp_kses( $section->get_content(), $allowed_html ) . '</span>';
	}

	protected function get_status_priority( string $status_level ): int {
		$priorities = array(
			'success' => 0,
			'warning' => 1,
			'error'   => 2,
		);

		return $priorities[ $status_level ] ?? 0;
	}

	protected function enqueue_assets(): callable {
		return function (): void {
			if ( $this->is_available() && is_admin_bar_showing() ) {
				$this->asset
					->enqueue_style( 'assistant' )
					->enqueue_script( 'assistant' );
			}
		};
	}
}

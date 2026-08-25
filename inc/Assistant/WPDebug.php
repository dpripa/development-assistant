<?php
namespace WPDevAssist\Assistant;

use WPDevAssist\Htaccess;
use WPDevAssist\ActionQuery;
use WPDevAssist\Setting;
use WPDevAssist\Setting\DebugLog;

defined( 'ABSPATH' ) || exit;

class WPDebug extends Section {
	protected ActionQuery $action_query;
	protected DebugLog $debug_log;
	protected array $checked_constants = array();
	protected bool $is_debug_log_exists;
	protected bool $is_htaccess_exists;
	protected bool $is_disabled_direct_access_to_log;
	protected string $debug_enabled;
	protected string $log_enabled;
	protected string $display_enabled;
	protected string $action_base_url;

	public function __construct( ActionQuery $action_query, DebugLog $debug_log, Htaccess $htaccess, string $action_base_url ) {
		$this->action_query                     = $action_query;
		$this->debug_log                        = $debug_log;
		$this->is_debug_log_exists              = $debug_log->is_file_exists();
		$this->is_htaccess_exists               = $htaccess->exists();
		$this->is_disabled_direct_access_to_log = 'yes' === get_option( Setting::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, Setting::DISABLE_DIRECT_ACCESS_TO_LOG_DEFAULT );
		$this->debug_enabled                    = get_option( Setting::ENABLE_WP_DEBUG_KEY, Setting::ENABLE_WP_DEBUG_DEFAULT );
		$this->log_enabled                      = get_option( Setting::ENABLE_WP_DEBUG_LOG_KEY, Setting::ENABLE_WP_DEBUG_LOG_DEFAULT );
		$this->display_enabled                  = get_option( Setting::ENABLE_WP_DEBUG_DISPLAY_KEY, Setting::ENABLE_WP_DEBUG_DISPLAY_DEFAULT );
		$this->action_base_url                  = $action_base_url;

		$this->check_constants();
		parent::__construct();
	}

	protected function set_title(): void {
		$this->title = __( 'WP Debug', 'development-assistant' );
	}

	protected function set_content(): void {
		$checked_constants = array_reduce(
			$this->checked_constants,
			function ( string $result, string $const_name ): string {
				return $result . ( $result ? ', ' : '' ) . "<code>$const_name</code>";
			},
			''
		);

		if ( $this->checked_constants ) {
			$this->content .= sprintf( __( 'The following constants are enabled: %s.', 'development-assistant' ), $checked_constants );
			$this->content .= '<br><b>' . __( 'Don\'t leave it enabled unless you are debugging to avoid the performance issues.', 'development-assistant' ) . '</b>';

			if ( ! $this->is_disabled_direct_access_to_log && $this->is_htaccess_exists ) {
				$this->content .= '<br>' . __( 'Also, for security reasons, it\'s highly recommended to disable direct access to the <code>debug.log</code> file.', 'development-assistant' );
			} else {
				$this->content .= '<br>' . __( 'Direct access to the <code>debug.log</code> file is disabled.', 'development-assistant' );
			}

			if ( 'yes' === $this->display_enabled ) {
				$this->content .= '<br><span class="da-assistant__error-message"><b>' . __( 'Warning!', 'development-assistant' ) . '</b> ' . __( 'Enabled <code>WP_DEBUG_DISPLAY</code> may cause the entire interface blocking due to the display of error messages, as well as a critical security issues. <b>It is highly recommended to disable it.</b>', 'development-assistant' ) . '</span>';
			}
		} else {
			$this->content .= __( 'Everything is fine, debug mode is disabled, error information isn\'t displayed or logged.', 'development-assistant' );

			if ( $this->is_debug_log_exists ) {
				if ( $this->is_disabled_direct_access_to_log ) {
					$this->content .= '<br>' . __( 'The <code>debug.log</code> file still exists, but it is protected from direct access, so don\'t worry.', 'development-assistant' );
				} else { // phpcs:ignore
					if ( $this->is_htaccess_exists ) {
						$this->content .= '<br><b>' . __( 'But <code>debug.log</code> file still exists, it\'s important to delete it or disable direct access.', 'development-assistant' ) . '</b>';
					} else {
						$this->content .= '<br><b>' . __( 'But <code>debug.log</code> file still exists, it\'s important to delete it.', 'development-assistant' ) . '</b>';
					}
				}
			}
		}
	}

	protected function check_constants(): void {

		if ( 'no' !== $this->debug_enabled ) {
			$this->checked_constants[] = 'WP_DEBUG';
		}

		if ( 'no' !== $this->log_enabled ) {
			$this->checked_constants[] = 'WP_DEBUG_LOG';
		}

		if ( 'no' !== $this->display_enabled ) {
			$this->checked_constants[] = 'WP_DEBUG_DISPLAY';
		}
	}

	protected function set_controls(): void {
		if ( $this->is_debug_log_exists ) {
			$this->controls[] = new Control(
				__( 'Go to <code>debug.log</code>', 'development-assistant' ),
				$this->debug_log->get_page_url()
			);
		}

		if ( 'yes' === $this->display_enabled ) {
			$this->controls[] = new Control(
				__( 'Disable <code>WP_DEBUG_DISPLAY</code>', 'development-assistant' ),
				$this->action_query->get_url( Setting::DISABLE_DEBUG_DISPLAY_QUERY_KEY, $this->action_base_url ),
			);
		}

		if ( $this->checked_constants ) {
			$this->controls[] = new Control(
				__( 'Disable debug mode', 'development-assistant' ),
				$this->action_query->get_url( Setting::TOGGLE_DEBUG_MODE_QUERY_KEY, $this->action_base_url, 'no' ),
				__( 'Are you sure to disable debug mode?', 'development-assistant' )
			);
		} else {
			$this->controls[] = new Control(
				__( 'Enable debug mode', 'development-assistant' ),
				$this->action_query->get_url( Setting::TOGGLE_DEBUG_MODE_QUERY_KEY, $this->action_base_url ),
				__( 'Are you sure to enable debug mode?', 'development-assistant' )
			);
		}

		if (
			( $this->checked_constants || $this->is_debug_log_exists ) &&
			! $this->is_disabled_direct_access_to_log && $this->is_htaccess_exists
		) {
			$this->controls[] = new Control(
				__( 'Disable direct access to <code>debug.log</code>', 'development-assistant' ),
				$this->action_query->get_url( Setting::DISABLE_DIRECT_ACCESS_TO_LOG_QUERY_KEY, $this->action_base_url ),
			);
		}

		if (
			( ! $this->checked_constants || ! $this->is_disabled_direct_access_to_log ) &&
			$this->is_debug_log_exists
		) {
			$this->controls[] = new Control(
				__( 'Delete log file', 'development-assistant' ),
				$this->action_query->get_url( DebugLog::DELETE_LOG_QUERY_KEY, $this->action_base_url ),
				$this->debug_log->get_deletion_confirmation_massage()
			);
		}
	}

	public function configure_status(): bool {
		if ( $this->checked_constants ) {
			if ( ! $this->is_disabled_direct_access_to_log && $this->is_htaccess_exists ) {
				$this->status_level       = 'error';
				$this->status_description = __( 'Enabled with direct access to logs', 'development-assistant' );
			} else {
				if ( 'yes' === $this->display_enabled ) {
					$this->status_level = 'error';
				} else {
					$this->status_level = 'warning';
				}
				$this->status_description = __( 'Enabled', 'development-assistant' );
			}
		} else { // phpcs:ignore
			if ( $this->is_debug_log_exists && ! $this->is_disabled_direct_access_to_log ) {
				$this->status_level       = 'error';
				$this->status_description = __( 'Disabled, but logs exists', 'development-assistant' );
			} else {
				$this->status_level       = 'success';
				$this->status_description = __( 'Disabled', 'development-assistant' );
			}
		}

		return true;
	}
}

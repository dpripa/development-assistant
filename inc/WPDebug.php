<?php
namespace WPDevAssist;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class WPDebug {
	protected const ORIGINAL_DEBUG_VALUE_KEY       = KEY . '_original_wp_debug_value';
	protected const ORIGINAL_DEBUG_VALUE_DEFAULT   = 'disabled';
	protected const ORIGINAL_LOG_VALUE_KEY         = KEY . '_original_wp_debug_log_value';
	protected const ORIGINAL_LOG_VALUE_DEFAULT     = 'disabled';
	protected const ORIGINAL_DISPLAY_VALUE_KEY     = KEY . '_original_wp_debug_display_value';
	protected const ORIGINAL_DISPLAY_VALUE_DEFAULT = 'disabled';
	protected const HTACCESS_MARKER                = KEY . '_debug_log';

	protected AdminNotice $admin_notice;
	protected DebugConfigEditor $editor;
	protected ExternalFileMutationManager $file_mutations;
	protected Htaccess $htaccess;

	public function __construct(
		AdminNotice $admin_notice,
		ExternalFileMutationManager $file_mutations,
		Htaccess $htaccess,
		DebugConfigEditor $editor
	) {
		$this->admin_notice   = $admin_notice;
		$this->editor         = $editor;
		$this->file_mutations = $file_mutations;
		$this->htaccess       = $htaccess;
	}

	/**
	 * @param array<string, string> $values Constant names mapped to yes/no.
	 *
	 * @return WP_Error|null
	 */
	public function apply_debug_values( array $values ): ?WP_Error {
		$allowed = array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY' );

		foreach ( $values as $name => $value ) {
			if ( ! in_array( $name, $allowed, true ) || ! in_array( $value, array( 'yes', 'no' ), true ) ) {
				return new WP_Error( 'debug_setting_invalid', __( 'Development Assistant refused an invalid debug setting.', 'development-assistant' ) );
			}
		}

		$result = $this->file_mutations->mutate(
			ExternalFileMutationManager::TARGET_WP_CONFIG,
			function ( string $content ) use ( $values ) {
				foreach ( $values as $name => $value ) {
					$desired = $this->get_desired_value( $content, $name, 'yes' === $value );

					if ( $desired instanceof WP_Error ) {
						return $desired;
					}

					$content = $this->editor->set( $content, $name, $desired );

					if ( $content instanceof WP_Error ) {
						return $content;
					}
				}

				return $content;
			},
			function ( string $content ) use ( $values ) {
				return $this->validate_values( $content, $values );
			}
		);

		return $result instanceof WP_Error ? $result : null;
	}

	/**
	 * @param array{type: string, value?: bool|string} $state
	 *
	 * @return string|WP_Error
	 */
	protected function update_config_const( string $name, array $state, string $config_content ) {
		if ( 'missing' === $state['type'] ) {
			return $this->editor->remove( $config_content, $name );
		}

		return $this->editor->set( $config_content, $name, $state['value'] );
	}

	/**
	 * @return string|WP_Error
	 */
	protected function read_config_content() {
		return $this->file_mutations->read( ExternalFileMutationManager::TARGET_WP_CONFIG );
	}

	public function is_debug_enabled(): bool {
		return defined( 'WP_DEBUG' ) && (bool) WP_DEBUG;
	}

	public function is_debug_log_enabled(): bool {
		return defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG;
	}

	public function is_debug_display_enabled(): bool {
		return defined( 'WP_DEBUG_DISPLAY' ) && (bool) WP_DEBUG_DISPLAY;
	}

	public function store_original_config_const(): void {
		$content = $this->read_config_content();

		if ( $content instanceof WP_Error ) {
			$this->admin_notice->add_transient( $content->get_error_message(), 'error' );

			return;
		}

		$this->store_original_value( static::ORIGINAL_DEBUG_VALUE_KEY, 'WP_DEBUG', $content );
		$this->store_original_value( static::ORIGINAL_LOG_VALUE_KEY, 'WP_DEBUG_LOG', $content );
		$this->store_original_value( static::ORIGINAL_DISPLAY_VALUE_KEY, 'WP_DEBUG_DISPLAY', $content );
	}

	public function reset_config_const(): void {
		$states = array(
			'WP_DEBUG'         => get_option( static::ORIGINAL_DEBUG_VALUE_KEY, static::ORIGINAL_DEBUG_VALUE_DEFAULT ),
			'WP_DEBUG_LOG'     => get_option( static::ORIGINAL_LOG_VALUE_KEY, static::ORIGINAL_LOG_VALUE_DEFAULT ),
			'WP_DEBUG_DISPLAY' => get_option( static::ORIGINAL_DISPLAY_VALUE_KEY, static::ORIGINAL_DISPLAY_VALUE_DEFAULT ),
		);

		$result = $this->file_mutations->mutate(
			ExternalFileMutationManager::TARGET_WP_CONFIG,
			function ( string $content ) use ( $states ) {
				foreach ( $states as $name => $state ) {
					$value = $this->decode_stored_state( $state );

					if ( $value instanceof WP_Error ) {
						return $value;
					}

					$content = $this->update_config_const( $name, $value, $content );

					if ( $content instanceof WP_Error ) {
						return $content;
					}
				}

				return $content;
			},
			function ( string $content ) use ( $states ) {
				foreach ( $states as $name => $state ) {
					$value      = $this->decode_stored_state( $state );
					$definition = $this->editor->inspect( $content, $name );

					if ( $value instanceof WP_Error || $definition instanceof WP_Error ) {
						return $value instanceof WP_Error ? $value : $definition;
					}

					if ( 'missing' === $value['type'] ) {
						if ( 'missing' !== $definition['type'] ) {
							return false;
						}
					} elseif ( 'missing' === $definition['type'] || $definition['value'] !== $value['value'] ) {
						return false;
					}
				}

				return true;
			}
		);

		if ( $result instanceof WP_Error ) {
			$this->admin_notice->add_transient( $result->get_error_message(), 'error' );

			return;
		}

		delete_option( static::ORIGINAL_DEBUG_VALUE_KEY );
		delete_option( static::ORIGINAL_LOG_VALUE_KEY );
		delete_option( static::ORIGINAL_DISPLAY_VALUE_KEY );
	}

	/**
	 * @return WP_Error|null
	 */
	public function add_htaccess_directives(): ?WP_Error {
		$result = $this->htaccess->replace(
			static::HTACCESS_MARKER,
			'<If "%{REQUEST_URI} =~ m#^/wp-content/debug.log#">\n\t<IfModule mod_authz_core.c>\n\t\tRequire all denied\n\t</IfModule>\n\t<IfModule !mod_authz_core.c>\n\t\tOrder deny,allow\n\t\tDeny from all\n\t</IfModule>\n</If>'
		);

		return $result instanceof WP_Error ? $result : null;
	}

	/**
	 * @return WP_Error|null
	 */
	public function remove_htaccess_directives(): ?WP_Error {
		$result = $this->htaccess->remove( static::HTACCESS_MARKER );

		return $result instanceof WP_Error ? $result : null;
	}

	/**
	 * @return bool|string|WP_Error
	 */
	protected function get_desired_value( string $content, string $name, bool $enabled ) {
		if ( ! $enabled ) {
			return false;
		}

		if ( 'WP_DEBUG_LOG' !== $name ) {
			return true;
		}

		$current = $this->editor->inspect( $content, $name );

		if ( $current instanceof WP_Error ) {
			return $current;
		}

		if ( 'string' === $current['type'] ) {
			return $current['value'];
		}

		$original = $this->decode_stored_state( get_option( static::ORIGINAL_LOG_VALUE_KEY, static::ORIGINAL_LOG_VALUE_DEFAULT ) );

		return ! $original instanceof WP_Error && 'value' === $original['type'] && is_string( $original['value'] ) ? $original['value'] : true;
	}

	/**
	 * @param array<string, string> $values
	 *
	 * @return bool|WP_Error
	 */
	protected function validate_values( string $content, array $values ) {
		foreach ( $values as $name => $value ) {
			$definition = $this->editor->inspect( $content, $name );

			if ( $definition instanceof WP_Error ) {
				return $definition;
			}

			if ( 'missing' === $definition['type'] ) {
				return false;
			}

			$actual_enabled = 'string' === $definition['type'] ? '' !== $definition['value'] : true === $definition['value'];

			if ( ( 'yes' === $value ) !== $actual_enabled ) {
				return false;
			}
		}

		return true;
	}

	protected function store_original_value( string $option, string $name, string $content ): void {
		if ( false !== get_option( $option, false ) ) {
			return;
		}

		$definition = $this->editor->inspect( $content, $name );

		if ( $definition instanceof WP_Error ) {
			$this->admin_notice->add_transient( $definition->get_error_message(), 'error' );

			return;
		}

		if ( 'missing' === $definition['type'] ) {
			$state = 'missing';
		} elseif ( 'string' === $definition['type'] ) {
			$state = 'string:' . base64_encode( $definition['value'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Reversible typed option encoding, not code obfuscation.
		} else {
			$state = $definition['value'] ? 'enabled' : 'disabled';
		}

		update_option( $option, $state );
	}

	/**
	 * @param mixed $state
	 *
	 * @return array{type: string, value?: bool|string}|WP_Error
	 */
	protected function decode_stored_state( $state ) {
		if ( 'enabled' === $state ) {
			return array(
				'type'  => 'value',
				'value' => true,
			);
		}

		if ( 'disabled' === $state ) {
			return array(
				'type'  => 'value',
				'value' => false,
			);
		}

		if ( 'missing' === $state ) {
			return array( 'type' => 'missing' );
		}

		if ( is_string( $state ) && 0 === strpos( $state, 'string:' ) ) {
			$value = base64_decode( substr( $state, 7 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the plugin's own typed option value.

			if ( false !== $value ) {
				return array(
					'type'  => 'value',
					'value' => $value,
				);
			}
		}

		return new WP_Error( 'debug_original_state_invalid', __( 'Development Assistant cannot safely restore an invalid saved debug configuration.', 'development-assistant' ) );
	}
}

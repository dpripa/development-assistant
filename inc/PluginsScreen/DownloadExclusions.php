<?php
namespace WPDevAssist\PluginsScreen;

defined( 'ABSPATH' ) || exit;

class DownloadExclusions {
	public const OPTION        = 'wp_dev_assist_download_exclusions';
	public const DEFAULT_VALUE = "node_modules\n"
		. ".git\n"
		. ".svn/\n"
		. ".hg/\n"
		. ".idea/\n"
		. ".vscode/\n"
		. ".DS_Store\n"
		. "Thumbs.db\n"
		. "desktop.ini\n"
		. "__MACOSX/\n"
		. ".phpunit.cache/\n"
		. ".phpunit.result.cache\n"
		. ".eslintcache\n"
		. ".stylelintcache\n"
		. ".sass-cache/\n"
		. "npm-debug.log\n"
		. "yarn-debug.log\n"
		. "yarn-error.log\n"
		. '.pnpm-debug.log';

	protected array $entries;

	public function __construct() {
		$value         = self::normalize( get_option( self::OPTION, self::DEFAULT_VALUE ) );
		$this->entries = explode( "\n", $value ?? self::DEFAULT_VALUE );
	}

	/**
	 * @param mixed $value Submitted setting.
	 */
	public static function sanitize( $value ): string {
		$normalized = self::normalize( $value );

		if ( null !== $normalized ) {
			return $normalized;
		}

		add_settings_error( self::OPTION, 'invalid_download_exclusions', __( 'Enter literal file or folder names or plugin-relative paths, one per line. Absolute paths, dot path segments, and control characters are not allowed. The previous exclusion list was kept.', 'development-assistant' ) );
		$previous = get_option( self::OPTION, self::DEFAULT_VALUE );

		return is_string( $previous ) ? $previous : self::DEFAULT_VALUE;
	}

	/**
	 * @param mixed $value List of literal names and paths.
	 */
	protected static function normalize( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$entries = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $entry ) {
			$entry = str_replace( '\\', '/', trim( $entry, " \t" ) );

			if ( '' === $entry ) {
				continue;
			}

			if ( '/' === $entry[0] || preg_match( '/^[a-zA-Z]:|[\x00-\x1f\x7f]/', $entry ) ) {
				return null;
			}

			$entry = preg_replace( '#/+#', '/', $entry );

			if ( array_intersect( array( '.', '..' ), explode( '/', $entry ) ) ) {
				return null;
			}

			$entries[] = $entry;
		}

		return implode( "\n", array_unique( $entries ) );
	}

	public function matches( string $relative_path, bool $is_directory = false ): bool {
		$parts = explode( '/', wp_normalize_path( $relative_path ) );
		$path  = '';
		$last  = count( $parts ) - 1;

		foreach ( $parts as $index => $part ) {
			$path      = '' === $path ? $part : $path . '/' . $part;
			$is_folder = $index < $last || $is_directory;

			foreach ( $this->entries as $entry ) {
				if ( '' === $entry ) {
					continue;
				}

				$folder_only = '/' === substr( $entry, -1 );
				$target      = rtrim( $entry, '/' );
				$candidate   = false === strpos( $target, '/' ) ? $part : $path;

				if ( $candidate === $target && ( ! $folder_only || $is_folder ) ) {
					return true;
				}
			}
		}

		return false;
	}
}

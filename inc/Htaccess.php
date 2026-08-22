<?php
namespace WPDevAssist;

use WPDevAssist\Fs;

defined( 'ABSPATH' ) || exit;

class Htaccess {
	protected const PATH = ABSPATH . '.htaccess';

	protected Fs $fs;

	public function __construct( Fs $fs ) {
		$this->fs = $fs;
	}

	public function exists(): bool {
		return file_exists( static::PATH );
	}

	public function replace( string $marker, string $content ): bool {
		if ( ! $this->exists() ) {
			return false;
		}

		$file_content = $this->fs->read_text_file_fully( static::PATH );

		if ( ! $file_content ) {
			return false;
		}

		$pattern = $this->get_pattern( $marker );

		if ( ! empty( $content ) ) {
			$content = $this->get_pattern( $marker, $content );
		}

		if ( preg_match( $pattern, $file_content ) ) {
			$file_content = preg_replace( $pattern, $content, $file_content );
		} elseif ( ! empty( $content ) ) {
			$file_content .= $content;
		} else {
			return $file_content;
		}

		return $this->fs->write_text_file( static::PATH, $file_content, 0644 );
	}

	public function remove( string $marker ): bool {
		return $this->replace( $marker, '' );
	}

	protected function get_pattern( string $marker, string $content = '' ): string {
		$pattern = "# BEGIN $marker.*?# END $marker";

		if ( empty( $content ) ) {
			return "/$pattern/s";
		}

		return str_replace( '.*?', "\n$content\n", $pattern );
	}
}

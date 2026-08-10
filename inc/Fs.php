<?php
namespace WPDevAssist;

defined( 'ABSPATH' ) || exit;

class Fs {
	public function get_url( string $rel = '', bool $stamp = false ): string {
		$url = plugin_dir_url( ROOT_FILE );
		$url = $rel ? ( $url . $rel ) : rtrim( $url, '/\\' );

		if ( $stamp ) {
			$path = $this->get_path( $rel );

			if ( file_exists( $path ) ) {
				$url = add_query_arg( array( 'ver' => filemtime( $path ) ), $url );
			}
		}

		return $url;
	}

	public function get_path( string $rel = '' ): string {
		$path = plugin_dir_path( ROOT_FILE );

		return $rel ? "$path{$rel}" : rtrim( $path, '/\\' );
	}

	public function write_text_file( string $path, string $text, int $permissions = 0600 ): bool {
		$bytes_written = file_put_contents( $path, $text, LOCK_EX ); // phpcs:ignore

		if ( false === $bytes_written ) {
			return false;
		}

		chmod( $path, $permissions ); // phpcs:ignore

		return true;
	}

	public function read_text_file( string $path ): string {
		if ( ! file_exists( $path ) ) {
			return '';
		}

		$file     = fopen( $path, 'r' ); // phpcs:ignore
		$response = '';

		fseek( $file, -1048576, SEEK_END );

		while ( ! feof( $file ) ) {
			$response .= fgets( $file );
		}

		fclose( $file ); // phpcs:ignore

		return $response;
	}
}

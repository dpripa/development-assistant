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
		$file_exists   = file_exists( $path );
		$bytes_written = file_put_contents( $path, $text, LOCK_EX ); // phpcs:ignore

		if ( false === $bytes_written ) {
			return false;
		}

		if ( ! $file_exists ) {
			chmod( $path, $permissions ); // phpcs:ignore
		}

		return true;
	}

	public function read_text_file_fully( string $path ): string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$file = @fopen( $path, 'rb' ); // phpcs:ignore

		if ( false === $file ) {
			return '';
		}

		$response = stream_get_contents( $file );

		fclose( $file ); // phpcs:ignore

		return false === $response ? '' : $response;
	}

	public function read_text_file_tail( string $path, int $max_bytes = 1048576 ): string {
		if ( 0 >= $max_bytes || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$file = @fopen( $path, 'rb' ); // phpcs:ignore

		if ( false === $file ) {
			return '';
		}

		$stats = fstat( $file );

		if ( false === $stats ) {
			fclose( $file ); // phpcs:ignore

			return '';
		}

		$offset = max( 0, $stats['size'] - $max_bytes );

		if ( 0 !== fseek( $file, $offset ) ) {
			fclose( $file ); // phpcs:ignore

			return '';
		}

		$response = stream_get_contents( $file, $max_bytes );

		fclose( $file ); // phpcs:ignore

		return false === $response ? '' : $response;
	}
}

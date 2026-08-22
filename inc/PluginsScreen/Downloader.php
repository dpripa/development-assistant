<?php
namespace WPDevAssist\PluginsScreen;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use WP_Error;
use WPDevAssist\ActionQuery;
use WPDevAssist\AdminNotice;
use ZipArchive;
use const WPDevAssist\KEY;

defined( 'ABSPATH' ) || exit;

class Downloader {
	protected const DOWNLOAD_QUERY_KEY = KEY . '_download_plugin';

	protected ActionQuery $action_query;
	protected AdminNotice $admin_notice;

	public function __construct( ActionQuery $action_query, AdminNotice $admin_notice ) {
		$this->action_query = $action_query;
		$this->admin_notice = $admin_notice;

		if ( ! $this->is_available() ) {
			return;
		}

		$action_query->add( static::DOWNLOAD_QUERY_KEY, $this->handle_download() );
	}

	public function is_available(): bool {
		return class_exists( 'ZipArchive' );
	}

	public function get_url( string $plugin_file ): string {
		return $this->action_query->get_url(
			static::DOWNLOAD_QUERY_KEY,
			get_admin_url( null, 'plugins.php' ),
			$plugin_file
		);
	}

	protected function handle_download(): callable {
		return function ( array $data ): void {
			$plugin_file = sanitize_text_field( wp_unslash( $data[ static::DOWNLOAD_QUERY_KEY ] ) );
			$source      = $this->get_plugin_source( $plugin_file );

			if ( $source instanceof WP_Error ) {
				$this->add_error_notice( $source );

				return;
			}

			$zip_file = $this->get_temp_zip_file( $plugin_file );

			if ( $zip_file instanceof WP_Error ) {
				$this->add_error_notice( $zip_file );

				return;
			}

			$download_ready = false;
			$zip            = new ZipArchive();
			$zip_open       = false;

			try {
				if ( true !== $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
					$this->add_error_notice( new WP_Error( 'archive_open_failed', __( 'Could not create the plugin archive.', 'development-assistant' ) ) );

					return;
				}

				$zip_open = true;

				$archive_error = $this->add_plugin_files( $zip, $source );

				if ( $archive_error instanceof WP_Error ) {
					$zip->close();
					$zip_open = false;
					$this->add_error_notice( $archive_error );

					return;
				}

				if ( ! $zip->close() ) {
					$zip_open = false;
					$this->add_error_notice( new WP_Error( 'archive_write_failed', __( 'Could not finish the plugin archive.', 'development-assistant' ) ) );

					return;
				}

				$zip_open = false;

				if ( ! is_readable( $zip_file ) ) {
					$this->add_error_notice( new WP_Error( 'archive_write_failed', __( 'Could not finish the plugin archive.', 'development-assistant' ) ) );

					return;
				}

				$archive_size = filesize( $zip_file );

				if ( false === $archive_size ) {
					$this->add_error_notice( new WP_Error( 'archive_read_failed', __( 'Could not read the plugin archive.', 'development-assistant' ) ) );

					return;
				}

				header( 'Content-Type: application/zip' );
				header( 'Content-Disposition: attachment; filename="' . $this->get_download_filename( $plugin_file ) . '"' );
				header( 'Content-Length: ' . $archive_size );
				flush();

				if ( false === readfile( $zip_file ) ) { // phpcs:ignore
					$this->add_error_notice( new WP_Error( 'archive_read_failed', __( 'Could not read the plugin archive.', 'development-assistant' ) ) );

					return;
				}

				$download_ready = true;
			} catch ( Throwable $error ) {
				$this->add_error_notice( new WP_Error( 'archive_failed', __( 'Could not create the plugin archive from the selected files.', 'development-assistant' ) ) );
			} finally {
				if ( $zip_open ) {
					$zip->close();
				}

				$this->delete_temp_file( $zip_file );
			}

			if ( $download_ready ) {
				exit;
			}
		};
	}

	/**
	 * @return array<string, string|bool>|WP_Error
	 */
	protected function get_plugin_source( string $plugin_file ) {
		$installed_plugins = get_plugins();

		if ( ! isset( $installed_plugins[ $plugin_file ] ) ) {
			return new WP_Error( 'plugin_not_installed', __( 'The requested plugin is not installed.', 'development-assistant' ) );
		}

		$plugins_root = realpath( WP_PLUGIN_DIR );
		$plugin_path  = realpath( WP_PLUGIN_DIR . '/' . $plugin_file );

		if (
			false === $plugins_root ||
			false === $plugin_path ||
			! $this->is_path_within( $plugin_path, $plugins_root ) ||
			! is_file( $plugin_path ) ||
			! is_readable( $plugin_path )
		) {
			return new WP_Error( 'plugin_source_invalid', __( 'The requested plugin files are missing, unreadable, or outside the plugins directory.', 'development-assistant' ) );
		}

		$plugin_dirname = dirname( $plugin_file );

		if ( '.' === $plugin_dirname ) {
			return array(
				'path'           => $plugin_path,
				'plugins_root'   => $plugins_root,
				'is_single_file' => true,
			);
		}

		$plugin_dir = realpath( WP_PLUGIN_DIR . '/' . $plugin_dirname );

		if (
			false === $plugin_dir ||
			! $this->is_path_within( $plugin_dir, $plugins_root ) ||
			! is_dir( $plugin_dir ) ||
			! is_readable( $plugin_dir ) ||
			! $this->is_path_within( $plugin_path, $plugin_dir )
		) {
			return new WP_Error( 'plugin_source_invalid', __( 'The requested plugin files are missing, unreadable, or outside the plugins directory.', 'development-assistant' ) );
		}

		return array(
			'path'           => $plugin_dir,
			'plugins_root'   => $plugins_root,
			'archive_root'   => basename( wp_normalize_path( $plugin_dirname ) ),
			'is_single_file' => false,
		);
	}

	protected function is_path_within( string $path, string $directory ): bool {
		$path      = rtrim( wp_normalize_path( $path ), '/' );
		$directory = rtrim( wp_normalize_path( $directory ), '/' );

		return 0 === strpos( $path, "$directory/" );
	}

	protected function get_download_filename( string $plugin_file ): string {
		$plugin_dirname = dirname( $plugin_file );
		$plugin_name    = '.' === $plugin_dirname ? pathinfo( $plugin_file, PATHINFO_FILENAME ) : $plugin_dirname;

		return sanitize_file_name( $plugin_name . '.zip' );
	}

	/**
	 * @return string|WP_Error
	 */
	protected function get_temp_zip_file( string $plugin_file ) {
		$zip_file = wp_tempnam( $this->get_download_filename( $plugin_file ) );

		if ( ! is_string( $zip_file ) || '' === $zip_file ) {
			return new WP_Error( 'temp_file_failed', __( 'Could not create a temporary plugin archive.', 'development-assistant' ) );
		}

		return $zip_file;
	}

	protected function delete_temp_file( string $zip_file ): void {
		if ( file_exists( $zip_file ) ) {
			unlink( $zip_file ); // phpcs:ignore
		}
	}

	/**
	 * @param array<string, string|bool> $source
	 */
	protected function add_plugin_files( ZipArchive $zip, array $source ): ?WP_Error {
		if ( true === $source['is_single_file'] ) {
			if ( ! $zip->addFile( $source['path'], basename( $source['path'] ) ) ) {
				return new WP_Error( 'archive_add_failed', __( 'Could not add the selected plugin file to the archive.', 'development-assistant' ) );
			}

			return null;
		}

		$plugin_dir = $source['path'];
		$files      = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				continue;
			}

			$file_path = $file->getRealPath();

			if (
				false === $file_path ||
				! $this->is_path_within( $file_path, $plugin_dir ) ||
				! $this->is_path_within( $file_path, $source['plugins_root'] ) ||
				! is_file( $file_path ) ||
				! is_readable( $file_path )
			) {
				return new WP_Error( 'plugin_file_invalid', __( 'A plugin file is missing, unreadable, or outside the selected plugin directory.', 'development-assistant' ) );
			}

			$relative_path = wp_normalize_path( substr( $file_path, strlen( $plugin_dir ) + 1 ) );
			$archive_path  = $source['archive_root'] . '/' . $relative_path;

			if ( ! $zip->addFile( $file_path, $archive_path ) ) {
				return new WP_Error( 'archive_add_failed', __( 'Could not add a selected plugin file to the archive.', 'development-assistant' ) );
			}
		}

		return null;
	}

	protected function add_error_notice( WP_Error $error ): void {
		$this->admin_notice->add_transient( $error->get_error_message(), 'error' );
	}
}

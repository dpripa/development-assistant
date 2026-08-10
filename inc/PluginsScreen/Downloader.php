<?php
namespace WPDevAssist\PluginsScreen;

use WPDevAssist\ActionQuery;
use WPDevAssist\AdminNotice;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
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
			$plugin_dir  = WP_PLUGIN_DIR . '/' . dirname( $plugin_file );
			$zip_file    = sys_get_temp_dir() . '/' . dirname( $plugin_file ) . '.zip';
			$zip         = new ZipArchive();

			if ( is_int( $zip->open( $zip_file, ZipArchive::CREATE ) ) ) {
				$this->admin_notice->add_transient( __( 'Failed to download plugin', 'development-assistant' ), 'error' );

				return;
			}

			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $plugin_dir ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					continue;
				}

				$file_path     = $file->getRealPath();
				$relative_path = substr( $file_path, strlen( $plugin_dir ) + 1 );

				$zip->addFile( $file_path, $relative_path );
			}

			$zip->close();

			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . basename( $zip_file ) . '"' );
			header( 'Content-Length: ' . filesize( $zip_file ) );
			flush();
			readfile( $zip_file ); // phpcs:ignore
			unlink( $zip_file ); // phpcs:ignore

			exit;
		};
	}
}

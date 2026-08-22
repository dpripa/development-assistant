<?php
namespace WPDevAssist;

use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class ExternalFileMutationManager {
	public const TARGET_WP_CONFIG = 'wp-config';
	public const TARGET_HTACCESS  = 'htaccess';

	protected const BACKUP_HEADER = "<?php exit; __halt_compiler(); ?>\n";

	/** @var array<string, bool> */
	protected array $temporary_files = array();

	/**
	 * @return string|WP_Error
	 */
	public function get_target_path( string $target ) {
		switch ( $target ) {
			case static::TARGET_WP_CONFIG:
				$root_path   = ABSPATH . 'wp-config.php';
				$parent_path = dirname( ABSPATH ) . '/wp-config.php';

				if ( file_exists( $root_path ) ) {
					return $root_path;
				}

				if ( file_exists( $parent_path ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
					return $parent_path;
				}

				return new WP_Error(
					'external_file_target_missing',
					__( 'Development Assistant could not find the active wp-config.php file. No changes were made.', 'development-assistant' )
				);

			case static::TARGET_HTACCESS:
				if ( ! function_exists( 'get_home_path' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				return get_home_path() . '.htaccess';

			default:
				return new WP_Error(
					'external_file_target_forbidden',
					__( 'Development Assistant refused an unregistered external file operation.', 'development-assistant' )
				);
		}
	}

	/**
	 * @return string|WP_Error
	 */
	public function read( string $target ) {
		$path = $this->get_target_path( $target );

		if ( $path instanceof WP_Error ) {
			return $path;
		}

		return $this->read_path( $path );
	}

	/**
	 * @param callable(string): (string|WP_Error) $mutator
	 * @param callable(string): (bool|WP_Error)   $validator
	 *
	 * @return array{changed: bool}|WP_Error
	 */
	public function mutate( string $target, callable $mutator, callable $validator ) {
		if ( ! in_array( $target, array( static::TARGET_WP_CONFIG, static::TARGET_HTACCESS ), true ) ) {
			return new WP_Error(
				'external_file_target_forbidden',
				__( 'Development Assistant refused an unregistered external file operation.', 'development-assistant' )
			);
		}

		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'development-assistant' ) ) {
			return new WP_Error(
				'external_file_modification_disallowed',
				__( 'WordPress configuration forbids file modifications. Development Assistant made no changes.', 'development-assistant' )
			);
		}

		$path = $this->get_target_path( $target );

		if ( $path instanceof WP_Error ) {
			return $path;
		}

		$current_content = $this->read_path( $path );

		if ( $current_content instanceof WP_Error ) {
			return $current_content;
		}

		try {
			$new_content = $mutator( $current_content );
		} catch ( Throwable $error ) {
			return new WP_Error(
				'external_file_mutation_failed',
				__( 'Development Assistant could not prepare the requested file change. No changes were made.', 'development-assistant' )
			);
		}

		if ( $new_content instanceof WP_Error ) {
			return $new_content;
		}

		if ( ! is_string( $new_content ) ) {
			return new WP_Error(
				'external_file_mutation_failed',
				__( 'Development Assistant could not prepare a complete file replacement. No changes were made.', 'development-assistant' )
			);
		}

		if ( $new_content === $current_content ) {
			return array( 'changed' => false );
		}

		try {
			$validation = $validator( $new_content );
		} catch ( Throwable $error ) {
			return new WP_Error(
				'external_file_validation_failed',
				__( 'Development Assistant could not validate the requested file change. No changes were made.', 'development-assistant' )
			);
		}

		if ( $validation instanceof WP_Error ) {
			return $validation;
		}

		if ( true !== $validation ) {
			return new WP_Error(
				'external_file_validation_failed',
				__( 'Development Assistant rejected an invalid file change. No changes were made.', 'development-assistant' )
			);
		}

		if ( ! is_writable( $path ) || ! is_writable( dirname( $path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return new WP_Error(
				'external_file_not_writable',
				__( 'The server does not allow Development Assistant to safely update this file. No changes were made. Check filesystem permissions or update the configuration manually.', 'development-assistant' )
			);
		}

		$recovery_directory = $this->ensure_recovery_directory();

		if ( $recovery_directory instanceof WP_Error ) {
			return $recovery_directory;
		}

		$lock_path = $recovery_directory . '/' . $target . '.lock';
		$lock      = @fopen( $lock_path, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $lock || ! flock( $lock, LOCK_EX ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}

			return new WP_Error(
				'external_file_lock_failed',
				__( 'Development Assistant could not lock the configuration file. No changes were made. Try again.', 'development-assistant' )
			);
		}

		$transaction_backup = '';
		$target_replaced    = false;

		try {
			$locked_content = $this->read_path( $path );

			if ( $locked_content instanceof WP_Error || ! hash_equals( hash( 'sha256', $current_content ), hash( 'sha256', $locked_content ) ) ) {
				return new WP_Error(
					'external_file_changed_concurrently',
					__( 'The configuration file changed while Development Assistant was preparing the update. No changes were made. Review the file and try again.', 'development-assistant' )
				);
			}

			$baseline = $this->create_baseline_backup( $recovery_directory, $target, $current_content );

			if ( $baseline instanceof WP_Error ) {
				return $baseline;
			}

			$transaction_backup = $recovery_directory . '/' . $target . '-transaction-' . bin2hex( random_bytes( 12 ) ) . '.php';
			$backup_result      = $this->write_backup( $transaction_backup, $current_content );

			if ( $backup_result instanceof WP_Error ) {
				return $backup_result;
			}

			$write_result = $this->replace_path_atomically( $path, $new_content );

			if ( $write_result instanceof WP_Error ) {
				return $write_result;
			}

			$target_replaced = true;

			$written_content = $this->read_path( $path );
			$written_valid   = $written_content instanceof WP_Error ? $written_content : $validator( $written_content );

			if (
				$written_content instanceof WP_Error ||
				! hash_equals( hash( 'sha256', $new_content ), hash( 'sha256', $written_content ) ) ||
				true !== $written_valid
			) {
				$rollback = $this->replace_path_atomically( $path, $current_content );

				if ( $rollback instanceof WP_Error ) {
					return new WP_Error(
						'external_file_rollback_failed',
						__( 'The configuration update could not be verified and automatic recovery failed. Use the protected Development Assistant recovery backup to restore the file manually.', 'development-assistant' )
					);
				}

				$target_replaced = false;

				return new WP_Error(
					'external_file_verification_failed',
					__( 'The configuration update could not be verified, so Development Assistant restored the original file. No requested change remains.', 'development-assistant' )
				);
			}

			@unlink( $transaction_backup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			$transaction_backup = '';

			return array( 'changed' => true );
		} catch ( Throwable $error ) {
			if ( $target_replaced ) {
				$rollback = $this->replace_path_atomically( $path, $current_content );

				if ( $rollback instanceof WP_Error ) {
					return new WP_Error(
						'external_file_rollback_failed',
						__( 'The configuration update failed unexpectedly and automatic recovery also failed. Use the protected Development Assistant recovery backup to restore the file manually.', 'development-assistant' )
					);
				}
			}

			return new WP_Error(
				'external_file_mutation_failed',
				__( 'Development Assistant could not complete the configuration update. The original file was preserved or restored.', 'development-assistant' )
			);
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * @return WP_Error|null
	 */
	public function delete_debug_log(): ?WP_Error {
		if ( function_exists( 'wp_is_file_mod_allowed' ) && ! wp_is_file_mod_allowed( 'development-assistant' ) ) {
			return new WP_Error(
				'external_file_modification_disallowed',
				__( 'WordPress configuration forbids file modifications. The debug log was not deleted.', 'development-assistant' )
			);
		}

		$path = WP_CONTENT_DIR . '/debug.log';

		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'debug_log_missing', __( 'The debug log could not be deleted because it no longer exists.', 'development-assistant' ) );
		}

		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'debug_log_unreadable', __( 'The debug log could not be deleted because it is not a readable regular file.', 'development-assistant' ) );
		}

		if ( ! @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error( 'debug_log_delete_failed', __( 'The debug log could not be deleted. Check filesystem permissions and try again.', 'development-assistant' ) );
		}

		return null;
	}

	/**
	 * @return string|WP_Error
	 */
	public function create_temporary_archive( string $filename ) {
		$path = wp_tempnam( $filename );

		if ( ! is_string( $path ) || '' === $path || is_link( $path ) || ! is_file( $path ) || ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return new WP_Error( 'temp_file_failed', __( 'Could not create a safe temporary plugin archive.', 'development-assistant' ) );
		}

		$this->temporary_files[ wp_normalize_path( $path ) ] = true;

		return $path;
	}

	public function delete_temporary_archive( string $path ): bool {
		$normalized_path = wp_normalize_path( $path );

		if ( empty( $this->temporary_files[ $normalized_path ] ) ) {
			return false;
		}

		unset( $this->temporary_files[ $normalized_path ] );

		return ! file_exists( $path ) || @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * @return string|WP_Error
	 */
	protected function read_path( string $path ) {
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error(
				'external_file_unreadable',
				__( 'Development Assistant cannot safely read the configuration file. No changes were made.', 'development-assistant' )
			);
		}

		$content = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $content ) {
			return new WP_Error(
				'external_file_unreadable',
				__( 'Development Assistant cannot safely read the configuration file. No changes were made.', 'development-assistant' )
			);
		}

		return $content;
	}

	/**
	 * @return string|WP_Error
	 */
	protected function ensure_recovery_directory() {
		$directory = WP_CONTENT_DIR . '/.development-assistant-recovery';

		if ( is_link( $directory ) || ( file_exists( $directory ) && ! is_dir( $directory ) ) ) {
			return new WP_Error(
				'external_file_backup_unavailable',
				__( 'Development Assistant recovery storage is not a safe directory. No configuration changes were made.', 'development-assistant' )
			);
		}

		if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			return new WP_Error(
				'external_file_backup_unavailable',
				__( 'Development Assistant could not create protected recovery storage. No configuration changes were made.', 'development-assistant' )
			);
		}

		@chmod( $directory, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		if ( ! is_writable( $directory ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return new WP_Error(
				'external_file_backup_unavailable',
				__( 'Development Assistant recovery storage is not writable. No configuration changes were made.', 'development-assistant' )
			);
		}

		$guards = array(
			'index.php' => "<?php\nexit;\n",
			'.htaccess' => "Require all denied\nDeny from all\n",
		);

		foreach ( $guards as $filename => $contents ) {
			$guard_path = $directory . '/' . $filename;

			if ( is_link( $guard_path ) || ( file_exists( $guard_path ) && ! is_file( $guard_path ) ) ) {
				return new WP_Error(
					'external_file_backup_unavailable',
					__( 'Development Assistant recovery protection files are invalid. No configuration changes were made.', 'development-assistant' )
				);
			}

			if ( ! file_exists( $guard_path ) ) {
				$result = $this->write_complete_file( $guard_path, $contents, 0600, true );

				if ( $result instanceof WP_Error ) {
					return $result;
				}
			}
		}

		return $directory;
	}

	/**
	 * @return bool|WP_Error
	 */
	protected function create_baseline_backup( string $directory, string $target, string $content ) {
		$path = $directory . '/' . $target . '-baseline.php';

		if ( file_exists( $path ) ) {
			if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
				return new WP_Error(
					'external_file_backup_invalid',
					__( 'The protected Development Assistant baseline backup is invalid. No configuration changes were made.', 'development-assistant' )
				);
			}

			$payload = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( false === $payload || 0 !== strpos( $payload, static::BACKUP_HEADER ) || false === base64_decode( substr( $payload, strlen( static::BACKUP_HEADER ) ), true ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Verifies the plugin's protected recovery payload.
				return new WP_Error(
					'external_file_backup_invalid',
					__( 'The protected Development Assistant baseline backup is corrupted. No configuration changes were made.', 'development-assistant' )
				);
			}

			return true;
		}

		return $this->write_backup( $path, $content, true );
	}

	/**
	 * @return bool|WP_Error
	 */
	protected function write_backup( string $path, string $content, bool $exclusive = false ) {
		$payload = static::BACKUP_HEADER . base64_encode( $content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Backup payload must not execute or render when requested.
		$result  = $this->write_complete_file( $path, $payload, 0600, $exclusive );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$stored_payload = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $stored_payload || $payload !== $stored_payload ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

			return new WP_Error(
				'external_file_backup_failed',
				__( 'Development Assistant could not verify the recovery backup. No configuration changes were made.', 'development-assistant' )
			);
		}

		return true;
	}

	/**
	 * @return bool|WP_Error
	 */
	protected function write_complete_file( string $path, string $content, int $permissions, bool $exclusive = false ) {
		$file = @fopen( $path, $exclusive ? 'x+b' : 'wb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $file ) {
			return new WP_Error(
				'external_file_write_failed',
				__( 'Development Assistant could not write a required protected file. No configuration changes were made.', 'development-assistant' )
			);
		}

		$length  = strlen( $content );
		$written = 0;

		while ( $written < $length ) {
			$bytes = fwrite( $file, substr( $content, $written ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			if ( false === $bytes || 0 === $bytes ) {
				fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

				return new WP_Error(
					'external_file_write_failed',
					__( 'Development Assistant could not complete a protected file write. No configuration changes were made.', 'development-assistant' )
				);
			}

			$written += $bytes;
		}

		$flushed = fflush( $file );
		fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$mode_set = @chmod( $path, $permissions ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		$mode     = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fileperms

		if ( ! $flushed || ! $mode_set || false === $mode || ( $mode & 0777 ) !== $permissions ) {
			return new WP_Error(
				'external_file_write_failed',
				__( 'Development Assistant could not safely finish a protected file write. No configuration changes were made.', 'development-assistant' )
			);
		}

		return true;
	}

	/**
	 * @return bool|WP_Error
	 */
	protected function replace_path_atomically( string $path, string $content ) {
		$permissions = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
		$owner       = @fileowner( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fileowner
		$group       = @filegroup( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_filegroup
		$temp_path   = @tempnam( dirname( $path ), '.development-assistant-' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_tempnam

		if ( false === $temp_path ) {
			return new WP_Error(
				'external_file_temp_failed',
				__( 'Development Assistant could not create a temporary configuration file. No changes were made.', 'development-assistant' )
			);
		}

		try {
			$result = $this->write_complete_file( $temp_path, $content, false === $permissions ? 0600 : $permissions & 0777 );

			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$temp_owner = @fileowner( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fileowner
			$temp_group = @filegroup( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_filegroup

			if ( false !== $owner && $owner !== $temp_owner && ! @chown( $temp_path, $owner ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chown
				return new WP_Error(
					'external_file_owner_failed',
					__( 'Development Assistant could not preserve the configuration file owner. The original file was left unchanged.', 'development-assistant' )
				);
			}

			if ( false !== $group && $group !== $temp_group && ! @chgrp( $temp_path, $group ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp
				return new WP_Error(
					'external_file_group_failed',
					__( 'Development Assistant could not preserve the configuration file group. The original file was left unchanged.', 'development-assistant' )
				);
			}

			if ( ! @rename( $temp_path, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				return new WP_Error(
					'external_file_replace_failed',
					__( 'Development Assistant could not atomically replace the configuration file. The original file was left unchanged.', 'development-assistant' )
				);
			}

			$temp_path = '';

			return true;
		} finally {
			if ( '' !== $temp_path && file_exists( $temp_path ) ) {
				@unlink( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ExternalFileMutationBoundaryTest extends TestCase {
	public function test_runtime_filesystem_mutations_use_the_registered_boundary(): void {
		$root                  = dirname( __DIR__, 2 );
		$allowed_mutation_file = realpath( $root . '/inc/ExternalFileMutationManager.php' );
		$allowed_archive_file  = realpath( $root . '/inc/PluginsScreen/Downloader.php' );
		$mutating_functions    = array(
			'chgrp',
			'chmod',
			'chown',
			'copy',
			'file_put_contents',
			'ftruncate',
			'fwrite',
			'mkdir',
			'move_uploaded_file',
			'rename',
			'rmdir',
			'touch',
			'unlink',
			'wp_filesystem',
		);
		$violations            = array();
		$iterator              = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/inc', RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$path                 = $file->getRealPath();
			$content              = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$tokens               = token_get_all( $content );
			$previous_significant = null;

			foreach ( $tokens as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}

				if ( is_array( $token ) && T_STRING === $token[0] ) {
					$name = strtolower( $token[1] );

					if (
						in_array( $name, $mutating_functions, true ) &&
						$path !== $allowed_mutation_file &&
						( ! is_array( $previous_significant ) || ! in_array( $previous_significant[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) )
					) {
						$violations[] = sprintf( '%s:%d direct %s() call', substr( $path, strlen( $root ) + 1 ), $token[2], $token[1] );
					}

					if (
						'ziparchive' === $name &&
						is_array( $previous_significant ) &&
						T_NEW === $previous_significant[0] &&
						$path !== $allowed_archive_file
					) {
						$violations[] = sprintf(
							'%s:%d ZipArchive creation outside the registered temporary-archive policy',
							substr( $path, strlen( $root ) + 1 ),
							$token[2]
						);
					}
				}

				$previous_significant = $token;
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"Runtime filesystem mutations must use ExternalFileMutationManager:\n" . implode( "\n", $violations )
		);
	}
}

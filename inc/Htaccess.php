<?php
namespace WPDevAssist;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Htaccess {
	protected ExternalFileMutationManager $file_mutations;

	public function __construct( ExternalFileMutationManager $file_mutations ) {
		$this->file_mutations = $file_mutations;
	}

	public function exists(): bool {
		$path = $this->file_mutations->get_target_path( ExternalFileMutationManager::TARGET_HTACCESS );

		return is_string( $path ) && is_file( $path );
	}

	/**
	 * @return bool|WP_Error
	 */
	public function replace( string $marker, string $content ) {
		if ( ! $this->exists() ) {
			return new WP_Error(
				'htaccess_missing',
				__( 'Development Assistant could not find the active .htaccess file. No changes were made.', 'development-assistant' )
			);
		}

		$result = $this->file_mutations->mutate(
			ExternalFileMutationManager::TARGET_HTACCESS,
			function ( string $file_content ) use ( $marker, $content ) {
				return $this->replace_marker( $file_content, $marker, $content );
			},
			function ( string $file_content ) use ( $marker, $content ) {
				$blocks = $this->find_marker_blocks( $file_content, $marker );

				if ( $blocks instanceof WP_Error ) {
					return $blocks;
				}

				return '' === $content ? 0 === count( $blocks ) : 1 === count( $blocks );
			}
		);

		return $result instanceof WP_Error ? $result : true;
	}

	/**
	 * @return bool|WP_Error
	 */
	public function remove( string $marker ) {
		return $this->replace( $marker, '' );
	}

	/**
	 * @return string|WP_Error
	 */
	protected function replace_marker( string $file_content, string $marker, string $content ) {
		$blocks = $this->find_marker_blocks( $file_content, $marker );

		if ( $blocks instanceof WP_Error ) {
			return $blocks;
		}

		$line_ending = false !== strpos( $file_content, "\r\n" ) ? "\r\n" : "\n";
		$replacement = '';

		if ( '' !== $content ) {
			$replacement = '# BEGIN ' . $marker . $line_ending . str_replace( "\n", $line_ending, $content ) . $line_ending . '# END ' . $marker;
		}

		if ( 1 === count( $blocks ) ) {
			$block = $blocks[0];

			return substr( $file_content, 0, $block['start'] ) . $replacement . substr( $file_content, $block['end'] );
		}

		if ( '' === $replacement ) {
			return $file_content;
		}

		$separator = '' === $file_content || preg_match( '/\R$/', $file_content ) ? '' : $line_ending;

		return $file_content . $separator . $replacement . $line_ending;
	}

	/**
	 * @return array<int, array{start: int, end: int}>|WP_Error
	 */
	protected function find_marker_blocks( string $file_content, string $marker ) {
		$quoted_marker = preg_quote( $marker, '/' );
		$start_count   = preg_match_all( '/^# BEGIN ' . $quoted_marker . '\h*$/m', $file_content );
		$end_count     = preg_match_all( '/^# END ' . $quoted_marker . '\h*$/m', $file_content );

		if ( false === $start_count || false === $end_count || $start_count !== $end_count || 1 < $start_count ) {
			return new WP_Error(
				'htaccess_marker_invalid',
				__( 'The Development Assistant .htaccess markers are duplicated or incomplete. No changes were made.', 'development-assistant' )
			);
		}

		if ( 0 === $start_count ) {
			return array();
		}

		$pattern = '/^# BEGIN ' . $quoted_marker . '\h*\R.*?^# END ' . $quoted_marker . '\h*(?:\R)?/ms';

		if ( 1 !== preg_match( $pattern, $file_content, $match, PREG_OFFSET_CAPTURE ) ) {
			return new WP_Error(
				'htaccess_marker_invalid',
				__( 'The Development Assistant .htaccess marker block is malformed. No changes were made.', 'development-assistant' )
			);
		}

		return array(
			array(
				'start' => $match[0][1],
				'end'   => $match[0][1] + strlen( $match[0][0] ),
			),
		);
	}
}

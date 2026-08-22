<?php
namespace WPDevAssist;

use ParseError;
use WP_Error;

defined( 'ABSPATH' ) || exit;

class DebugConfigEditor {
	protected const NAMES = array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY' );

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public function inspect( string $content, string $name ) {
		if ( ! in_array( $name, static::NAMES, true ) ) {
			return new WP_Error( 'debug_constant_forbidden', __( 'Development Assistant refused to manage an unsupported configuration constant.', 'development-assistant' ) );
		}

		try {
			$tokens = token_get_all( $content, TOKEN_PARSE );
		} catch ( ParseError $error ) {
			return new WP_Error( 'debug_config_invalid_php', __( 'The wp-config.php file contains invalid PHP syntax. Development Assistant made no changes.', 'development-assistant' ) );
		}

		$definitions = $this->find_definitions( $tokens, $name );

		if ( 1 < count( $definitions ) ) {
			return new WP_Error( 'debug_constant_duplicate', sprintf( __( 'Multiple definitions of %s were found. Development Assistant made no changes.', 'development-assistant' ), $name ) );
		}

		if ( empty( $definitions ) ) {
			return array( 'type' => 'missing' );
		}

		$definition = $definitions[0];
		$expression = trim( $definition['expression'] );

		if ( 0 === strcasecmp( $expression, 'true' ) || 0 === strcasecmp( $expression, 'false' ) ) {
			$definition['type']  = 'boolean';
			$definition['value'] = 0 === strcasecmp( $expression, 'true' );

			return $definition;
		}

		$string_value = $this->decode_string_literal( $expression );

		if ( 'WP_DEBUG_LOG' === $name && is_string( $string_value ) ) {
			$definition['type']  = 'string';
			$definition['value'] = $string_value;

			return $definition;
		}

		return new WP_Error(
			'debug_constant_dynamic',
			sprintf( __( '%s uses an unsupported dynamic or non-boolean expression. Development Assistant made no changes.', 'development-assistant' ), $name )
		);
	}

	/**
	 * @param bool|string $value
	 *
	 * @return string|WP_Error
	 */
	public function set( string $content, string $name, $value ) {
		$definition = $this->inspect( $content, $name );

		if ( $definition instanceof WP_Error ) {
			return $definition;
		}

		$replacement = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value ) . "'";

		if ( 'missing' === $definition['type'] ) {
			return $this->insert_definition( $content, $name, $replacement );
		}

		return substr( $content, 0, $definition['value_start'] ) . $replacement . substr( $content, $definition['value_end'] );
	}

	/**
	 * @return string|WP_Error
	 */
	public function remove( string $content, string $name ) {
		$definition = $this->inspect( $content, $name );

		if ( $definition instanceof WP_Error ) {
			return $definition;
		}

		if ( 'missing' === $definition['type'] ) {
			return $content;
		}

		$declaration_end = $definition['declaration_end'];

		if ( "\r\n" === substr( $content, $declaration_end, 2 ) ) {
			$declaration_end += 2;
		} elseif ( "\n" === substr( $content, $declaration_end, 1 ) ) {
			++$declaration_end;
		}

		return substr( $content, 0, $definition['declaration_start'] ) . substr( $content, $declaration_end );
	}

	/**
	 * @param array<int, array<int, int|string>|string> $tokens
	 *
	 * @return array<int, array<string, int|string>>
	 */
	protected function find_definitions( array $tokens, string $name ): array {
		$definitions = array();
		$offsets     = array();
		$offset      = 0;

		foreach ( $tokens as $index => $token ) {
			$offsets[ $index ] = $offset;
			$offset           += strlen( $this->token_text( $token ) );
		}

		foreach ( $tokens as $index => $token ) {
			if ( is_array( $token ) && T_STRING === $token[0] && 0 === strcasecmp( $token[1], 'define' ) ) {
				$definition = $this->parse_define( $tokens, $offsets, $index, $name );

				if ( is_array( $definition ) ) {
					$definitions[] = $definition;
				}
			} elseif ( is_array( $token ) && T_CONST === $token[0] ) {
				$definition = $this->parse_const( $tokens, $offsets, $index, $name );

				if ( is_array( $definition ) ) {
					$definitions[] = $definition;
				}
			}
		}

		return $definitions;
	}

	/**
	 * @param array<int, array<int, int|string>|string> $tokens
	 * @param array<int, int>                           $offsets
	 *
	 * @return array<string, int|string>|null
	 */
	protected function parse_define( array $tokens, array $offsets, int $index, string $name ): ?array {
		$open_index = $this->next_significant_index( $tokens, $index + 1 );

		if ( null === $open_index || '(' !== $this->token_text( $tokens[ $open_index ] ) ) {
			return null;
		}

		$name_index = $this->next_significant_index( $tokens, $open_index + 1 );

		if ( null === $name_index || ! is_array( $tokens[ $name_index ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $name_index ][0] ) {
			return null;
		}

		if ( $name !== $this->decode_string_literal( $tokens[ $name_index ][1] ) ) {
			return null;
		}

		$comma_index = $this->next_significant_index( $tokens, $name_index + 1 );

		if ( null === $comma_index || ',' !== $this->token_text( $tokens[ $comma_index ] ) ) {
			return null;
		}

		$value_start_index = $this->next_significant_index( $tokens, $comma_index + 1 );

		if ( null === $value_start_index ) {
			return null;
		}

		$depth     = 1;
		$end_index = null;

		$token_count = count( $tokens );

		for ( $cursor = $value_start_index; $cursor < $token_count; $cursor++ ) {
			$text = $this->token_text( $tokens[ $cursor ] );

			if ( '(' === $text ) {
				++$depth;
			} elseif ( ')' === $text ) {
				--$depth;

				if ( 0 === $depth ) {
					$end_index = $cursor;
					break;
				}
			}
		}

		if ( null === $end_index ) {
			return null;
		}

		$value_end_index = $this->previous_significant_index( $tokens, $end_index - 1 );

		if ( null === $value_end_index ) {
			return null;
		}

		$declaration_end_index = $this->next_significant_index( $tokens, $end_index + 1 );
		$declaration_end       = $offsets[ $end_index ] + 1;

		if ( null !== $declaration_end_index && ';' === $this->token_text( $tokens[ $declaration_end_index ] ) ) {
			$declaration_end = $offsets[ $declaration_end_index ] + 1;
		}

		$value_start = $offsets[ $value_start_index ];
		$value_end   = $offsets[ $value_end_index ] + strlen( $this->token_text( $tokens[ $value_end_index ] ) );

		return array(
			'declaration_start' => $offsets[ $index ],
			'declaration_end'   => $declaration_end,
			'value_start'       => $value_start,
			'value_end'         => $value_end,
			'expression'        => substr( $this->tokens_text( $tokens ), $value_start, $value_end - $value_start ),
		);
	}

	/**
	 * @param array<int, array<int, int|string>|string> $tokens
	 * @param array<int, int>                           $offsets
	 *
	 * @return array<string, int|string>|null
	 */
	protected function parse_const( array $tokens, array $offsets, int $index, string $name ): ?array {
		$name_index = $this->next_significant_index( $tokens, $index + 1 );

		if ( null === $name_index || ! is_array( $tokens[ $name_index ] ) || T_STRING !== $tokens[ $name_index ][0] || $name !== $tokens[ $name_index ][1] ) {
			return null;
		}

		$equals_index = $this->next_significant_index( $tokens, $name_index + 1 );

		if ( null === $equals_index || '=' !== $this->token_text( $tokens[ $equals_index ] ) ) {
			return null;
		}

		$value_start_index = $this->next_significant_index( $tokens, $equals_index + 1 );
		$end_index         = $value_start_index;

		$token_count = count( $tokens );

		while ( null !== $end_index && $end_index < $token_count && ';' !== $this->token_text( $tokens[ $end_index ] ) ) {
			++$end_index;
		}

		if ( null === $value_start_index || null === $end_index || $end_index >= $token_count ) {
			return null;
		}

		$value_end_index = $this->previous_significant_index( $tokens, $end_index - 1 );

		if ( null === $value_end_index ) {
			return null;
		}

		$value_start = $offsets[ $value_start_index ];
		$value_end   = $offsets[ $value_end_index ] + strlen( $this->token_text( $tokens[ $value_end_index ] ) );

		return array(
			'declaration_start' => $offsets[ $index ],
			'declaration_end'   => $offsets[ $end_index ] + 1,
			'value_start'       => $value_start,
			'value_end'         => $value_end,
			'expression'        => substr( $this->tokens_text( $tokens ), $value_start, $value_end - $value_start ),
		);
	}

	/**
	 * @param array<int, array<int, int|string>|string> $tokens
	 */
	protected function next_significant_index( array $tokens, int $index ): ?int {
		$token_count = count( $tokens );

		for ( $cursor = $index; $cursor < $token_count; $cursor++ ) {
			if ( ! $this->is_ignorable( $tokens[ $cursor ] ) ) {
				return $cursor;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<int, int|string>|string> $tokens
	 */
	protected function previous_significant_index( array $tokens, int $index ): ?int {
		for ( $cursor = $index; 0 <= $cursor; $cursor-- ) {
			if ( ! $this->is_ignorable( $tokens[ $cursor ] ) ) {
				return $cursor;
			}
		}

		return null;
	}

	/** @param array<int, int|string>|string $token */
	protected function is_ignorable( $token ): bool {
		return is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
	}

	/** @param array<int, int|string>|string $token */
	protected function token_text( $token ): string {
		return is_array( $token ) ? $token[1] : $token;
	}

	/** @param array<int, array<int, int|string>|string> $tokens */
	protected function tokens_text( array $tokens ): string {
		return implode(
			'',
			array_map(
				function ( $token ): string {
					return $this->token_text( $token );
				},
				$tokens
			)
		);
	}

	/**
	 * @return string|false
	 */
	protected function decode_string_literal( string $literal ) {
		if ( 2 > strlen( $literal ) || ! in_array( $literal[0], array( "'", '"' ), true ) || substr( $literal, -1 ) !== $literal[0] ) {
			return false;
		}

		$value = substr( $literal, 1, -1 );

		if ( "'" === $literal[0] ) {
			return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $value );
		}

		return stripcslashes( $value );
	}

	/**
	 * @return string|WP_Error
	 */
	protected function insert_definition( string $content, string $name, string $value ) {
		try {
			$tokens = token_get_all( $content, TOKEN_PARSE );
		} catch ( ParseError $error ) {
			return new WP_Error( 'debug_config_invalid_php', __( 'The wp-config.php file contains invalid PHP syntax. Development Assistant made no changes.', 'development-assistant' ) );
		}

		$anchor = false;
		$offset = 0;

		foreach ( $tokens as $token ) {
			$text = $this->token_text( $token );

			if ( is_array( $token ) && T_VARIABLE === $token[0] && '$table_prefix' === $token[1] ) {
				$anchor = $offset;
				break;
			}

			$offset += strlen( $text );
		}

		if ( false === $anchor ) {
			return new WP_Error(
				'debug_config_anchor_missing',
				__( 'Development Assistant could not find a safe insertion point in wp-config.php. No changes were made.', 'development-assistant' )
			);
		}

		$line_ending = false !== strpos( $content, "\r\n" ) ? "\r\n" : "\n";
		$definition  = "define( '" . $name . "', " . $value . ' );' . $line_ending;

		return substr( $content, 0, $anchor ) . $definition . substr( $content, $anchor );
	}
}

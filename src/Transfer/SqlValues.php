<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Transfer;

use Closure;

/**
 * Rewrites the quoted string values inside one SQL statement.
 *
 * Each 'string' (or "string") is decoded exactly as MySQL reads it, handed to
 * a callback, and written back escaped the way mysqldump writes it. So the
 * callback sees the real stored value: a serialized array with its true byte
 * lengths, JSON with its own escaping. `Identifiers`, numbers, NULL and 0x
 * hex literals are copied as they are, and so are _binary '...' strings,
 * which hold bytes, not text.
 */
final class SqlValues {

	/**
	 * @param Closure(string): ?string $replace Returns the new value, or null to keep the literal as it is.
	 */
	public static function rewrite( string $sql, Closure $replace ): string {
		$output = '';
		$pos    = 0;
		$length = strlen( $sql );

		while ( $pos < $length ) {
			$plain   = strcspn( $sql, "'\"`", $pos );
			$output .= substr( $sql, $pos, $plain );
			$pos    += $plain;

			if ( $pos >= $length ) {
				break;
			}

			$quote = $sql[ $pos ];
			$end   = self::closing( $sql, $pos + 1, $quote );

			// An identifier, an unterminated string, or bytes: leave them be.
			if ( '`' === $quote || $end >= $length || 1 === preg_match( '/_binary\s*$/i', substr( $output, -16 ) ) ) {
				$output .= substr( $sql, $pos, $end - $pos + 1 );
				$pos     = $end + 1;
				continue;
			}

			$literal = substr( $sql, $pos, $end - $pos + 1 );
			$value   = $replace( self::unescape( substr( $sql, $pos + 1, $end - $pos - 1 ), $quote ) );

			$output .= null === $value ? $literal : "'" . self::escape( $value ) . "'";
			$pos     = $end + 1;
		}

		return $output;
	}

	/**
	 * Escapes a value for a single-quoted SQL string, as mysqldump does.
	 */
	public static function escape( string $value ): string {
		return strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"\x1a" => '\\Z',
				"'"    => "\\'",
				'"'    => '\\"',
			)
		);
	}

	/**
	 * @return int Position of the closing quote, or the length of $sql if there is none.
	 */
	private static function closing( string $sql, int $pos, string $quote ): int {
		$stops  = '`' === $quote ? '`' : $quote . '\\';
		$length = strlen( $sql );

		while ( true ) {
			$pos += strcspn( $sql, $stops, $pos );

			if ( $pos >= $length ) {
				return $length;
			}

			if ( '\\' === $sql[ $pos ] ) {
				$pos += 2;
				continue;
			}

			// A doubled quote is an escaped quote, not the end.
			if ( ( $sql[ $pos + 1 ] ?? '' ) === $quote ) {
				$pos += 2;
				continue;
			}

			return $pos;
		}
	}

	/**
	 * Decodes the inside of a quoted string the way MySQL does.
	 */
	private static function unescape( string $text, string $quote ): string {
		return (string) preg_replace_callback(
			'/\\\\(.)|' . preg_quote( $quote . $quote, '/' ) . '/s',
			fn( array $escape ): string => ! isset( $escape[1] ) ? $quote : match ( $escape[1] ) {
				'0'     => "\0",
				'b'     => "\x08",
				'n'     => "\n",
				'r'     => "\r",
				't'     => "\t",
				'Z'     => "\x1a",
				// MySQL keeps the backslash in \% and \_, which only mean something in LIKE patterns.
				'%'     => '\\%',
				'_'     => '\\_',
				default => $escape[1],
			},
			$text
		);
	}
}

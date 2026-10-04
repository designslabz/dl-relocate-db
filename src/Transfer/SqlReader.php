<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Transfer;

use RuntimeException;

/**
 * Reads an SQL file one statement at a time, from any byte offset.
 *
 * Understands what mysqldump, phpMyAdmin and this plugin write: quoted
 * strings with backslash or doubled-quote escapes, backtick identifiers,
 * "-- ", "#" and block comments, MySQL's executable /*! ... *\/ comments,
 * and DELIMITER lines around triggers and procedures. Only a chunk of the
 * file is held in memory, plus the statement being read.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 */
final class SqlReader {

	private const CHUNK = 1048576;

	/** @var resource */
	private $handle;

	/** Unread text, starting at $offset in the file. */
	private string $buffer = '';

	private bool $eof = false;

	/**
	 * @param int    $offset    Byte offset to start at: the end of the last statement read.
	 * @param string $delimiter Statement delimiter in effect at that offset.
	 * @throws RuntimeException When the file cannot be opened.
	 */
	public function __construct( string $path, private int $offset = 0, private string $delimiter = ';' ) {
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Could not open %s.', $path ) );
		}

		fseek( $handle, $offset );
		$this->handle = $handle;
	}

	public function __destruct() {
		fclose( $this->handle );
	}

	/**
	 * Where the next statement starts: save it to carry on later.
	 */
	public function offset(): int {
		return $this->offset;
	}

	public function delimiter(): string {
		return $this->delimiter;
	}

	/**
	 * @return string|null The next statement without its delimiter, or null at the end of the file.
	 */
	public function next(): ?string {
		$pos     = 0;
		$content = false;

		while ( true ) {
			if ( ! $this->ensure( $pos + 1 ) ) {
				// End of file: anything left is a last statement without a delimiter.
				$statement = $content ? trim( $this->buffer ) : null;
				$this->consume( strlen( $this->buffer ) );
				return $statement;
			}

			$char = $this->buffer[ $pos ];

			// Before a statement starts, whitespace and comments are dropped, so
			// statements begin with their first keyword.
			if ( ! $content && '' === trim( $char ) ) {
				$this->consume( $pos + strspn( $this->buffer, " \t\r\n\f\v", $pos ) );
				$pos = 0;
				continue;
			}

			if ( ! $content && ( 'D' === $char || 'd' === $char ) && $this->delimiter_line() ) {
				$pos = 0;
				continue;
			}

			if ( '#' === $char || ( '-' === $char && $this->starts_line_comment( $pos ) ) ) {
				$pos = $this->skip_past( "\n", $pos );
				if ( ! $content ) {
					$this->consume( $pos );
					$pos = 0;
				}
				continue;
			}

			if ( '/' === $char && $this->ensure( $pos + 2 ) && '*' === $this->buffer[ $pos + 1 ] ) {
				$executable = $this->ensure( $pos + 3 ) && '!' === $this->buffer[ $pos + 2 ];
				$pos        = $this->skip_past( '*/', $pos + 2 );

				if ( $executable ) {
					$content = true;
				} elseif ( ! $content ) {
					$this->consume( $pos );
					$pos = 0;
				}
				continue;
			}

			if ( "'" === $char || '"' === $char || '`' === $char ) {
				$pos     = $this->skip_quoted( $char, $pos + 1 );
				$content = true;
				continue;
			}

			if ( $char === $this->delimiter[0] && $this->at_delimiter( $pos ) ) {
				$statement = rtrim( substr( $this->buffer, 0, $pos ) );
				$this->consume( $pos + strlen( $this->delimiter ) );
				return $statement;
			}

			$content = true;

			// Skip ordinary characters in one go, up to the next one that could matter.
			$pos += max( 1, strcspn( $this->buffer, "'\"`#-/" . $this->delimiter[0], $pos + 1 ) + 1 );
		}
	}

	/**
	 * Handles a "DELIMITER $$" line at the start of a statement.
	 */
	private function delimiter_line(): bool {
		$this->ensure_line( 0 );

		if ( 1 !== preg_match( '/^DELIMITER[ \t]+(\S+)[^\n]*(?:\n|$)/i', $this->buffer, $match ) ) {
			return false;
		}

		$this->delimiter = $match[1];
		$this->consume( strlen( $match[0] ) );

		return true;
	}

	/**
	 * MySQL needs whitespace (or the end of the line) after "--" for a comment.
	 */
	private function starts_line_comment( int $pos ): bool {
		if ( ! $this->ensure( $pos + 2 ) || '-' !== $this->buffer[ $pos + 1 ] ) {
			return false;
		}

		return ! $this->ensure( $pos + 3 ) || '' === trim( $this->buffer[ $pos + 2 ] );
	}

	private function at_delimiter( int $pos ): bool {
		$length = strlen( $this->delimiter );

		return $this->ensure( $pos + $length ) && substr( $this->buffer, $pos, $length ) === $this->delimiter;
	}

	/**
	 * @return int Position just after the closing quote, or the end of the buffer if there is none.
	 */
	private function skip_quoted( string $quote, int $pos ): int {
		// Backslash escapes apply inside strings, not inside `identifiers`.
		$stops = '`' === $quote ? '`' : $quote . '\\';

		while ( true ) {
			$pos += strcspn( $this->buffer, $stops, $pos );

			if ( $pos >= strlen( $this->buffer ) ) {
				if ( ! $this->fill() ) {
					return $pos;
				}
				continue;
			}

			if ( '\\' === $this->buffer[ $pos ] ) {
				$this->ensure( $pos + 2 );
				$pos += 2;
				continue;
			}

			// A doubled quote is an escaped quote, not the end.
			if ( $this->ensure( $pos + 2 ) && $quote === $this->buffer[ $pos + 1 ] ) {
				$pos += 2;
				continue;
			}

			return $pos + 1;
		}
	}

	/**
	 * @return int Position just after $needle, or the end of the buffer if there is none.
	 */
	private function skip_past( string $needle, int $pos ): int {
		$found = strpos( $this->buffer, $needle, $pos );

		while ( false === $found ) {
			if ( ! $this->fill() ) {
				return strlen( $this->buffer );
			}
			$found = strpos( $this->buffer, $needle, $pos );
		}

		return $found + strlen( $needle );
	}

	/**
	 * Reads until the line starting at $pos is complete or the file ends.
	 */
	private function ensure_line( int $pos ): void {
		while ( false === strpos( $this->buffer, "\n", $pos ) ) {
			if ( ! $this->fill() ) {
				return;
			}
		}
	}

	/**
	 * @return bool Whether at least $length bytes are buffered; false only at the end of the file.
	 */
	private function ensure( int $length ): bool {
		while ( true ) {
			if ( strlen( $this->buffer ) >= $length ) {
				return true;
			}

			if ( ! $this->fill() ) {
				return false;
			}
		}
	}

	private function fill(): bool {
		if ( $this->eof ) {
			return false;
		}

		$chunk = fread( $this->handle, self::CHUNK );

		if ( false === $chunk || '' === $chunk ) {
			$this->eof = true;
			return false;
		}

		$this->buffer .= $chunk;

		return true;
	}

	private function consume( int $length ): void {
		$this->buffer  = (string) substr( $this->buffer, $length );
		$this->offset += $length;
	}
}

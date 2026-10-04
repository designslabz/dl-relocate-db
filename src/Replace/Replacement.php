<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Replace;

use InvalidArgumentException;

/**
 * What to search for, what to replace it with, and how to match.
 *
 * One or more pairs, applied together in a single pass: text produced by one
 * pair is never searched again by another, so pairs cannot chain into each
 * other. Besides the values the user typed, the search also covers the forms
 * those values take inside JSON (escaped slashes, \uXXXX escapes) and, for
 * URLs when requested, the other scheme and the protocol-relative form.
 */
final class Replacement {

	/**
	 * Every search/replace pair to apply, including the derived forms, the
	 * user's own pairs first.
	 *
	 * @var list<array{search: string, replace: string}>
	 */
	public readonly array $pairs;

	/**
	 * @param list<array{0: string, 1: string}> $values Search and replacement pairs, as entered.
	 * @throws InvalidArgumentException When the values cannot be used.
	 */
	public function __construct(
		public readonly array $values,
		public readonly bool $case_sensitive = true,
		public readonly bool $whole_words = false,
		public readonly bool $url_variants = false
	) {
		if ( ! $values ) {
			throw new InvalidArgumentException( 'At least one search value is needed.' );
		}

		$seen = array();

		foreach ( $values as [ $search, $replace ] ) {
			if ( '' === $search ) {
				throw new InvalidArgumentException( 'The search value cannot be empty.' );
			}

			if ( $search === $replace ) {
				throw new InvalidArgumentException( 'The search and replacement values are identical.' );
			}

			if ( ! self::is_utf8( $search ) || ! self::is_utf8( $replace ) ) {
				throw new InvalidArgumentException( 'The search and replacement values must be valid UTF-8.' );
			}

			$key = $case_sensitive ? $search : self::lowercase( $search );

			if ( isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException( 'The same search value is used twice.' );
			}

			$seen[ $key ] = true;
		}

		$this->pairs = $this->build_pairs();
	}

	/**
	 * @return list<array{search: string, replace: string}>
	 */
	private function build_pairs(): array {
		$pairs = array();

		foreach ( $this->values as [ $search, $replace ] ) {
			$pairs[] = array(
				'search'  => $search,
				'replace' => $replace,
			);
		}

		if ( $this->url_variants ) {
			foreach ( $this->values as [ $search, $replace ] ) {
				$pairs = array_merge( $pairs, self::url_pairs( $search, $replace ) );
			}
		}

		// JSON as written by json_encode(), with and without JSON_UNESCAPED_UNICODE.
		foreach ( $pairs as $pair ) {
			foreach ( array( JSON_UNESCAPED_UNICODE, 0 ) as $flags ) {
				$pairs[] = array(
					'search'  => self::json_escape( $pair['search'], $flags ),
					'replace' => self::json_escape( $pair['replace'], $flags ),
				);
			}
		}

		// The first pair for a given search wins, so the user's own values beat derived forms.
		$unique = array();
		foreach ( $pairs as $pair ) {
			if ( $pair['search'] !== $pair['replace'] && ! isset( $unique[ $pair['search'] ] ) ) {
				$unique[ $pair['search'] ] = $pair;
			}
		}

		return array_values( $unique );
	}

	/**
	 * For https://old.test also match http://old.test, and //old.test when the
	 * replacement is itself a URL with a scheme.
	 *
	 * @return list<array{search: string, replace: string}>
	 */
	private static function url_pairs( string $search, string $replace ): array {
		if ( ! preg_match( '#^(https?):(//.+)#i', $search, $search_parts ) ) {
			return array();
		}

		$pairs = array(
			array(
				'search'  => ( 0 === strcasecmp( $search_parts[1], 'https' ) ? 'http:' : 'https:' ) . $search_parts[2],
				'replace' => $replace,
			),
		);

		if ( preg_match( '#^https?:(//.+)#i', $replace, $replace_parts ) ) {
			$pairs[] = array(
				'search'  => $search_parts[2],
				'replace' => $replace_parts[1],
			);
		}

		return $pairs;
	}

	private static function json_escape( string $text, int $flags ): string {
		// Plain json_encode() keeps this class free of WordPress; the input is already known to be valid UTF-8.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return substr( (string) json_encode( $text, $flags ), 1, -1 );
	}

	/**
	 * Case folding used to tell whether two search values are the same.
	 */
	public static function lowercase( string $text ): string {
		// mbstring is common but not guaranteed on WordPress hosts.
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	private static function is_utf8( string $text ): bool {
		return 1 === preg_match( '//u', $text );
	}
}

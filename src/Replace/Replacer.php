<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Replace;

use RuntimeException;

/**
 * Applies a Replacement to single database values.
 *
 * All pairs are applied in one regex pass, so text produced by one pair is
 * never matched again by another, and a replacement that contains the search
 * value is applied exactly once.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 */
final class Replacer {

	/**
	 * Replacement text for each capture group, in pattern order.
	 *
	 * @var string[]
	 */
	private array $replacements;

	private string $byte_pattern;

	private ?string $unicode_pattern = null;

	public function __construct( private Replacement $replacement ) {
		$pairs = $replacement->pairs;

		// At the same offset the longest search wins, e.g. https://a.test before //a.test.
		usort( $pairs, fn( array $a, array $b ): int => strlen( $b['search'] ) <=> strlen( $a['search'] ) );

		$this->replacements = array_column( $pairs, 'replace' );
		$this->byte_pattern = $this->pattern( $pairs, false );

		// Unicode mode is only needed for multibyte case folding and word boundaries.
		if ( ! $replacement->case_sensitive || $replacement->whole_words ) {
			$this->unicode_pattern = $this->pattern( $pairs, true );
		}
	}

	/**
	 * @throws RuntimeException When PCRE fails on the value.
	 */
	public function replace( string $value ): ReplaceResult {
		$pattern = $this->pattern_for( $value );

		if ( ! $this->matches( $pattern, $value ) ) {
			return new ReplaceResult( $value, 0 );
		}

		if ( SerializedString::looks_serialized( $value ) ) {
			return $this->replace_serialized( $value );
		}

		[ $new_value, $count ] = $this->replace_text( $pattern, $value );

		if ( $this->breaks_json( $value, $new_value ) ) {
			return new ReplaceResult( $value, 0, ReplaceResult::BROKEN_JSON );
		}

		return new ReplaceResult( $new_value, $count );
	}

	private function replace_serialized( string $value ): ReplaceResult {
		$result = SerializedString::rewrite( $value, fn( string $content ): ReplaceResult => $this->replace( $content ) );

		if ( null === $result ) {
			return new ReplaceResult( $value, 0, ReplaceResult::INVALID_SERIALIZED );
		}

		// The rewriter only ever touches string contents and their lengths, but this is a
		// destructive operation: anything PHP could read before must still be readable after.
		if ( $result->count > 0 && self::unserializes( $value ) && ! self::unserializes( $result->value ) ) {
			return new ReplaceResult( $value, 0, ReplaceResult::INVALID_SERIALIZED );
		}

		return $result;
	}

	/**
	 * @return array{0: string, 1: int}
	 */
	private function replace_text( string $pattern, string $value ): array {
		$count     = 0;
		$new_value = preg_replace_callback(
			$pattern,
			function ( array $groups ): string {
				foreach ( $this->replacements as $index => $replace ) {
					if ( null !== ( $groups[ $index + 1 ] ?? null ) ) {
						return $replace;
					}
				}
				return $groups[0];
			},
			$value,
			-1,
			$count,
			PREG_UNMATCHED_AS_NULL
		);

		if ( null === $new_value ) {
			throw new RuntimeException( 'Replacement failed: ' . preg_last_error_msg() );
		}

		return array( $new_value, $count );
	}

	private function matches( string $pattern, string $value ): bool {
		$matched = preg_match( $pattern, $value );

		if ( false === $matched ) {
			throw new RuntimeException( 'Search failed: ' . preg_last_error_msg() );
		}

		return 1 === $matched;
	}

	/**
	 * The /u pattern rejects invalid UTF-8, which real databases do contain. Those
	 * values fall back to byte matching: still exact, with ASCII-only case folding.
	 */
	private function pattern_for( string $value ): string {
		if ( null !== $this->unicode_pattern && 1 === preg_match( '//u', $value ) ) {
			return $this->unicode_pattern;
		}

		return $this->byte_pattern;
	}

	/**
	 * @param list<array{search: string, replace: string}> $pairs Pairs, longest search first.
	 */
	private function pattern( array $pairs, bool $unicode ): string {
		$modifiers = ( $this->replacement->case_sensitive ? '' : 'i' ) . ( $unicode ? 'u' : '' );
		$word_char = $unicode ? '[\p{L}\p{N}_]' : '[A-Za-z0-9_]';
		$groups    = array();

		foreach ( $pairs as $pair ) {
			$needle = preg_quote( $pair['search'], '/' );

			// Only enforce a boundary where the search itself starts or ends with a word character,
			// so "example.com/" still matches "example.com/page".
			if ( $this->replacement->whole_words ) {
				if ( preg_match( '/^' . $word_char . '/' . $modifiers, $pair['search'] ) ) {
					$needle = '(?<!' . $word_char . ')' . $needle;
				}
				if ( preg_match( '/' . $word_char . '\z/' . $modifiers, $pair['search'] ) ) {
					$needle .= '(?!' . $word_char . ')';
				}
			}

			$groups[] = '(' . $needle . ')';
		}

		return '/' . implode( '|', $groups ) . '/' . $modifiers;
	}

	private function breaks_json( string $before, string $after ): bool {
		$first = ltrim( $before )[0] ?? '';

		if ( '{' !== $first && '[' !== $first ) {
			return false;
		}

		return self::is_json( $before ) && ! self::is_json( $after );
	}

	private static function is_json( string $text ): bool {
		if ( function_exists( 'json_validate' ) ) {
			return json_validate( $text );
		}

		json_decode( $text );
		return JSON_ERROR_NONE === json_last_error();
	}

	private static function unserializes( string $data ): bool {
		// allowed_classes => false: objects come back as __PHP_Incomplete_Class, no class code runs.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- Validity check only; failure is the expected signal.
		return false !== @unserialize( $data, array( 'allowed_classes' => false ) );
	}
}

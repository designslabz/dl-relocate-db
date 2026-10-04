<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Tests\Unit;

use CraftRoq\Relocate\Replace\Replacement;
use CraftRoq\Relocate\Replace\Replacer;
use CraftRoq\Relocate\Transfer\SqlValues;
use PHPUnit\Framework\TestCase;

/**
 * Changing text inside the values of SQL statements, as an import does.
 *
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
 */
final class SqlValuesTest extends TestCase {

	private function replace( string $sql, string $search = 'http://old.test', string $replace = 'https://new.example' ): string {
		$replacer = new Replacer( new Replacement( array( array( $search, $replace ) ) ) );

		return SqlValues::rewrite(
			$sql,
			function ( string $value ) use ( $replacer ): ?string {
				$result = $replacer->replace( $value );
				return $result->count ? $result->value : null;
			}
		);
	}

	public function test_values_are_replaced_and_everything_else_is_kept(): void {
		$this->assertSame(
			"INSERT INTO `http://old.test` (`a`, `b`) VALUES (1, 'see https://new.example/x', NULL, 0x687474703a2f2f6f6c642e74657374);",
			$this->replace( "INSERT INTO `http://old.test` (`a`, `b`) VALUES (1, 'see http://old.test/x', NULL, 0x687474703a2f2f6f6c642e74657374);" )
		);
	}

	public function test_untouched_literals_are_copied_byte_for_byte(): void {
		$sql = "INSERT INTO t VALUES ('it''s', \"double\", 'tab\\there', 'back\\\\slash');";

		$this->assertSame( $sql, $this->replace( $sql ) );
	}

	public function test_serialized_values_get_correct_lengths_through_sql_escaping(): void {
		$value = serialize(
			array(
				'url'   => 'http://old.test',
				'quote' => "It's \"quoted\"\nwith a newline",
			)
		);
		$sql   = "INSERT INTO t VALUES ('" . SqlValues::escape( $value ) . "');";

		$rewritten = $this->replace( $sql );

		preg_match( "/VALUES \\('(.*)'\\);$/s", $rewritten, $match );
		$stored = stripcslashes( $match[1] );

		$this->assertSame(
			array(
				'url'   => 'https://new.example',
				'quote' => "It's \"quoted\"\nwith a newline",
			),
			unserialize( $stored )
		);
	}

	public function test_json_with_escaped_slashes_is_replaced(): void {
		// JSON's \/ is stored as \\/ inside an SQL string.
		$this->assertSame(
			"INSERT INTO t VALUES ('{\\\"u\\\":\\\"https:\\\\/\\\\/new.example\\\"}');",
			$this->replace( "INSERT INTO t VALUES ('{\"u\":\"http:\\\\/\\\\/old.test\"}');" )
		);
	}

	public function test_binary_strings_are_left_alone(): void {
		$sql = "INSERT INTO t VALUES (_binary 'http://old.test', 'http://old.test');";

		$this->assertSame( "INSERT INTO t VALUES (_binary 'http://old.test', 'https://new.example');", $this->replace( $sql ) );
	}

	public function test_escape_round_trips_every_special_character(): void {
		$value     = "nul\0 nl\n cr\r sub\x1a back\\ single' double\" percent\\% under\\_";
		$rewritten = SqlValues::rewrite( "SELECT '" . SqlValues::escape( $value ) . "'", fn( string $decoded ): string => $decoded );

		$this->assertSame( "SELECT '" . SqlValues::escape( $value ) . "'", $rewritten );
	}
}

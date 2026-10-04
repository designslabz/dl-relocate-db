<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Tests\Unit;

use CraftRoq\Relocate\Transfer\SqlReader;
use PHPUnit\Framework\TestCase;

/**
 * Splitting SQL files into statements, as mysqldump, phpMyAdmin and the plugin write them.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 */
final class SqlReaderTest extends TestCase {

	/** @var list<string> */
	private array $files = array();

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			unlink( $file );
		}
	}

	private function file( string $sql ): string {
		$path          = (string) tempnam( sys_get_temp_dir(), 'crq' );
		$this->files[] = $path;
		file_put_contents( $path, $sql );

		return $path;
	}

	/**
	 * @return list<string>
	 */
	private function statements( string $sql ): array {
		$reader     = new SqlReader( $this->file( $sql ) );
		$statements = array();

		for ( $statement = $reader->next(); null !== $statement; $statement = $reader->next() ) {
			$statements[] = $statement;
		}

		return $statements;
	}

	public function test_statements_are_split_on_the_delimiter(): void {
		$this->assertSame( array( 'SELECT 1', 'SELECT 2' ), $this->statements( "SELECT 1;\nSELECT 2;\n" ) );
	}

	public function test_a_last_statement_without_a_delimiter_is_kept(): void {
		$this->assertSame( array( 'SELECT 1', 'SELECT 2' ), $this->statements( "SELECT 1;\nSELECT 2\n" ) );
	}

	public function test_delimiters_inside_strings_and_identifiers_do_not_split(): void {
		$sql = "INSERT INTO `a;b` VALUES ('x;y', 'it\\'s; fine', 'twice''; quoted', \"double;\", 'back\\\\');\nSELECT 2;";

		$this->assertSame(
			array( "INSERT INTO `a;b` VALUES ('x;y', 'it\\'s; fine', 'twice''; quoted', \"double;\", 'back\\\\')", 'SELECT 2' ),
			$this->statements( $sql )
		);
	}

	public function test_comments_are_dropped_but_executable_comments_kept(): void {
		$sql = "-- A comment; with a semicolon\n# Another; one\n/* Block; comment */\nSELECT 1;\n/*!40101 SET NAMES utf8mb4 */;\n";

		$this->assertSame( array( 'SELECT 1', '/*!40101 SET NAMES utf8mb4 */' ), $this->statements( $sql ) );
	}

	public function test_double_dash_without_a_space_is_not_a_comment(): void {
		$this->assertSame( array( 'SELECT 1--1', 'SELECT 2' ), $this->statements( "SELECT 1--1;\nSELECT 2;" ) );
	}

	public function test_a_file_of_only_comments_has_no_statements(): void {
		$this->assertSame( array(), $this->statements( "-- Nothing here\n/* nor here */\n" ) );
	}

	public function test_delimiter_lines_change_the_delimiter(): void {
		$sql = "DELIMITER ;;\nCREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET @a = 1; SET @b = 2; END;;\nDELIMITER ;\nSELECT 2;";

		$this->assertSame(
			array( 'CREATE TRIGGER t BEFORE INSERT ON x FOR EACH ROW BEGIN SET @a = 1; SET @b = 2; END', 'SELECT 2' ),
			$this->statements( $sql )
		);
	}

	public function test_reading_carries_on_from_a_saved_offset(): void {
		$path   = $this->file( "SELECT 1;\nDELIMITER $$\nSELECT 2$$\nSELECT 3$$\n" );
		$reader = new SqlReader( $path );

		$this->assertSame( 'SELECT 1', $reader->next() );
		$this->assertSame( 'SELECT 2', $reader->next() );

		$resumed = new SqlReader( $path, $reader->offset(), $reader->delimiter() );

		$this->assertSame( 'SELECT 3', $resumed->next() );
		$this->assertNull( $resumed->next() );
	}

	public function test_statements_larger_than_the_read_chunk(): void {
		// Over the 1 MB chunk, with delimiters and quotes inside the string.
		$value = str_repeat( "a;b'c\\'d", 300000 );
		$value = str_replace( "'c", "''c", $value );
		$sql   = "INSERT INTO t VALUES ('" . $value . "');\nSELECT 2;";

		$statements = $this->statements( $sql );

		$this->assertCount( 2, $statements );
		$this->assertSame( "INSERT INTO t VALUES ('" . $value . "')", $statements[0] );
		$this->assertSame( 'SELECT 2', $statements[1] );
	}
}

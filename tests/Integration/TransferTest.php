<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Tests\Integration;

use CraftRoq\Relocate\Database\Schema;
use CraftRoq\Relocate\Installer;
use CraftRoq\Relocate\Jobs\JobException;
use CraftRoq\Relocate\Jobs\JobStatus;
use CraftRoq\Relocate\Logger;
use CraftRoq\Relocate\Rest\TransfersController;
use CraftRoq\Relocate\Storage;
use CraftRoq\Relocate\Transfer\Exporter;
use CraftRoq\Relocate\Transfer\Importer;
use CraftRoq\Relocate\Transfer\Transfer;
use CraftRoq\Relocate\Transfer\TransferRepository;
use CraftRoq\Relocate\Transfer\TransferRunner;
use CraftRoq\Relocate\Transfer\TransferStarter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Database exports and imports against real tables.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
 */
final class TransferTest extends WP_UnitTestCase {

	private const OLD = 'http://old.test';
	private const NEW = 'https://new.example';

	private TransferRepository $transfers;

	private TransferRunner $runner;

	private TransferStarter $starter;

	private static int $admin_id;

	public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ): void {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up(): void {
		parent::set_up();

		// Real tables: temporary ones are invisible to information_schema.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;

		$data   = self::table( 'data' );
		$no_key = self::table( 'nokey' );

		$wpdb->query( "DROP TABLE IF EXISTS {$data}, {$no_key}" );
		$wpdb->query(
			"CREATE TABLE {$data} (
				id int(11) NOT NULL AUTO_INCREMENT,
				title varchar(100) NOT NULL DEFAULT '',
				body longtext,
				bytes longblob,
				amount decimal(10,2) DEFAULT NULL,
				doubled decimal(12,2) GENERATED ALWAYS AS (amount * 2) VIRTUAL,
				PRIMARY KEY (id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
		$wpdb->query( "CREATE TABLE {$no_key} (note text) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

		// Row 0 checks that a zero key survives (NO_AUTO_VALUE_ON_ZERO).
		$wpdb->query( "SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')" );
		$rows = array(
			array( 0, 'Zero', 'Visit ' . self::OLD, "\x00\x01\xff", '1.50' ),
			array( 1, "It's 100% \"quoted\"; with \\ backslash", serialize( array( 'home' => self::OLD . '/a' ) ), null, null ),
			array( 2, 'Grüße 日本', '{"url":"' . str_replace( '/', '\/', self::OLD ) . '"}', '', '-3.25' ),
		);
		foreach ( range( 3, 9 ) as $id ) {
			$rows[] = array( $id, "Row {$id}", "Text {$id}\nline two", random_bytes( 8 ), (string) $id );
		}

		foreach ( $rows as [ $id, $title, $body, $bytes, $amount ] ) {
			$wpdb->insert( $data, compact( 'id', 'title', 'body', 'bytes', 'amount' ) );
		}

		$wpdb->query( "INSERT INTO {$no_key} (note) VALUES ('first'), ('second'), (NULL)" );

		$this->transfers = new TransferRepository( $wpdb );
		$schema          = new Schema( $wpdb );
		$this->runner    = new TransferRunner(
			$wpdb,
			$this->transfers,
			// A batch size of 3 makes the tables take several batches each.
			new Exporter( $wpdb, $schema, $this->transfers, 3 ),
			new Importer( $wpdb, $this->transfers ),
			new Logger( $wpdb )
		);
		$this->starter = new TransferStarter( $wpdb, $this->transfers, $schema, new Logger( $wpdb ) );

		wp_set_current_user( self::$admin_id );

		// One batch or statement per step, so tests can interrupt between them.
		add_filter( 'crq_relocate_step_seconds', '__return_zero' );
	}

	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Installer::TRANSFERS_TABLE );
		Storage::delete_all();

		// Last: DDL commits implicitly, which also makes the clean-up above stick.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( 'data' ) . ', ' . self::table( 'nokey' ) . ', ' . self::table( 'later' ) );

		parent::tear_down();
	}

	public function test_export_writes_the_tables_with_text_changed_and_leaves_the_database_alone(): void {
		$export = $this->run_to_end( $this->export( array( array( self::OLD, self::NEW ) ) ) );

		$this->assertSame( JobStatus::Completed, $export->status, (string) $export->error_message );

		$sql = $this->contents( $export );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `' . self::table( 'data' ) . '`', $sql );
		$this->assertStringContainsString( '-- Table prefix: ' . $GLOBALS['wpdb']->prefix, $sql );
		$this->assertStringNotContainsString( 'INSERT INTO `' . self::table( 'data' ) . '` (`id`, `title`, `body`, `bytes`, `amount`, `doubled`)', $sql, 'Generated columns are not inserted.' );

		$inserts = implode( "\n", preg_grep( '/^\(|^INSERT/', explode( "\n", $sql ) ) );
		$this->assertStringNotContainsString( self::OLD, $inserts );
		$this->assertStringNotContainsString( str_replace( '/', '\\\\/', self::OLD ), $inserts );
		$this->assertSame( 3, $export->state['replacements'] );
		$this->assertSame( 13, $export->state['rows'] );

		global $wpdb;
		$this->assertStringContainsString( self::OLD, (string) $wpdb->get_var( 'SELECT body FROM ' . self::table( 'data' ) . ' WHERE id = 0' ) );
	}

	public function test_export_then_import_restores_the_tables_exactly(): void {
		global $wpdb;

		$before = $this->snapshot();
		$export = $this->run_to_end( $this->export() );

		$wpdb->query( 'UPDATE ' . self::table( 'data' ) . " SET title = 'changed', bytes = NULL" );
		$wpdb->query( 'DELETE FROM ' . self::table( 'data' ) . ' WHERE id > 5' );
		$wpdb->query( 'DROP TABLE ' . self::table( 'nokey' ) );

		$import = $this->run_to_end( $this->import_file( $export ) );

		$this->assertSame( JobStatus::Completed, $import->status, (string) $import->error_message );
		$this->assertSame( $before, $this->snapshot() );
		$this->assertSame( '', $import->file, 'The imported file is deleted once it has run.' );
		$this->assertSame( '18.00', $wpdb->get_var( 'SELECT doubled FROM ' . self::table( 'data' ) . ' WHERE id = 9' ), 'Generated columns are computed again.' );
	}

	public function test_import_changes_text_on_the_way_in(): void {
		global $wpdb;

		$export = $this->run_to_end( $this->export() );
		$stored = $this->store( (string) gzencode( $this->contents( $export ) ), '.sql.gz' );

		[ $import ] = $this->starter->import( $stored, 'test.sql.gz', array( array( self::OLD, self::NEW ) ) );
		$import     = $this->run_to_end( $import );

		$this->assertSame( JobStatus::Completed, $import->status, (string) $import->error_message );
		$this->assertSame( 3, $import->state['replacements'] );

		$table = self::table( 'data' );
		$this->assertSame( 'Visit ' . self::NEW, $wpdb->get_var( "SELECT body FROM {$table} WHERE id = 0" ) );
		$this->assertSame( array( 'home' => self::NEW . '/a' ), unserialize( (string) $wpdb->get_var( "SELECT body FROM {$table} WHERE id = 1" ) ), 'Serialized lengths are fixed.' );
		$this->assertSame( array( 'url' => self::NEW ), json_decode( (string) $wpdb->get_var( "SELECT body FROM {$table} WHERE id = 2" ), true ) );
		$this->assertSame( "It's 100% \"quoted\"; with \\ backslash", $wpdb->get_var( "SELECT title FROM {$table} WHERE id = 1" ), 'Other values are untouched.' );
	}

	public function test_import_saves_its_progress_in_a_request_that_has_not_seen_the_tables(): void {
		global $wpdb;

		$table  = self::table( 'nokey' );
		$stored = $this->store( "INSERT INTO `{$table}` (note) VALUES ('Café Zürich');\nINSERT INTO `{$table}` (note) VALUES ('later');\n", '.sql' );

		// Non-ASCII text is what wpdb checks against a column's character set when the progress is saved.
		[ $import ] = $this->starter->import( $stored, 'test.sql', array( array( 'Café Zürich', 'Kaffee Köln' ) ) );

		$steps = 0;

		while ( $import && ! $import->status->is_finished() && $steps++ < 10 ) {
			// Every step is a new request, in which wpdb has not looked at any table yet.
			$this->forget_table_details();
			$import = $this->runner->step( $import );
		}

		$this->assertNotNull( $import );
		$this->assertSame( JobStatus::Completed, $import->status, (string) $import->error_message );
		$this->assertSame( 1, $import->state['replacements'] );
		$this->assertContains( 'Kaffee Köln', $wpdb->get_col( "SELECT note FROM {$table}" ) );
	}

	public function test_import_pairs_are_validated_before_anything_runs(): void {
		$stored = $this->store( "SELECT 1;\n", '.sql' );

		try {
			$this->starter->import( $stored, 'x.sql', array( array( 'same', 'same' ) ) );
			$this->fail( 'The import should have been refused.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'crq_relocate_same_values', $e->error_code );
		}

		$this->assertNull( Storage::path( $stored ), 'The refused file is deleted.' );
	}

	public function test_an_export_interrupted_mid_write_does_not_repeat_rows(): void {
		$export = $this->export();
		$export = $this->runner->step( $export );
		$export = $this->runner->step( $export );

		// A request that died after writing a batch but before saving its position.
		$handle = gzopen( Storage::path( $export->file ), 'ab' );
		gzwrite( $handle, 'INSERT INTO `' . self::table( 'data' ) . "` VALUES (1, 'duplicate');\n" );
		gzclose( $handle );

		$export = $this->run_to_end( $export );
		$this->assertStringNotContainsString( "'duplicate'", $this->contents( $export ) );

		$import = $this->run_to_end( $this->import_file( $export ) );
		$this->assertSame( JobStatus::Completed, $import->status, (string) $import->error_message );
	}

	public function test_import_skips_statements_that_would_break_it(): void {
		global $wpdb;

		$jobs = $wpdb->prefix . Installer::JOBS_TABLE;
		$data = self::table( 'data' );
		$sql  = implode(
			"\n",
			array(
				'-- A dump from another tool',
				'/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE */;',
				'USE `some_other_database`;',
				"LOCK TABLES `{$data}` WRITE;",
				"INSERT INTO `{$data}` (`id`, `title`) VALUES (50, 'from file');",
				'UNLOCK TABLES;',
				"DROP TABLE IF EXISTS `{$jobs}`;",
				'/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;',
			)
		);

		$import = $this->run_to_end( $this->import_sql( $sql ) );

		$this->assertSame( JobStatus::Completed, $import->status, (string) $import->error_message );
		$this->assertSame( 4, $import->state['skipped'] );
		$this->assertSame( 'from file', $wpdb->get_var( "SELECT title FROM {$data} WHERE id = 50" ) );
		$this->assertSame( $jobs, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs ) ), 'The plugin’s own tables are never touched.' );
	}

	public function test_a_failed_import_resumes_at_the_statement_that_failed(): void {
		global $wpdb;

		$later  = self::table( 'later' );
		$import = $this->run_to_end( $this->import_sql( "INSERT INTO `{$later}` VALUES (1);\nINSERT INTO `{$later}` VALUES (2);\n" ) );

		$this->assertSame( JobStatus::Failed, $import->status );
		$this->assertStringContainsString( 'later', (string) $import->error_message );

		$wpdb->query( "CREATE TABLE {$later} (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB" );

		$import = $this->run_to_end( $this->runner->resume( $import ) );

		$this->assertSame( JobStatus::Completed, $import->status, (string) $import->error_message );
		$this->assertSame( array( '1', '2' ), $wpdb->get_col( "SELECT id FROM {$later} ORDER BY id" ) );
	}

	public function test_a_file_from_a_site_with_another_table_prefix_is_refused(): void {
		$stored = $this->store( "-- CR Relocate DB database export\n-- Table prefix: other_\nSELECT 1;\n", '.sql' );

		try {
			$this->starter->import( $stored, 'other.sql' );
			$this->fail( 'The import should have been refused.' );
		} catch ( JobException $e ) {
			$this->assertSame( 'crq_relocate_prefix_mismatch', $e->error_code );
		}

		$this->assertNull( Storage::path( $stored ), 'The refused file is deleted.' );
	}

	public function test_export_pairs_are_validated(): void {
		$this->expectException( JobException::class );

		$this->starter->export( TransferStarter::SCOPE_CORE, array( array( 'same', 'same' ) ) );
	}

	public function test_import_steps_run_with_the_key_after_the_session_ends(): void {
		[ $import, $key ] = $this->starter->import( $this->store( "SELECT 1;\n", '.sql' ), 'tiny.sql' );

		// As if the import had replaced the users table.
		wp_set_current_user( 0 );

		$wrong = new WP_REST_Request( 'POST', '/crq-relocate/v1/transfers/' . $import->id . '/run' );
		$wrong->set_header( TransfersController::KEY_HEADER, 'not-the-key' );
		$this->assertSame( 401, rest_get_server()->dispatch( $wrong )->get_status() );

		$right = new WP_REST_Request( 'POST', '/crq-relocate/v1/transfers/' . $import->id . '/run' );
		$right->set_header( TransfersController::KEY_HEADER, $key );
		$this->assertSame( 200, rest_get_server()->dispatch( $right )->get_status() );

		$export = $this->export();
		$other  = new WP_REST_Request( 'POST', '/crq-relocate/v1/transfers/' . $export->id . '/run' );
		$other->set_header( TransfersController::KEY_HEADER, $key );
		$this->assertSame( 401, rest_get_server()->dispatch( $other )->get_status(), 'A key only works for its own import.' );
	}

	/**
	 * @param list<array{0: string, 1: string}> $pairs
	 */
	private function export( array $pairs = array() ): Transfer {
		$export = $this->transfers->create(
			new Transfer(
				0,
				Transfer::EXPORT,
				JobStatus::Pending,
				array(
					'scope'  => 'test',
					'tables' => array( self::table( 'data' ), self::table( 'nokey' ) ),
					'pairs'  => $pairs,
				),
				Exporter::initial_state( 13 ),
				'',
				self::$admin_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		$export->file = Storage::name( 'export-' . $export->id, '.sql.gz' );
		$this->transfers->save( $export );

		return $export;
	}

	private function import_file( Transfer $export ): Transfer {
		return $this->import_sql( $this->contents( $export ) );
	}

	private function import_sql( string $sql ): Transfer {
		return $this->starter->import( $this->store( (string) gzencode( $sql ), '.sql.gz' ), 'test.sql.gz' )[0];
	}

	private function store( string $contents, string $extension ): string {
		$name = Storage::name( 'upload', $extension );
		file_put_contents( Storage::make_directory() . '/' . $name, $contents );

		return $name;
	}

	private function run_to_end( ?Transfer $transfer ): Transfer {
		$steps = 0;

		while ( $transfer && ! $transfer->status->is_finished() && $steps++ < 500 ) {
			$transfer = $this->runner->step( $transfer );
		}

		$this->assertNotNull( $transfer );

		return $transfer;
	}

	/**
	 * Empties what wpdb remembers about tables' character sets and columns, as at the start of a request.
	 */
	private function forget_table_details(): void {
		global $wpdb;

		foreach ( array( 'table_charset', 'col_meta' ) as $name ) {
			$property = new \ReflectionProperty( \wpdb::class, $name );
			$property->setAccessible( true );
			$property->setValue( $wpdb, array() );
		}
	}

	private function contents( Transfer $export ): string {
		return implode( '', (array) gzfile( (string) Storage::path( $export->file ) ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function snapshot(): array {
		global $wpdb;

		return array(
			'data'  => $wpdb->get_results( 'SELECT id, title, body, HEX(bytes) AS bytes, amount FROM ' . self::table( 'data' ) . ' ORDER BY id', ARRAY_A ),
			'nokey' => $wpdb->get_col( 'SELECT note FROM ' . self::table( 'nokey' ) ),
		);
	}

	private static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'crqt_' . $name;
	}
}

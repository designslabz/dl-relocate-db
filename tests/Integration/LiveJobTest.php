<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Tests\Integration;

use CraftRoq\Relocate\Installer;
use CraftRoq\Relocate\Jobs\BeforeImage;
use CraftRoq\Relocate\Jobs\JobRepository;
use CraftRoq\Relocate\Replace\Replacement;
use CraftRoq\Relocate\Replace\Replacer;
use CraftRoq\Relocate\Settings;
use CraftRoq\Relocate\Storage;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Live replacements, which commit their own transactions.
 *
 * That also commits the test framework's per-test transaction, so nothing
 * here is rolled back automatically: every test starts from a freshly built
 * fixture table and tear_down() removes what the test left behind.
 */
final class LiveJobTest extends WP_UnitTestCase {

	private const OLD = 'http://old.test';
	private const NEW = 'https://new.example';

	private const ROWS = 600;

	private static int $admin_id;

	/** @var array<string, string> */
	private array $site_address = array();

	public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ): void {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up(): void {
		parent::set_up();

		// Real tables: temporary ones are invisible to information_schema.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		global $wpdb;

		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$wpdb->query(
			"CREATE TABLE {$table} (
				id int(11) NOT NULL,
				body longtext,
				meta longtext,
				PRIMARY KEY (id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		$values = array();
		foreach ( range( 1, self::ROWS ) as $id ) {
			$body = 0 === $id % 2 ? "Row {$id}: " . self::OLD . '/a and ' . self::OLD . '/Grüße' : "Row {$id}: nothing";
			$meta = 0 === $id % 5 ? serialize(
				array(
					'home' => self::OLD,
					'n'    => $id,
				)
			) : '';

			$values[] = $wpdb->prepare( '(%d, %s, %s)', $id, $body, $meta );
		}
		$wpdb->query( "INSERT INTO {$table} (id, body, meta) VALUES " . implode( ',', $values ) );

		update_option( Settings::OPTION, array( 'batch_size' => 100 ) );

		$this->site_address = array(
			'siteurl' => (string) get_option( 'siteurl' ),
			'home'    => (string) get_option( 'home' ),
		);

		wp_set_current_user( self::$admin_id );

		// One window per request, so tests can stop and inspect between windows.
		add_filter( 'crq_relocate_step_seconds', '__return_zero' );
	}

	public function tear_down(): void {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Installer::JOBS_TABLE );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Installer::LOG_TABLE );

		foreach ( $this->site_address as $name => $value ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => $name ) );
		}

		delete_option( Settings::OPTION );
		Storage::delete_all();
		wp_cache_flush();

		// Last: DDL commits implicitly, which also makes the clean-up above stick
		// instead of being undone by the framework's rollback.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() );

		parent::tear_down();
	}

	public function test_live_run_writes_exactly_what_the_dry_run_previewed(): void {
		$original = $this->rows();
		$dry_run  = $this->dry_run( array( self::table() ) );
		$live     = $this->finish( $this->execute( $dry_run ) );

		$this->assertSame( 'completed', $live['status'] );
		$this->assertSame( $dry_run['totals'], $live['totals'] );
		$this->assertSame( $this->expected( $original, new Replacement( array( array( self::OLD, self::NEW ) ) ) ), $this->rows() );
	}

	public function test_serialized_values_still_unserialize_after_replacement(): void {
		$this->finish( $this->execute( $this->dry_run( array( self::table() ) ) ) );

		global $wpdb;
		$meta = $wpdb->get_var( $wpdb->prepare( 'SELECT meta FROM %i WHERE id = 5', self::table() ) );

		$this->assertSame(
			array(
				'home' => self::NEW,
				'n'    => 5,
			),
			unserialize( $meta )
		);
	}

	public function test_before_image_restores_the_original_values(): void {
		global $wpdb;

		$original = $this->rows();
		$live     = $this->finish( $this->execute( $this->dry_run( array( self::table() ) ) ) );

		$this->assertNotSame( $original, $this->rows() );
		$this->assertNotNull( $live['before_image_url'] );

		$job  = ( new JobRepository( $wpdb ) )->find( $live['id'] );
		$path = ( new BeforeImage( $wpdb ) )->path( $job->before_image );

		foreach ( gzfile( $path ) as $line ) {
			if ( str_starts_with( $line, 'UPDATE ' ) ) {
				$this->assertNotFalse( $wpdb->query( $line ) );
			}
		}

		$this->assertSame( $original, $this->rows() );
	}

	public function test_before_image_can_be_turned_off(): void {
		$live = $this->finish( $this->execute( $this->dry_run( array( self::table() ) ), false ) );

		$this->assertSame( 'completed', $live['status'] );
		$this->assertNull( $live['before_image_url'] );
	}

	/**
	 * The replacement contains the search value, so a window applied twice
	 * would show up as http://old.test/moved/moved.
	 */
	public function test_failed_window_is_rolled_back_and_resume_applies_it_once(): void {
		global $wpdb;

		$original = $this->rows();
		$search   = self::OLD;
		$replace  = self::OLD . '/moved';
		$table    = self::table();

		$dry_run = $this->dry_run( array( $table ), $search, $replace );
		$live    = $this->execute( $dry_run );

		// Fail the update of row 350, in the fourth window (301-400), once.
		$failed = false;
		$break  = function ( string $query ) use ( &$failed, $table ): string {
			if ( ! $failed && str_starts_with( $query, "UPDATE `{$table}`" ) && str_ends_with( $query, '`id` = 350' ) ) {
				$failed = true;
				return 'THIS IS NOT SQL';
			}
			return $query;
		};
		add_filter( 'query', $break );

		$suppress = $wpdb->suppress_errors( true );
		$live     = $this->finish( $live );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break );

		$this->assertSame( 'failed', $live['status'] );

		$rows = $this->rows();
		$this->assertNotSame( $original[300], $rows[300], 'Rows before the failed window stay changed.' );
		$this->assertSame( $original[302], $rows[302], 'The failed window was rolled back completely.' );
		$this->assertSame( $original[350], $rows[350] );
		$this->assertSame( $original[600], $rows[600], 'Nothing after the failed window was touched.' );

		$live = $this->request( 'POST', "/jobs/{$live['id']}/resume" )->get_data();
		$live = $this->finish( $live );

		$this->assertSame( 'completed', $live['status'] );
		$this->assertSame( $dry_run['totals'], $live['totals'] );
		$this->assertSame( $this->expected( $original, new Replacement( array( array( $search, $replace ) ) ) ), $this->rows() );
	}

	public function test_site_address_is_changed_last(): void {
		global $wpdb;

		$wpdb->update( $wpdb->options, array( 'option_value' => self::OLD ), array( 'option_name' => 'siteurl' ) );
		$wpdb->update( $wpdb->options, array( 'option_value' => self::OLD ), array( 'option_name' => 'home' ) );
		wp_cache_flush();

		$live = $this->execute( $this->dry_run( array( $wpdb->options, self::table() ) ) );

		while ( ! $live['finished'] ) {
			$this->assertSame( self::OLD, $this->raw_option( 'siteurl' ), 'siteurl must not change before the end.' );
			$live = $this->request( 'POST', "/jobs/{$live['id']}/run" )->get_data();
		}

		$this->assertSame( 'completed', $live['status'] );
		$this->assertTrue( $live['site_address_changed'] );
		$this->assertSame( self::NEW, $this->raw_option( 'siteurl' ) );
		$this->assertSame( self::NEW, $this->raw_option( 'home' ) );
		$this->assertSame( self::NEW, get_option( 'home' ), 'The object cache must not hold the old address.' );
	}

	public function test_execute_needs_confirmation(): void {
		$dry_run  = $this->dry_run( array( self::table() ) );
		$response = $this->request( 'POST', "/jobs/{$dry_run['id']}/execute", array( 'confirmed' => false ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'crq_relocate_not_confirmed', $response->get_data()['code'] );
	}

	public function test_execute_needs_a_completed_dry_run_with_changes(): void {
		$pending = $this->request(
			'POST',
			'/jobs',
			array(
				'search'  => self::OLD,
				'replace' => self::NEW,
				'tables'  => array( self::table() ),
			)
		)->get_data();

		$this->assertSame( 'crq_relocate_not_executable', $this->request( 'POST', "/jobs/{$pending['id']}/execute", array( 'confirmed' => true ) )->get_data()['code'] );

		$nothing = $this->dry_run( array( self::table() ), 'no such text anywhere' );

		$this->assertFalse( $nothing['executable'] );
		$this->assertSame( 'crq_relocate_nothing_to_replace', $this->request( 'POST', "/jobs/{$nothing['id']}/execute", array( 'confirmed' => true ) )->get_data()['code'] );
	}

	public function test_a_dry_run_can_only_be_executed_once(): void {
		$dry_run = $this->dry_run( array( self::table() ) );
		$this->execute( $dry_run );

		$again = $this->request( 'POST', "/jobs/{$dry_run['id']}/execute", array( 'confirmed' => true ) );

		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'crq_relocate_already_executed', $again->get_data()['code'] );
		$this->assertFalse( $this->request( 'GET', "/jobs/{$dry_run['id']}" )->get_data()['executable'] );
	}

	public function test_a_dry_run_stays_applied_after_its_replacement_is_deleted(): void {
		global $wpdb;

		$dry_run = $this->dry_run( array( self::table() ) );
		$live    = $this->execute( $dry_run );

		( new JobRepository( $wpdb ) )->delete( $live['id'] );

		$again = $this->request( 'POST', "/jobs/{$dry_run['id']}/execute", array( 'confirmed' => true ) );

		$this->assertSame( 'crq_relocate_already_executed', $again->get_data()['code'] );
		$this->assertFalse( $this->request( 'GET', "/jobs/{$dry_run['id']}" )->get_data()['executable'] );
	}

	public function test_only_one_live_job_runs_at_a_time(): void {
		$this->execute( $this->dry_run( array( self::table() ) ) );

		$second = $this->request( 'POST', '/jobs/' . $this->dry_run( array( self::table() ) )['id'] . '/execute', array( 'confirmed' => true ) );

		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'crq_relocate_job_running', $second->get_data()['code'] );
	}

	/**
	 * @param list<string> $tables
	 * @return array<string, mixed>
	 */
	private function dry_run( array $tables, string $search = self::OLD, string $replace = self::NEW ): array {
		$job = $this->request(
			'POST',
			'/jobs',
			array(
				'search'  => $search,
				'replace' => $replace,
				'tables'  => $tables,
			)
		)->get_data();

		$this->assertArrayHasKey( 'id', $job, (string) wp_json_encode( $job ) );

		return $this->finish( $job );
	}

	/**
	 * @param array<string, mixed> $dry_run
	 * @return array<string, mixed>
	 */
	private function execute( array $dry_run, bool $before_image = true ): array {
		$response = $this->request(
			'POST',
			"/jobs/{$dry_run['id']}/execute",
			array(
				'confirmed'    => true,
				'before_image' => $before_image,
			)
		);

		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		return $response->get_data();
	}

	/**
	 * Runs a job to the end, one window per request.
	 *
	 * @param array<string, mixed> $job
	 * @return array<string, mixed>
	 */
	private function finish( array $job ): array {
		while ( ! $job['finished'] ) {
			$job = $this->request( 'POST', "/jobs/{$job['id']}/run" )->get_data();
		}

		return $job;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function request( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/crq-relocate/v1' . $route );
		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<int, array{body: string|null, meta: string|null}>
	 */
	private function rows(): array {
		global $wpdb;

		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT id, body, meta FROM %i ORDER BY id', self::table() ), ARRAY_A ) as $row ) {
			$rows[ (int) $row['id'] ] = array(
				'body' => $row['body'],
				'meta' => $row['meta'],
			);
		}

		return $rows;
	}

	/**
	 * @param array<int, array{body: string|null, meta: string|null}> $rows
	 * @return array<int, array{body: string|null, meta: string|null}>
	 */
	private function expected( array $rows, Replacement $replacement ): array {
		$replacer = new Replacer( $replacement );

		foreach ( $rows as $id => $row ) {
			foreach ( $row as $column => $value ) {
				$rows[ $id ][ $column ] = null === $value ? null : $replacer->replace( $value )->value;
			}
		}

		return $rows;
	}

	private function raw_option( string $name ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
	}

	private static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'crq_fixture_live';
	}
}

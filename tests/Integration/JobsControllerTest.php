<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Tests\Integration;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class JobsControllerTest extends WP_UnitTestCase {

	private static int $admin_id;
	private static int $editor_id;

	public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ): void {
		self::$admin_id  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor_id = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function valid_body(): array {
		global $wpdb;

		return array(
			'search'  => 'Hello world',
			'replace' => 'Goodbye world',
			'tables'  => array( $wpdb->posts ),
		);
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

	public function test_logged_out_users_are_rejected(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( 'POST', '/jobs', $this->valid_body() )->get_status() );
	}

	public function test_users_without_the_capability_are_rejected(): void {
		wp_set_current_user( self::$editor_id );

		$this->assertSame( 403, $this->request( 'POST', '/jobs', $this->valid_body() )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/jobs/1/run' )->get_status() );
	}

	public function test_administrators_lose_access_when_unfiltered_html_is_disallowed(): void {
		wp_set_current_user( self::$admin_id );

		$deny = fn( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		add_filter( 'map_meta_cap', $deny, 5, 2 );

		$this->assertSame( 403, $this->request( 'POST', '/jobs', $this->valid_body() )->get_status() );
	}

	public function test_cookie_request_without_nonce_is_treated_as_logged_out(): void {
		global $wp_rest_auth_cookie;

		wp_set_current_user( self::$admin_id );
		$wp_rest_auth_cookie = true;
		unset( $_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE'] );

		rest_cookie_check_errors( null );
		$wp_rest_auth_cookie = null;

		$this->assertSame( 401, $this->request( 'POST', '/jobs', $this->valid_body() )->get_status() );
	}

	public function test_cookie_request_with_invalid_nonce_is_rejected(): void {
		global $wp_rest_auth_cookie;

		wp_set_current_user( self::$admin_id );
		$wp_rest_auth_cookie        = true;
		$_SERVER['HTTP_X_WP_NONCE'] = 'not-a-valid-nonce';

		$result = rest_cookie_check_errors( null );

		$wp_rest_auth_cookie = null;
		unset( $_SERVER['HTTP_X_WP_NONCE'] );

		$this->assertWPError( $result );
		$this->assertSame( 'rest_cookie_invalid_nonce', $result->get_error_code() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function invalid_bodies(): array {
		return array(
			'empty search'    => array( array( 'search' => '' ), 'crq_relocate_empty_search' ),
			'same values'     => array( array( 'replace' => 'Hello world' ), 'crq_relocate_same_values' ),
			'no tables'       => array( array( 'tables' => array() ), 'crq_relocate_no_tables' ),
			'unknown table'   => array( array( 'tables' => array( 'wptests_does_not_exist' ) ), 'crq_relocate_invalid_tables' ),
			'own jobs table'  => array( array( 'tables' => array( 'wptests_crq_relocate_jobs' ) ), 'crq_relocate_invalid_tables' ),
			'injection'       => array( array( 'tables' => array( 'wptests_posts`; DROP TABLE wptests_users; --' ) ), 'crq_relocate_invalid_tables' ),
			// WordPress turns a comma-separated string into a list; it still goes through the allowlist.
			'comma string'    => array( array( 'tables' => 'wptests_posts,wptests_nope' ), 'crq_relocate_invalid_tables' ),
			'tables not list' => array( array( 'tables' => array( array( 'nested' ) ) ), 'rest_invalid_param' ),
			'unknown column'  => array( array( 'exclude_columns' => array( 'wptests_posts' => array( 'no_such_column' ) ) ), 'crq_relocate_invalid_columns' ),
			'key column'      => array( array( 'exclude_columns' => array( 'wptests_posts' => array( 'ID' ) ) ), 'crq_relocate_invalid_columns' ),
			'other table'     => array( array( 'exclude_columns' => array( 'wptests_users' => array( 'user_url' ) ) ), 'crq_relocate_invalid_columns' ),
		);
	}

	/**
	 * @dataProvider invalid_bodies
	 *
	 * @param array<string, mixed> $overrides
	 */
	public function test_invalid_requests_are_rejected( array $overrides, string $code ): void {
		wp_set_current_user( self::$admin_id );

		$response = $this->request( 'POST', '/jobs', array_merge( $this->valid_body(), $overrides ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	public function test_several_pairs_in_one_dry_run(): void {
		wp_set_current_user( self::$admin_id );
		self::factory()->post->create( array( 'post_content' => 'Hello world from the old house' ) );

		$job = $this->request(
			'POST',
			'/jobs',
			array(
				'pairs'  => array(
					array(
						'search'  => 'Hello world',
						'replace' => 'Goodbye world',
					),
					array(
						'search'  => 'old house',
						'replace' => 'new house',
					),
				),
				'tables' => array( 'wptests_posts' ),
			)
		)->get_data();

		while ( ! $job['finished'] ) {
			$job = $this->request( 'POST', "/jobs/{$job['id']}/run" )->get_data();
		}

		$this->assertCount( 2, $job['pairs'] );
		$this->assertSame( 'old house', $job['pairs'][1]['search'] );
		$this->assertGreaterThanOrEqual( 2, $job['totals']['replacements'] );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: bool}>
	 */
	public static function pair_limits(): array {
		return array(
			'five is allowed'       => array( 5, 5, true ),
			'six is too many'       => array( 6, 5, false ),
			'a filter can raise it' => array( 6, 10, true ),
		);
	}

	/**
	 * @dataProvider pair_limits
	 */
	public function test_number_of_pairs_is_limited( int $pairs, int $limit, bool $allowed ): void {
		wp_set_current_user( self::$admin_id );
		add_filter( 'crq_relocate_max_pairs', fn() => $limit );

		$response = $this->request(
			'POST',
			'/jobs',
			array(
				'pairs'  => array_map(
					fn( int $i ): array => array(
						'search'  => "value {$i}",
						'replace' => "other {$i}",
					),
					range( 1, $pairs )
				),
				'tables' => array( 'wptests_posts' ),
			)
		);

		$this->assertSame( $allowed ? 201 : 400, $response->get_status() );
		if ( ! $allowed ) {
			$this->assertSame( 'crq_relocate_too_many_pairs', $response->get_data()['code'] );
		}
	}

	public function test_duplicate_pairs_are_rejected(): void {
		wp_set_current_user( self::$admin_id );

		$response = $this->request(
			'POST',
			'/jobs',
			array(
				'pairs'  => array(
					array(
						'search'  => 'same',
						'replace' => 'a',
					),
					array(
						'search'  => 'same',
						'replace' => 'b',
					),
				),
				'tables' => array( 'wptests_posts' ),
			)
		);

		$this->assertSame( 'crq_relocate_duplicate_search', $response->get_data()['code'] );
	}

	public function test_case_insensitive_duplicates_are_found_beyond_ascii(): void {
		wp_set_current_user( self::$admin_id );

		$response = $this->request(
			'POST',
			'/jobs',
			array(
				'pairs'          => array(
					array(
						'search'  => 'École',
						'replace' => 'a',
					),
					array(
						'search'  => 'école',
						'replace' => 'b',
					),
				),
				'case_sensitive' => false,
				'tables'         => array( 'wptests_posts' ),
			)
		);

		$this->assertSame( 'crq_relocate_duplicate_search', $response->get_data()['code'] );
	}

	public function test_unknown_job_is_not_found(): void {
		wp_set_current_user( self::$admin_id );

		$this->assertSame( 404, $this->request( 'POST', '/jobs/999999/run' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/jobs/999999' )->get_status() );
	}

	public function test_dry_run_from_start_to_finish(): void {
		wp_set_current_user( self::$admin_id );
		self::factory()->post->create_many( 3, array( 'post_content' => 'Hello world, Hello world!' ) );

		$created = $this->request( 'POST', '/jobs', $this->valid_body() );
		$this->assertSame( 201, $created->get_status() );

		$job = $created->get_data();
		$this->assertTrue( $job['dry_run'] );
		$this->assertSame( 'pending', $job['status'] );

		while ( ! $job['finished'] ) {
			$job = $this->request( 'POST', "/jobs/{$job['id']}/run" )->get_data();
		}

		$this->assertSame( 'completed', $job['status'] );
		$this->assertSame( 100, $job['progress'] );
		$this->assertGreaterThanOrEqual( 6, $job['totals']['replacements'] );
		$this->assertSame( 'wptests_posts', $job['report']['tables'][0]['name'] );
		$this->assertNotEmpty( $job['report']['samples'] );
		$this->assertStringContainsString( 'Goodbye world', $job['report']['samples'][0]['after'] );
	}

	public function test_cancel(): void {
		wp_set_current_user( self::$admin_id );

		$job = $this->request( 'POST', '/jobs', $this->valid_body() )->get_data();
		$job = $this->request( 'POST', "/jobs/{$job['id']}/cancel" )->get_data();

		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertTrue( $job['finished'] );
	}
}

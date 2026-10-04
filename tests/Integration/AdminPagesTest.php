<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Tests\Integration;

use CraftRoq\Relocate\Admin\Admin;
use CraftRoq\Relocate\Admin\ImportExport;
use CraftRoq\Relocate\Transfer\TransferRepository;
use CraftRoq\Relocate\Database\Schema;
use CraftRoq\Relocate\Jobs\BeforeImage;
use CraftRoq\Relocate\Jobs\Job;
use CraftRoq\Relocate\Jobs\JobRepository;
use CraftRoq\Relocate\Jobs\JobStatus;
use CraftRoq\Relocate\Jobs\Report;
use CraftRoq\Relocate\Logger;
use CraftRoq\Relocate\Rest\JobFormatter;
use CraftRoq\Relocate\Settings;
use WP_UnitTestCase;

/**
 * Renders every screen as an administrator. Any PHP notice, warning or
 * deprecation fails the test, which is the main point.
 */
final class AdminPagesTest extends WP_UnitTestCase {

	private static int $admin_id;

	public static function wpSetUpBeforeClass( \WP_UnitTest_Factory $factory ): void {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::$admin_id );
		set_current_screen( 'tools_page_' . Admin::PAGE );
	}

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	public function test_dashboard(): void {
		$failed = $this->job( false, JobStatus::Failed );

		$html = $this->render( array( 'tab' => 'dashboard' ) );

		$this->assertStringContainsString( 'Needs attention', $html );
		$this->assertStringContainsString( 'Replacement #' . $failed->id, $html );
		$this->assertStringContainsString( 'Search and replace across your database', $html );
		$this->assertStringContainsString( 'aria-current="page"', $html );
	}

	public function test_dashboard_without_any_jobs(): void {
		$html = $this->render( array() );

		$this->assertStringContainsString( 'Move a site safely in three steps', $html, 'New users get the three-step introduction.' );
	}

	public function test_introduction_is_hidden_once_there_are_jobs(): void {
		$this->job( true );

		$this->assertStringNotContainsString( 'Move a site safely in three steps', $this->render( array() ) );
	}

	public function test_quick_form_prefills_search_and_replace(): void {
		$_POST = array(
			'_wpnonce' => wp_create_nonce( 'crq_relocate_quick' ),
			'search'   => 'https://old.test/"quoted"',
			'replace'  => 'https://new.test',
		);

		$html = $this->render( array( 'tab' => 'search-replace' ) );

		$this->assertStringContainsString( 'value="https://old.test/&quot;quoted&quot;"', $html );
		$this->assertStringContainsString( 'value="https://new.test"', $html );
		$this->assertStringContainsString( 'data-start-step="2"', $html, 'Coming from the dashboard skips to step 2.' );
	}

	public function test_quick_form_values_are_ignored_without_a_valid_nonce(): void {
		$_POST = array(
			'_wpnonce' => 'invalid',
			'search'   => 'https://old.test',
		);

		$this->assertStringNotContainsString( 'value="https://old.test"', $this->render( array( 'tab' => 'search-replace' ) ) );
	}

	public function test_search_replace_lists_tables_but_not_the_plugins_own(): void {
		$html = $this->render( array( 'tab' => 'search-replace' ) );

		$this->assertStringContainsString( 'value="wptests_posts"', $html );
		$this->assertStringNotContainsString( 'value="wptests_crq_relocate_jobs"', $html );
		$this->assertStringContainsString( 'id="crq-confirm"', $html );
		$this->assertStringContainsString( 'id="crq-steps"', $html );
		$this->assertStringContainsString( 'name="scope" value="core" checked', $html, '"All WordPress tables" is the default.' );
		$this->assertStringContainsString( 'data-start-step="1"', $html );
	}

	public function test_history_list_and_type_filter(): void {
		$dry  = $this->job( true );
		$live = $this->job( false );

		$all      = $this->render( array( 'tab' => 'history' ) );
		$dry_only = $this->render(
			array(
				'tab'  => 'history',
				'type' => 'dry',
			)
		);

		$this->assertStringContainsString( 'Dry run #' . $dry->id, $all );
		$this->assertStringContainsString( 'Replacement #' . $live->id, $all );
		$this->assertStringContainsString( 'Dry run #' . $dry->id, $dry_only );
		$this->assertStringNotContainsString( 'Replacement #' . $live->id, $dry_only );
	}

	public function test_log_view(): void {
		global $wpdb;
		( new Logger( $wpdb ) )->warning( 'Something odd.', array( 'table' => 'wptests_posts' ) );

		$html = $this->render(
			array(
				'tab'  => 'history',
				'view' => 'log',
			)
		);

		$this->assertStringContainsString( 'Something odd.', $html );
		$this->assertStringContainsString( '&quot;table&quot;: &quot;wptests_posts&quot;', $html );
	}

	public function test_job_page_embeds_the_job_for_the_script(): void {
		$job = $this->job( true );

		$html = $this->render(
			array(
				'tab' => 'history',
				'job' => $job->id,
			)
		);

		$this->assertStringContainsString( 'Dry run #' . $job->id, $html );
		$this->assertMatchesRegularExpression( '/data-job="\{[^"]*&quot;id&quot;:' . $job->id . ',/', $html );
		$this->assertStringContainsString( '<code>&lt;old&gt;</code>', $html, 'Search values are escaped.' );
	}

	public function test_missing_job_shows_an_error(): void {
		$html = $this->render(
			array(
				'tab' => 'history',
				'job' => 999999,
			)
		);

		$this->assertStringContainsString( 'That job does not exist.', $html );
	}

	public function test_history_search_and_sort(): void {
		$alpha = $this->job( true, JobStatus::Completed, 'alpha-domain.test' );
		$beta  = $this->job( true, JobStatus::Completed, 'beta-domain.test' );

		$found = $this->render(
			array(
				'tab' => 'history',
				's'   => 'alpha',
			)
		);

		$this->assertStringContainsString( 'Dry run #' . $alpha->id, $found );
		$this->assertStringNotContainsString( 'Dry run #' . $beta->id, $found );

		$oldest_first = $this->render(
			array(
				'tab'     => 'history',
				'orderby' => 'id',
				'order'   => 'asc',
			)
		);

		$this->assertLessThan( strpos( $oldest_first, 'Dry run #' . $beta->id ), strpos( $oldest_first, 'Dry run #' . $alpha->id ) );
		$this->assertStringContainsString( 'name="job_ids[]"', $oldest_first, 'Finished jobs can be selected for deletion.' );
	}

	public function test_running_jobs_cannot_be_deleted(): void {
		$running = $this->job( false, JobStatus::Running );

		$html = $this->render( array( 'tab' => 'history' ) );

		$this->assertStringNotContainsString( 'value="' . $running->id . '"', $html );
	}

	public function test_database_list_search_and_sort(): void {
		$search = $this->render(
			array(
				'tab'  => 'settings',
				'view' => 'database',
				's'    => 'post',
			)
		);

		$this->assertStringContainsString( '<code>wptests_posts</code>', $search );
		$this->assertStringContainsString( '<code>wptests_postmeta</code>', $search );
		$this->assertStringNotContainsString( '<code>wptests_users</code>', $search );

		$sorted = $this->render(
			array(
				'tab'     => 'settings',
				'view'    => 'database',
				'orderby' => 'name',
				'order'   => 'desc',
			)
		);

		$this->assertLessThan( strpos( $sorted, '<code>wptests_comments</code>' ), strpos( $sorted, '<code>wptests_users</code>' ) );
	}

	public function test_picker_lists_text_columns_but_not_primary_keys(): void {
		$html = $this->render( array( 'tab' => 'search-replace' ) );

		$this->assertStringContainsString( 'name="columns[wptests_posts][]" value="post_content"', $html );
		$this->assertStringNotContainsString( 'name="columns[wptests_posts][]" value="ID"', $html );
	}

	public function test_database_and_settings(): void {
		$this->assertStringContainsString( 'action="options.php"', $this->render( array( 'tab' => 'settings' ) ) );
		$this->assertStringContainsString(
			'class="crq-inline-facts"',
			$this->render(
				array(
					'tab'  => 'settings',
					'view' => 'database',
				)
			)
		);
		$this->assertStringContainsString(
			'Ready to run replacements',
			$this->render(
				array(
					'tab'  => 'settings',
					'view' => 'status',
				)
			)
		);
	}

	/**
	 * @param array<string, string|int> $query
	 */
	private function render( array $query ): string {
		global $wpdb;

		// Each section is its own submenu page: cr-relocate-db-<section>.
		$section = $query['tab'] ?? 'dashboard';
		unset( $query['tab'] );
		$_GET = array( 'page' => 'dashboard' === $section ? Admin::PAGE : Admin::PAGE . '-' . $section ) + array_map( 'strval', $query );

		$jobs   = new JobRepository( $wpdb );
		$schema = new Schema( $wpdb );
		$images = new BeforeImage( $wpdb );
		$admin  = new Admin(
			dirname( __DIR__, 2 ) . '/cr-relocate-db.php',
			$schema,
			$jobs,
			$images,
			new JobFormatter( $wpdb, $jobs, $schema, $images ),
			new Logger( $wpdb ),
			new Settings(),
			new ImportExport( new TransferRepository( $wpdb ), $schema, new Settings() )
		);

		ob_start();
		$admin->render_page();
		return (string) ob_get_clean();
	}

	private function job( bool $dry_run, JobStatus $status = JobStatus::Completed, string $search = '<old>' ): Job {
		global $wpdb;

		return ( new JobRepository( $wpdb ) )->create(
			new Job(
				0,
				null,
				$dry_run,
				$status,
				$search,
				'new',
				array(
					'case_sensitive' => true,
					'whole_words'    => false,
					'url_variants'   => false,
					'skip_guids'     => true,
					'tables'         => array( $wpdb->posts ),
				),
				array(
					'table_index' => 1,
					'last_key'    => null,
					'total_rows'  => 0,
				),
				new Report(),
				self::$admin_id,
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}
}

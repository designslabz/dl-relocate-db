<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Tests\Integration;

use CraftRoq\Relocate\Installer;
use CraftRoq\Relocate\Jobs\BeforeImage;
use CraftRoq\Relocate\Jobs\Cleanup;
use CraftRoq\Relocate\Jobs\Job;
use CraftRoq\Relocate\Jobs\JobRepository;
use CraftRoq\Relocate\Jobs\JobStatus;
use CraftRoq\Relocate\Jobs\Report;
use CraftRoq\Relocate\Logger;
use CraftRoq\Relocate\Settings;
use CraftRoq\Relocate\Storage;
use CraftRoq\Relocate\Transfer\TransferRepository;
use WP_UnitTestCase;

final class HistoryTest extends WP_UnitTestCase {

	private JobRepository $jobs;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->jobs = new JobRepository( $wpdb );
	}

	public function tear_down(): void {
		global $wpdb;
		Storage::delete_all();

		parent::tear_down();
	}

	public function test_pages_are_newest_first_and_filter_by_type(): void {
		foreach ( range( 1, 25 ) as $i ) {
			$this->job( 0 === $i % 5 ? false : true );
		}

		[ $first, $total ] = $this->jobs->page( 1, 10 );
		[ $third ]         = $this->jobs->page( 3, 10 );
		[ $live, $lives ]  = $this->jobs->page( 1, 10, false );

		$this->assertSame( 25, $total );
		$this->assertCount( 10, $first );
		$this->assertCount( 5, $third );
		$this->assertGreaterThan( $first[1]->id, $first[0]->id );
		$this->assertSame( 5, $lives );
		$this->assertCount( 5, $live );
		$this->assertFalse( $live[0]->dry_run );
	}

	public function test_pages_can_be_searched_and_sorted(): void {
		global $wpdb;

		$small = $this->job( true, JobStatus::Completed, 'one.test' );
		$big   = $this->job( true, JobStatus::Completed, 'two.test' );
		$wpdb->update( $wpdb->prefix . Installer::JOBS_TABLE, array( 'replacements' => 5 ), array( 'id' => $small->id ) );
		$wpdb->update( $wpdb->prefix . Installer::JOBS_TABLE, array( 'replacements' => 50 ), array( 'id' => $big->id ) );

		[ $found, $total ] = $this->jobs->page( 1, 10, null, 'id', 'desc', 'two.' );
		[ $by_count ]      = $this->jobs->page( 1, 10, null, 'replacements', 'desc' );
		[ $unsafe ]        = $this->jobs->page( 1, 10, null, 'id; DROP TABLE x', 'sideways' );

		$this->assertSame( 1, $total );
		$this->assertSame( $big->id, $found[0]->id );
		$this->assertSame( $big->id, $by_count[0]->id );
		$this->assertCount( 2, $unsafe, 'Unknown sort columns fall back to id.' );
	}

	public function test_delete_removes_the_job_and_its_log(): void {
		global $wpdb;

		$logger = new Logger( $wpdb );
		$job    = $this->job( true );
		$other  = $this->job( true );
		$logger->info( 'Mine.', array(), $job->id );
		$logger->info( 'Not mine.', array(), $other->id );

		$this->jobs->delete( $job->id );
		$logger->delete_for_job( $job->id );

		$this->assertNull( $this->jobs->find( $job->id ) );
		$this->assertNotNull( $this->jobs->find( $other->id ) );
		$this->assertSame( 0, $logger->entries( 1, 10, $job->id )[1] );
		$this->assertSame( 1, $logger->entries( 1, 10, $other->id )[1] );
	}

	public function test_log_search(): void {
		global $wpdb;

		$logger = new Logger( $wpdb );
		$logger->warning( 'Value left unchanged.', array( 'table' => 'wptests_special' ) );
		$logger->info( 'Job completed.' );

		$this->assertSame( array( 'Value left unchanged.' ), array_column( $logger->entries( 1, 10, null, null, 'wptests_special' )[0], 'message' ) );
	}

	public function test_stats(): void {
		global $wpdb;

		$live = $this->job( false );
		$this->job( true );
		$wpdb->update( $wpdb->prefix . Installer::JOBS_TABLE, array( 'replacements' => 7 ), array( 'id' => $live->id ) );

		$this->assertSame(
			array(
				'jobs'              => 2,
				'replacements_run'  => 1,
				'replacements_made' => 7,
			),
			$this->jobs->stats()
		);
	}

	public function test_a_quiet_unfinished_job_is_interrupted(): void {
		$running = $this->job( true, JobStatus::Running );
		$stale   = $this->job( true, JobStatus::Running );
		$done    = $this->job( true, JobStatus::Completed );
		$failed  = $this->job( false, JobStatus::Failed );
		$this->age( $stale, 120 );
		$this->age( $done, 120 );

		$this->assertFalse( $this->jobs->find( $running->id )->is_interrupted() );
		$this->assertTrue( $this->jobs->find( $stale->id )->is_interrupted() );
		$this->assertFalse( $this->jobs->find( $done->id )->is_interrupted() );

		$attention = array_map( fn( Job $job ): int => $job->id, $this->jobs->needing_attention() );
		sort( $attention );

		$this->assertSame( array( $stale->id, $failed->id ), $attention );
	}

	public function test_cleanup_removes_old_jobs_their_files_and_log_entries(): void {
		global $wpdb;

		$images = new BeforeImage( $wpdb );
		$logger = new Logger( $wpdb );

		$old               = $this->job( false, JobStatus::Completed );
		$old->before_image = $images->create( $old );
		$this->jobs->save( $old );
		$this->age( $old, 40 * DAY_IN_SECONDS );

		$old_unfinished = $this->job( false, JobStatus::Running );
		$this->age( $old_unfinished, 40 * DAY_IN_SECONDS );

		$abandoned_dry_run = $this->job( true, JobStatus::Running );
		$this->age( $abandoned_dry_run, 40 * DAY_IN_SECONDS );

		$recent = $this->job( true, JobStatus::Completed );

		$logger->info( 'Old entry.' );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET created_at = %s', $wpdb->prefix . Installer::LOG_TABLE, gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );
		$logger->info( 'New entry.' );

		$path = $images->path( $old->before_image );
		$this->assertFileExists( $path );

		$this->cleanup()->run();

		$this->assertNull( $this->jobs->find( $old->id ) );
		$this->assertFileDoesNotExist( $path );
		$this->assertNotNull( $this->jobs->find( $old_unfinished->id ), 'Unfinished replacements are never deleted.' );
		$this->assertNull( $this->jobs->find( $abandoned_dry_run->id ), 'Abandoned dry runs changed nothing, so they expire.' );
		$this->assertNotNull( $this->jobs->find( $recent->id ) );
		$this->assertSame( array( 'New entry.' ), array_column( $logger->entries( 1, 10 )[0], 'message' ) );
	}

	public function test_cleanup_does_nothing_when_retention_is_off(): void {
		update_option( Settings::OPTION, array( 'retention_days' => 0 ) );

		$old = $this->job( true, JobStatus::Completed );
		$this->age( $old, 400 * DAY_IN_SECONDS );

		$this->cleanup()->run();

		$this->assertNotNull( $this->jobs->find( $old->id ) );
	}

	public function test_cleanup_is_scheduled_daily(): void {
		Cleanup::unschedule();
		$this->cleanup()->schedule();

		$this->assertSame( 'daily', wp_get_schedule( Cleanup::HOOK ) );
	}

	private function cleanup(): Cleanup {
		global $wpdb;

		return new Cleanup( $this->jobs, new BeforeImage( $wpdb ), new Logger( $wpdb ), new Settings(), new TransferRepository( $wpdb ) );
	}

	private function job( bool $dry_run, JobStatus $status = JobStatus::Completed, string $search = 'old' ): Job {
		$job = $this->jobs->create(
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
					'tables'         => array( 'wptests_posts' ),
				),
				array(
					'table_index' => 0,
					'last_key'    => null,
					'total_rows'  => 0,
				),
				new Report(),
				1,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return $job;
	}

	private function age( Job $job, int $seconds ): void {
		global $wpdb;

		$wpdb->update( $wpdb->prefix . Installer::JOBS_TABLE, array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ), array( 'id' => $job->id ) );
	}
}

<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Jobs;

use CraftRoq\Relocate\Database\Lock;
use CraftRoq\Relocate\Database\Schema;
use CraftRoq\Relocate\Database\TableLayout;
use CraftRoq\Relocate\Installer;
use CraftRoq\Relocate\Logger;
use CraftRoq\Relocate\Replace\Replacer;
use CraftRoq\Relocate\Settings;
use RuntimeException;

/**
 * Advances a job one step at a time.
 *
 * A step works through windows of rows until it runs out of time or memory,
 * saving the job's position after every window. Whoever calls it (the admin
 * screen over REST, later WP-CLI) just keeps calling until the job finishes,
 * and an interrupted job carries on from its last saved window.
 *
 * For a live job each window is one transaction: the rows are read with
 * FOR UPDATE, their original values go to the before-image file, the new
 * values are written and the job's position is saved, then it commits. If
 * anything fails the window rolls back as a whole, so resuming never applies
 * a window twice.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading and writing the database directly is what this plugin is for, and results must never come from a cache.
 */
final class JobRunner {

	// Short enough for the progress bar to move steadily, long enough that request overhead stays small.
	private const STEP_SECONDS = 4.0;

	// Changing these moves the login cookie's name and path, so they are changed last.
	private const SITE_ADDRESS_OPTIONS = array( 'siteurl', 'home' );

	// Enough to diagnose a problem without flooding the log on a large site.
	private const MAX_LOGGED_SKIPS = 50;

	/** @var array<string, TableLayout|null> */
	private array $layouts = array();

	public function __construct(
		private \wpdb $wpdb,
		private Schema $schema,
		private JobRepository $jobs,
		private Settings $settings,
		private Logger $logger,
		private BeforeImage $before_images
	) {}

	/**
	 * @return Job|null The job as saved after the step, or null when another request is processing it.
	 * @throws RuntimeException When the job cannot be saved.
	 */
	public function step( Job $job ): ?Job {
		$id = $job->id;

		if ( ! $this->lock( $this->lock_name( $id ), 0 ) ) {
			return null;
		}

		try {
			// Re-read under the lock: another request may have moved the job on.
			$job = $this->jobs->find( $id );

			if ( null === $job || $job->status->is_finished() ) {
				return $job;
			}

			$this->run( $job );

			return $job;
		} finally {
			$this->unlock( $this->lock_name( $id ) );
		}
	}

	/**
	 * @return Job|null The cancelled job, or null when it is still locked by a running step.
	 * @throws RuntimeException When the job cannot be saved.
	 */
	public function cancel( Job $job ): ?Job {
		// Wait for a step in progress to finish rather than overwrite what it saves.
		$id = $job->id;

		if ( ! $this->lock( $this->lock_name( $id ), (int) ceil( self::step_seconds() ) + 5 ) ) {
			return null;
		}

		try {
			$job = $this->jobs->find( $id );

			if ( null !== $job && ! $job->status->is_finished() ) {
				$job->finish( JobStatus::Cancelled );
				$this->jobs->save( $job );
				$this->logger->info( 'Job cancelled.', array(), $job->id );
				$this->flush_cache( $job );
			}

			return $job;
		} finally {
			$this->unlock( $this->lock_name( $id ) );
		}
	}

	/**
	 * Puts a failed job back to running so the next step carries on from its
	 * last committed window. Anything else is returned unchanged.
	 *
	 * @return Job|null Null when a step is processing the job right now.
	 * @throws RuntimeException When the job cannot be saved.
	 */
	public function resume( Job $job ): ?Job {
		$id = $job->id;

		if ( ! $this->lock( $this->lock_name( $id ), 0 ) ) {
			return null;
		}

		try {
			$job = $this->jobs->find( $id );

			if ( null !== $job && JobStatus::Failed === $job->status ) {
				$job->status        = JobStatus::Running;
				$job->error_message = null;
				$job->finished_at   = null;
				$this->jobs->save( $job );
				$this->logger->info( 'Job resumed.', array(), $job->id );
			}

			return $job;
		} finally {
			$this->unlock( $this->lock_name( $id ) );
		}
	}

	/**
	 * Runs $callback while holding a named lock, so two requests cannot both
	 * pass the same checks before either has acted on them.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T|null Null when the lock could not be taken within a few seconds.
	 */
	public function exclusive( string $name, callable $callback ): mixed {
		$lock = $this->wpdb->prefix . Installer::JOBS_TABLE . ':' . $name;

		if ( ! $this->lock( $lock, 5 ) ) {
			return null;
		}

		try {
			return $callback();
		} finally {
			$this->unlock( $lock );
		}
	}

	private function run( Job $job ): void {
		if ( JobStatus::Pending === $job->status ) {
			$job->status     = JobStatus::Running;
			$job->started_at = gmdate( 'Y-m-d H:i:s' );
		}

		$replacement = $job->replacement();
		$batch       = new TableBatch( $this->wpdb, $replacement, new Replacer( $replacement ), $this->settings->batch_size(), ! $job->dry_run );
		$deadline    = microtime( true ) + self::step_seconds();

		try {
			while ( null !== $job->current_table() ) {
				$this->process_window( $job, $batch, $job->current_table() );

				if ( microtime( true ) >= $deadline || self::memory_is_low() ) {
					$this->jobs->save( $job );
					return;
				}
			}

			$this->update_site_address( $job, $batch );

			$job->finish( JobStatus::Completed );
			$this->jobs->save( $job );
			$this->logger->info( 'Job completed.', $job->report->totals(), $job->id );
			$this->flush_cache( $job );
		} catch ( RuntimeException $e ) {
			// Forget whatever the failed window did to the job in memory: its changes were rolled back.
			$saved = $this->jobs->find( $job->id );
			if ( $saved ) {
				$job->state  = $saved->state;
				$job->report = $saved->report;
			}

			$job->finish(
				JobStatus::Failed,
				/* translators: 1: table name, 2: database error message. */
				sprintf( __( 'The job stopped while processing %1$s: %2$s', 'cr-relocate-db' ), (string) $job->current_table(), $e->getMessage() )
			);
			$this->jobs->save( $job );
			$this->logger->error(
				'Job failed.',
				array(
					'table' => $job->current_table(),
					'error' => $e->getMessage(),
				),
				$job->id
			);
			$this->flush_cache( $job );
		}
	}

	private function process_window( Job $job, TableBatch $batch, string $table ): void {
		$layout = $this->layout( $job, $table );

		if ( null === $layout ) {
			$this->next_table( $job );
			$this->jobs->save( $job );
			return;
		}

		if ( ! $job->dry_run && $this->wpdb->options === $table ) {
			$layout = $layout->except( 'option_name', self::SITE_ADDRESS_OPTIONS );
		}

		$this->in_transaction(
			$job,
			function () use ( $job, $batch, $layout, $table ): void {
				$result = $batch->scan( $layout, $job->state['last_key'] );

				$this->write( $job, $batch, $layout, $result );
				$this->log_skipped( $job, $table, $result );
				$job->report->add( $table, $result );

				if ( null === $result->last_key ) {
					$this->next_table( $job );
				} else {
					$job->state['last_key'] = $result->last_key;
				}

				$this->jobs->save( $job );
			}
		);
	}

	/**
	 * The siteurl and home options, held back from the options table until
	 * everything else is done. Dry runs count them in the normal pass.
	 */
	private function update_site_address( Job $job, TableBatch $batch ): void {
		$options = $this->wpdb->options;

		if ( $job->dry_run || ! empty( $job->state['site_address_done'] ) || ! in_array( $options, $job->settings['tables'], true ) ) {
			return;
		}

		$layout = $this->layout( $job, $options );

		$this->in_transaction(
			$job,
			function () use ( $job, $batch, $layout, $options ): void {
				if ( $layout ) {
					$result = $batch->scan( $layout->only( 'option_name', self::SITE_ADDRESS_OPTIONS ), null );

					$this->write( $job, $batch, $layout, $result );
					$job->report->add( $options, $result );
					$job->state['site_address_changed'] = (bool) $result->rows;
				}

				$job->state['site_address_done'] = true;
				$this->jobs->save( $job );
			}
		);
	}

	/**
	 * Saves the original values, then writes the new ones. Dry runs write nothing.
	 */
	private function write( Job $job, TableBatch $batch, TableLayout $layout, BatchResult $result ): void {
		if ( $job->dry_run || ! $result->rows ) {
			return;
		}

		if ( '' !== $job->before_image ) {
			$this->before_images->append( $job->before_image, $layout, $result->rows );
		}

		$batch->apply( $layout, $result->rows );
	}

	/**
	 * @param callable(): void $work
	 * @throws RuntimeException When the work or the commit fails; the transaction is rolled back.
	 */
	private function in_transaction( Job $job, callable $work ): void {
		if ( $job->dry_run ) {
			$work();
			return;
		}

		$wpdb = $this->wpdb;

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new RuntimeException( $wpdb->last_error );
		}

		try {
			$work();

			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( $wpdb->last_error );
			}
		} catch ( RuntimeException $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Rows were changed behind the object cache's back, so anything it holds may be stale.
	 */
	private function flush_cache( Job $job ): void {
		/**
		 * Filters whether to flush the whole object cache after a replacement.
		 *
		 * A persistent cache shared with other sites is flushed for all of them.
		 * Return false there, and clear this site's cache some other way.
		 *
		 * @param bool $flush Default true.
		 * @param Job  $job   The replacement that has just stopped.
		 */
		if ( ! $job->dry_run && apply_filters( 'crq_relocate_flush_object_cache', true, $job ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * The table's layout, or null (with a note on the report) when it cannot be processed.
	 */
	private function layout( Job $job, string $table ): ?TableLayout {
		if ( ! array_key_exists( $table, $this->layouts ) ) {
			$layout = $this->schema->describe( $table );

			if ( $layout && $job->settings['skip_guids'] && $this->wpdb->posts === $table ) {
				$layout = $layout->without( 'guid' );
			}

			foreach ( $layout ? $job->settings['exclude_columns'][ $table ] ?? array() : array() as $column ) {
				$layout = $layout->without( $column );
			}

			$this->layouts[ $table ] = $layout;
		}

		$layout = $this->layouts[ $table ];
		$note   = match ( true ) {
			null === $layout    => Report::NOTE_MISSING_TABLE,
			! $layout->key      => Report::NOTE_NO_KEY,
			! $layout->columns  => Report::NOTE_NO_COLUMNS,
			default             => null,
		};

		if ( null !== $note ) {
			$job->report->note( $table, $note );
			return null;
		}

		return $layout;
	}

	private function next_table( Job $job ): void {
		++$job->state['table_index'];
		$job->state['last_key'] = null;
	}

	private function log_skipped( Job $job, string $table, BatchResult $result ): void {
		$already = $job->report->totals()['skipped'];

		foreach ( array_slice( $result->skipped, 0, max( 0, self::MAX_LOGGED_SKIPS - $already ) ) as $skipped ) {
			$this->logger->warning(
				'Value left unchanged.',
				array(
					'table'  => $table,
					'column' => $skipped['column'],
					'key'    => $skipped['key'],
					'reason' => $skipped['reason'],
				),
				$job->id
			);
		}
	}

	public static function step_seconds(): float {
		/**
		 * Filters how long one step may work before it saves and returns.
		 *
		 * Lower it on hosts whose proxy or PHP time limits are strict. Each step
		 * always finishes at least one window, so 0 means one window per request.
		 *
		 * @param float $seconds Default 4.
		 */
		$seconds = (float) apply_filters( 'crq_relocate_step_seconds', self::STEP_SECONDS );
		$limit   = (int) ini_get( 'max_execution_time' );

		return $limit > 0 ? min( $seconds, $limit / 2 ) : $seconds;
	}

	public static function memory_is_low(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		return $limit > 0 && memory_get_usage() > $limit * 0.6;
	}

	/**
	 * A named database lock per job, so a crashed step never leaves a job stuck.
	 */
	private function lock( string $name, int $timeout ): bool {
		return ( new Lock( $this->wpdb ) )->acquire( $name, $timeout );
	}

	private function unlock( string $name ): void {
		( new Lock( $this->wpdb ) )->release( $name );
	}

	private function lock_name( int $job_id ): string {
		return $this->wpdb->prefix . Installer::JOBS_TABLE . ':' . $job_id;
	}
}

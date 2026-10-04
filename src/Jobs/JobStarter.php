<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Jobs;

use CraftRoq\Relocate\Database\Schema;
use CraftRoq\Relocate\Logger;
use CraftRoq\Relocate\Replace\Replacement;
use InvalidArgumentException;
use RuntimeException;

/**
 * Validates and creates jobs, for the REST API and WP-CLI alike.
 *
 * A live replacement can only be started from a completed dry run, and copies
 * everything from it, so what runs is exactly what was previewed.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 */
final class JobStarter {

	/** How many search and replacement pairs one job may have, unless filtered. */
	public const MAX_PAIRS = 5;

	public function __construct(
		private JobRepository $jobs,
		private JobRunner $runner,
		private Schema $schema,
		private BeforeImage $before_images,
		private Logger $logger
	) {}

	/**
	 * The most search and replacement pairs one job may have.
	 */
	public static function max_pairs(): int {
		/**
		 * Filters how many search and replacement pairs one job may have.
		 *
		 * @param int $max Default 5.
		 */
		return max( 1, (int) apply_filters( 'crq_relocate_max_pairs', self::MAX_PAIRS ) );
	}

	/**
	 * @param list<array{0: string, 1: string}>                                                    $pairs  Search and replacement values.
	 * @param array{case_sensitive: bool, whole_words: bool, url_variants: bool, skip_guids: bool} $options
	 * @param list<string>                                                                         $tables
	 * @param array<string, list<string>>                                                          $exclude_columns Table => columns to leave out.
	 * @throws JobException When the request is invalid or the job cannot be saved.
	 */
	public function dry_run( array $pairs, array $options, array $tables, array $exclude_columns = array() ): Job {
		self::validate_pairs( $pairs, $options['case_sensitive'] );

		try {
			new Replacement( $pairs, $options['case_sensitive'], $options['whole_words'], $options['url_variants'] );
		} catch ( InvalidArgumentException ) {
			throw new JobException( 'crq_relocate_invalid_values', __( 'The search and replacement values must be valid UTF-8 text.', 'cr-relocate-db' ) );
		}

		if ( ! $tables ) {
			throw new JobException( 'crq_relocate_no_tables', __( 'Select at least one table to search.', 'cr-relocate-db' ) );
		}

		try {
			$available = $this->schema->searchable_tables();
		} catch ( RuntimeException $e ) {
			throw new JobException( 'crq_relocate_database_error', $e->getMessage(), 500 );
		}

		$unknown = array_diff( $tables, array_keys( $available ) );

		if ( $unknown ) {
			throw new JobException(
				'crq_relocate_invalid_tables',
				/* translators: %s: comma-separated table names. */
				sprintf( __( 'These tables do not exist or cannot be searched: %s', 'cr-relocate-db' ), implode( ', ', $unknown ) )
			);
		}

		// Keep the database's own order, so jobs always walk tables the same way.
		$tables = array_values( array_intersect( array_keys( $available ), $tables ) );

		$exclude_columns = $this->validate_columns( $exclude_columns, $tables );

		$job = $this->create(
			new Job(
				0,
				null,
				true,
				JobStatus::Pending,
				$pairs[0][0],
				$pairs[0][1],
				$options + array(
					'tables'          => $tables,
					'exclude_columns' => $exclude_columns,
					'pairs'           => $pairs,
				),
				array(
					'table_index' => 0,
					'last_key'    => null,
					'total_rows'  => array_sum( array_map( fn( string $name ): int => $available[ $name ]->approx_rows, $tables ) ),
				),
				new Report(),
				get_current_user_id(),
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		$this->logger->info( 'Dry run created.', array( 'tables' => count( $tables ) ), $job->id );

		return $job;
	}

	/**
	 * @throws JobException When the dry run cannot be applied, or the job cannot be saved.
	 */
	public function replacement( Job $dry_run, bool $before_image ): Job {
		if ( ! $dry_run->dry_run || JobStatus::Completed !== $dry_run->status ) {
			throw new JobException( 'crq_relocate_not_executable', __( 'Only a completed dry run can be applied to the database.', 'cr-relocate-db' ) );
		}

		if ( 0 === $dry_run->report->totals()['rows_changed'] ) {
			throw new JobException( 'crq_relocate_nothing_to_replace', __( 'The dry run found nothing to replace.', 'cr-relocate-db' ) );
		}

		// Held while checking and creating, so two requests cannot both get past the checks.
		$job = $this->runner->exclusive(
			'execute',
			function () use ( $dry_run, $before_image ): Job {
				if ( $this->jobs->was_applied( $dry_run ) ) {
					throw new JobException( 'crq_relocate_already_executed', __( 'This dry run has already been applied. Run a new dry run to replace again.', 'cr-relocate-db' ), 409 );
				}

				if ( $this->jobs->active_live_job() ) {
					throw new JobException( 'crq_relocate_job_running', __( 'Another replacement is still running. Wait for it to finish or cancel it first.', 'cr-relocate-db' ), 409 );
				}

				$job = $this->create(
					new Job(
						0,
						$dry_run->id,
						false,
						JobStatus::Pending,
						$dry_run->search,
						$dry_run->replace,
						array( 'before_image' => $before_image ) + $dry_run->settings,
						array(
							'table_index' => 0,
							'last_key'    => null,
							'total_rows'  => $dry_run->report->totals()['rows_scanned'],
						),
						new Report(),
						get_current_user_id(),
						gmdate( 'Y-m-d H:i:s' )
					)
				);

				$this->mark_applied( $dry_run );

				return $job;
			}
		);

		if ( null === $job ) {
			throw new JobException( 'crq_relocate_job_busy', __( 'Another replacement is being started. Try again in a few seconds.', 'cr-relocate-db' ), 409 );
		}

		if ( $before_image ) {
			$this->create_before_image( $job );
		}

		$this->logger->info(
			'Replacement started.',
			array(
				'dry_run'      => $dry_run->id,
				'before_image' => $before_image,
			),
			$job->id
		);

		return $job;
	}

	/**
	 * Checked before building a Replacement, so each problem gets its own
	 * translated message. Exports use it for their optional pairs too.
	 *
	 * @param list<array{0: string, 1: string}> $pairs
	 * @throws JobException When a pair cannot be used.
	 */
	public static function validate_pairs( array $pairs, bool $case_sensitive ): void {
		if ( ! $pairs ) {
			throw new JobException( 'crq_relocate_empty_search', __( 'Enter the text or URL to search for.', 'cr-relocate-db' ) );
		}

		if ( count( $pairs ) > self::max_pairs() ) {
			throw new JobException(
				'crq_relocate_too_many_pairs',
				/* translators: %d: maximum number of pairs. */
				sprintf( _n( 'You can search for up to %d value at a time.', 'You can search for up to %d values at a time.', self::max_pairs(), 'cr-relocate-db' ), self::max_pairs() )
			);
		}

		$seen = array();

		foreach ( $pairs as $index => [ $search, $replace ] ) {
			/* translators: %d: pair number. */
			$which = count( $pairs ) > 1 ? ' ' . sprintf( __( '(pair %d)', 'cr-relocate-db' ), $index + 1 ) : '';

			if ( '' === $search ) {
				throw new JobException( 'crq_relocate_empty_search', __( 'Enter the text or URL to search for.', 'cr-relocate-db' ) . $which );
			}

			if ( $search === $replace ) {
				throw new JobException( 'crq_relocate_same_values', __( 'The search and replacement values are the same, so there is nothing to change.', 'cr-relocate-db' ) . $which );
			}

			// Folded the same way as in Replacement, or a pair it rejects would get past this check.
			$key = $case_sensitive ? $search : Replacement::lowercase( $search );

			if ( isset( $seen[ $key ] ) ) {
				throw new JobException( 'crq_relocate_duplicate_search', __( 'The same search value is entered twice.', 'cr-relocate-db' ) . $which );
			}

			$seen[ $key ] = true;
		}
	}

	/**
	 * Column names come from the request, so each one must be a searchable
	 * column of a table the job actually searches.
	 *
	 * @param array<string, list<string>> $exclude Table => columns.
	 * @param list<string>                $tables  Tables the job searches.
	 * @return array<string, list<string>>
	 * @throws JobException When a column is unknown.
	 */
	private function validate_columns( array $exclude, array $tables ): array {
		$exclude = array_filter( $exclude );

		if ( ! $exclude ) {
			return array();
		}

		try {
			$searchable = $this->schema->searchable_columns();
		} catch ( RuntimeException $e ) {
			throw new JobException( 'crq_relocate_database_error', $e->getMessage(), 500 );
		}

		$valid   = array();
		$unknown = array();

		foreach ( $exclude as $table => $columns ) {
			foreach ( array_unique( array_map( 'strval', (array) $columns ) ) as $column ) {
				if ( in_array( $table, $tables, true ) && in_array( $column, $searchable[ $table ] ?? array(), true ) ) {
					$valid[ $table ][] = $column;
				} else {
					$unknown[] = $table . '.' . $column;
				}
			}
		}

		if ( $unknown ) {
			throw new JobException(
				'crq_relocate_invalid_columns',
				/* translators: %s: comma-separated table.column names. */
				sprintf( __( 'These columns do not exist or are not searched: %s', 'cr-relocate-db' ), implode( ', ', $unknown ) )
			);
		}

		return $valid;
	}

	/**
	 * @throws JobException When the job cannot be saved.
	 */
	private function create( Job $job ): Job {
		try {
			return $this->jobs->create( $job );
		} catch ( RuntimeException $e ) {
			$this->logger->error( 'Could not create a job.', array( 'error' => $e->getMessage() ) );
			throw new JobException( 'crq_relocate_database_error', __( 'The job could not be saved to the database.', 'cr-relocate-db' ), 500 );
		}
	}

	/**
	 * Recorded on the dry run itself, so deleting the replacement from History
	 * does not make the dry run look unapplied. The replacement row is enough
	 * while it exists, so a failure here only costs that extra safeguard.
	 */
	private function mark_applied( Job $dry_run ): void {
		$dry_run->state['applied'] = true;

		try {
			$this->jobs->save( $dry_run );
		} catch ( RuntimeException $e ) {
			$this->logger->warning( 'Could not mark the dry run as applied.', array( 'error' => $e->getMessage() ), $dry_run->id );
		}
	}

	/**
	 * A replacement that asked for its original values to be saved does not
	 * start without the file: it is marked failed and nothing is changed.
	 *
	 * @throws JobException When the file cannot be created.
	 */
	private function create_before_image( Job $job ): void {
		try {
			$job->before_image = $this->before_images->create( $job );
			$this->jobs->save( $job );
		} catch ( RuntimeException $e ) {
			$job->finish( JobStatus::Failed, $e->getMessage() );
			$this->jobs->save( $job );
			$this->logger->error( 'Could not create the before-image file.', array( 'error' => $e->getMessage() ), $job->id );

			throw new JobException(
				'crq_relocate_before_image_failed',
				/* translators: %s: error message. */
				sprintf( __( 'The file for the original values could not be created, so nothing was changed. %s', 'cr-relocate-db' ), $e->getMessage() ),
				500
			);
		}
	}
}

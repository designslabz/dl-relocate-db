<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Jobs;

use CraftRoq\Relocate\Installer;
use RuntimeException;

/**
 * Loads and saves jobs in the jobs table.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading and writing the database directly is what this plugin is for, and results must never come from a cache.
 */
final class JobRepository {

	public function __construct( private \wpdb $wpdb ) {}

	/**
	 * @throws RuntimeException When the job cannot be stored.
	 */
	public function create( Job $job ): Job {
		$row                 = $this->to_row( $job );
		$row['created_at']   = $job->created_at;
		$row['search']       = $job->search;
		$row['replace_with'] = $job->replace;
		$row['dry_run']      = (int) $job->dry_run;
		$row['parent_id']    = $job->parent_id;
		$row['user_id']      = $job->user_id;

		if ( false === $this->wpdb->insert( $this->table(), $row ) ) {
			throw new RuntimeException( 'Could not create the job: ' . $this->wpdb->last_error );
		}

		$job->id = (int) $this->wpdb->insert_id;

		return $job;
	}

	public function find( int $id ): ?Job {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ) );

		return $row ? $this->from_row( $row ) : null;
	}

	/**
	 * The live job started from this dry run, if there is one.
	 */
	public function child_id( int $parent_id ): ?int {
		$wpdb = $this->wpdb;
		$id   = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE parent_id = %d LIMIT 1', $this->table(), $parent_id ) );

		return null === $id ? null : (int) $id;
	}

	/**
	 * Whether a live job was ever started from this dry run, even one since deleted.
	 */
	public function was_applied( Job $dry_run ): bool {
		return ! empty( $dry_run->state['applied'] ) || null !== $this->child_id( $dry_run->id );
	}

	/**
	 * Columns the history list can be sorted by. Anything else falls back to id.
	 */
	public const SORTABLE = array( 'id', 'rows_changed', 'replacements', 'status' );

	/**
	 * @param bool|null $dry_run Only dry runs (true), only live jobs (false), or both (null).
	 * @param string    $search  Matched against the search and replacement values.
	 * @return array{0: list<Job>, 1: int} The page of jobs and the total number of matching jobs.
	 */
	public function page( int $page, int $per_page, ?bool $dry_run = null, string $orderby = 'id', string $order = 'desc', string $search = '' ): array {
		$wpdb       = $this->wpdb;
		$conditions = array();
		$args       = array();

		if ( null !== $dry_run ) {
			$conditions[] = 'dry_run = %d';
			$args[]       = (int) $dry_run;
		}

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// Extra pairs live in the settings JSON, stored with unescaped slashes and Unicode.
			$conditions[] = '(search LIKE %s OR replace_with LIKE %s OR settings LIKE %s)';
			array_push( $args, $like, $like, $like );
		}

		$where   = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';
		$orderby = in_array( $orderby, self::SORTABLE, true ) ? $orderby : 'id';
		$order   = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';

		// $where holds only placeholders, with their values in $args; $order is ASC or DESC.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i' . $where . ' ORDER BY %i ' . $order . ', id ' . $order . ' LIMIT %d OFFSET %d',
				array_merge( array( $this->table() ), $args, array( $orderby, $per_page, max( 0, $page - 1 ) * $per_page ) )
			)
		);
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, array_merge( array( $this->table() ), $args ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array( array_map( array( $this, 'from_row' ), $rows ), $total );
	}

	/**
	 * @return array{jobs: int, replacements_run: int, replacements_made: int}
	 */
	public function stats(): array {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS jobs, SUM(dry_run = 0) AS runs, SUM(CASE WHEN dry_run = 0 THEN replacements ELSE 0 END) AS replacements FROM %i',
				$this->table()
			)
		);

		return array(
			'jobs'              => (int) ( $row->jobs ?? 0 ),
			'replacements_run'  => (int) ( $row->runs ?? 0 ),
			'replacements_made' => (int) ( $row->replacements ?? 0 ),
		);
	}

	public function delete( int $id ): void {
		$this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	public function latest_live_job(): ?Job {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE dry_run = 0 ORDER BY id DESC LIMIT 1', $this->table() ) );

		return $row ? $this->from_row( $row ) : null;
	}

	/**
	 * Jobs someone should look at: failed replacements, and anything interrupted.
	 *
	 * @return list<Job>
	 */
	public function needing_attention(): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status IN (%s, %s) OR (status = %s AND dry_run = 0) ORDER BY id DESC LIMIT 20',
				$this->table(),
				JobStatus::Pending->value,
				JobStatus::Running->value,
				JobStatus::Failed->value
			)
		);

		return array_values(
			array_filter(
				array_map( array( $this, 'from_row' ), $rows ),
				fn( Job $job ): bool => JobStatus::Failed === $job->status || $job->is_interrupted()
			)
		);
	}

	/**
	 * Deletes jobs last touched before the cutoff: finished jobs, and dry runs
	 * abandoned part-way, which changed nothing. An unfinished replacement is
	 * kept until someone resumes or cancels it, since it is half applied.
	 *
	 * @param string $cutoff UTC datetime.
	 * @return list<string> Before-image files of the deleted jobs, for the caller to remove.
	 */
	public function delete_expired( string $cutoff ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, before_image FROM %i WHERE (status IN (%s, %s, %s) OR (dry_run = 1 AND status IN (%s, %s))) AND updated_at < %s',
				$this->table(),
				JobStatus::Completed->value,
				JobStatus::Failed->value,
				JobStatus::Cancelled->value,
				JobStatus::Pending->value,
				JobStatus::Running->value,
				$cutoff
			)
		);

		if ( ! $rows ) {
			return array();
		}

		$ids = array_map( fn( object $row ): int => (int) $row->id, $rows );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- One %d placeholder per ID.
				'DELETE FROM %i WHERE id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
				array_merge( array( $this->table() ), $ids )
			)
		);

		if ( false === $deleted ) {
			throw new RuntimeException( 'Could not delete old jobs: ' . $wpdb->last_error );
		}

		return array_values( array_filter( array_map( fn( object $row ): string => (string) $row->before_image, $rows ) ) );
	}

	/**
	 * A live job that has not finished yet. Failed jobs do not count: they may
	 * be resumed, but they should not block every future replacement.
	 */
	public function active_live_job(): ?Job {
		$wpdb = $this->wpdb;
		$id   = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE dry_run = 0 AND status IN (%s, %s) ORDER BY id DESC LIMIT 1',
				$this->table(),
				JobStatus::Pending->value,
				JobStatus::Running->value
			)
		);

		return null === $id ? null : $this->find( (int) $id );
	}

	/**
	 * Saves the parts of a job that change while it runs.
	 *
	 * @throws RuntimeException When the job cannot be saved.
	 */
	public function save( Job $job ): void {
		if ( false === $this->wpdb->update( $this->table(), $this->to_row( $job ), array( 'id' => $job->id ) ) ) {
			throw new RuntimeException( 'Could not save the job: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * @return array<string, string|int|null>
	 */
	private function to_row( Job $job ): array {
		$totals = $job->report->totals();

		return array(
			'status'        => $job->status->value,
			'settings'      => $this->encode( $job->settings ),
			'state'         => $this->encode( $job->state ),
			'report'        => $this->encode( $job->report->to_array() ),
			'rows_scanned'  => $totals['rows_scanned'],
			'rows_changed'  => $totals['rows_changed'],
			'replacements'  => $totals['replacements'],
			'error_count'   => $totals['skipped'],
			'error_message' => $job->error_message,
			'started_at'    => $job->started_at,
			'finished_at'   => $job->finished_at,
			'before_image'  => $job->before_image,
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private function from_row( object $row ): Job {
		return new Job(
			(int) $row->id,
			null === $row->parent_id ? null : (int) $row->parent_id,
			(bool) $row->dry_run,
			JobStatus::tryFrom( (string) $row->status ) ?? JobStatus::Failed,
			(string) $row->search,
			(string) $row->replace_with,
			$this->decode( $row->settings ),
			$this->decode( $row->state ),
			Report::from_array( $this->decode( $row->report ) ),
			(int) $row->user_id,
			(string) $row->created_at,
			$row->started_at,
			$row->finished_at,
			$row->error_message,
			(string) $row->before_image,
			$row->updated_at
		);
	}

	/**
	 * @param array<mixed> $data
	 */
	private function encode( array $data ): string {
		// Sample snippets come straight from the database and may not be valid UTF-8.
		return (string) wp_json_encode( $data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * @return array<mixed>
	 */
	private function decode( ?string $json ): array {
		$data = json_decode( (string) $json, true );

		return is_array( $data ) ? $data : array();
	}

	private function table(): string {
		return $this->wpdb->prefix . Installer::JOBS_TABLE;
	}
}

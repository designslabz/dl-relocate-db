<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Jobs;

use CraftRoq\Relocate\Replace\Replacement;

/**
 * One search and replace run: its settings, where it has got to, and what it found.
 *
 * @phpstan-type JobSettings array{case_sensitive: bool, whole_words: bool, url_variants: bool, skip_guids: bool, tables: list<string>, exclude_columns?: array<string, list<string>>, before_image?: bool, pairs?: list<array{0: string, 1: string}>}
 * @phpstan-type JobState array{table_index: int, last_key: array<string, string>|null, total_rows: int, site_address_done?: bool, site_address_changed?: bool, applied?: bool}
 */
final class Job {

	// A step saves at least every few seconds, so a job this quiet is not being processed.
	private const INTERRUPTED_AFTER = 60;

	/**
	 * @param JobSettings $settings
	 * @param JobState    $state
	 */
	public function __construct(
		public int $id,
		public ?int $parent_id,
		public bool $dry_run,
		public JobStatus $status,
		public string $search,
		public string $replace,
		public array $settings,
		public array $state,
		public Report $report,
		public int $user_id,
		public string $created_at,
		public ?string $started_at = null,
		public ?string $finished_at = null,
		public ?string $error_message = null,
		public string $before_image = '',
		public ?string $updated_at = null
	) {}

	/**
	 * Every search and replacement pair. $search and $replace hold the first one,
	 * which is what lists show.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public function pairs(): array {
		return $this->settings['pairs'] ?? array( array( $this->search, $this->replace ) );
	}

	public function replacement(): Replacement {
		return new Replacement(
			$this->pairs(),
			$this->settings['case_sensitive'],
			$this->settings['whole_words'],
			$this->settings['url_variants']
		);
	}

	/**
	 * Unfinished, but nothing has worked on it for a while: the page running it
	 * was closed or lost its connection. Continuing it picks up where it stopped.
	 */
	public function is_interrupted(): bool {
		return ! $this->status->is_finished()
			&& null !== $this->updated_at
			&& strtotime( $this->updated_at . ' UTC' ) < time() - self::INTERRUPTED_AFTER;
	}

	public function current_table(): ?string {
		return $this->settings['tables'][ $this->state['table_index'] ] ?? null;
	}

	public function finish( JobStatus $status, ?string $error_message = null ): void {
		$this->status        = $status;
		$this->error_message = $error_message;
		$this->finished_at   = gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Rough percentage for the progress bar. Row counts are the server's estimates,
	 * so this is held below 100 until the job has actually finished.
	 */
	public function progress(): int {
		if ( JobStatus::Completed === $this->status ) {
			return 100;
		}

		$by_tables = $this->settings['tables'] ? $this->state['table_index'] / count( $this->settings['tables'] ) : 0;
		$by_rows   = $this->state['total_rows'] > 0 ? $this->report->totals()['rows_scanned'] / $this->state['total_rows'] : 0;

		return min( 99, (int) floor( max( $by_tables, $by_rows ) * 100 ) );
	}
}

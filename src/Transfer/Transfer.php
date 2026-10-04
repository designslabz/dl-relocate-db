<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Transfer;

use CraftRoq\Relocate\Jobs\JobStatus;

/**
 * One database export or import: what it covers, where it has got to, and its file.
 *
 * @phpstan-type ExportSettings array{tables: list<string>, pairs: list<array{0: string, 1: string}>}
 * @phpstan-type ImportSettings array{name: string, size: int}
 */
final class Transfer {

	public const EXPORT = 'export';
	public const IMPORT = 'import';

	// A step saves at least every few seconds, so one this quiet is not being processed.
	private const INTERRUPTED_AFTER = 60;

	/**
	 * @param string               $type     self::EXPORT or self::IMPORT.
	 * @param array<string, mixed> $settings What to export, or which file was uploaded.
	 * @param array<string, mixed> $state    Position and counters, updated as it runs.
	 * @param string               $file     File name in Storage: the export, or the uploaded import.
	 */
	public function __construct(
		public int $id,
		public string $type,
		public JobStatus $status,
		public array $settings,
		public array $state,
		public string $file,
		public int $user_id,
		public string $created_at,
		public ?string $finished_at = null,
		public ?string $error_message = null,
		public string $token_hash = '',
		public ?string $updated_at = null
	) {}

	public function is_export(): bool {
		return self::EXPORT === $this->type;
	}

	public function finish( JobStatus $status, ?string $error_message = null ): void {
		$this->status        = $status;
		$this->error_message = $error_message;
		$this->finished_at   = gmdate( 'Y-m-d H:i:s' );
	}

	public function is_interrupted(): bool {
		return ! $this->status->is_finished()
			&& null !== $this->updated_at
			&& strtotime( $this->updated_at . ' UTC' ) < time() - self::INTERRUPTED_AFTER;
	}

	/**
	 * Rough percentage for the progress bar, held below 100 until finished.
	 */
	public function progress(): int {
		if ( JobStatus::Completed === $this->status ) {
			return 100;
		}

		if ( $this->is_export() ) {
			$tables = count( $this->settings['tables'] );
			$rows   = (int) ( $this->state['total_rows'] ?? 0 );
			$share  = max(
				$tables ? (int) $this->state['table_index'] / $tables : 0,
				$rows ? (int) $this->state['rows'] / $rows : 0
			);
		} else {
			// Unpacking a compressed file comes first and counts for a fifth. How far
			// it has got is only known in unpacked bytes, so it assumes SQL shrinks
			// to about a fifth of its size.
			$unpack = $this->state['compressed'] ? 0.2 : 0.0;
			$share  = 'unpack' === $this->state['phase']
				? $unpack * min( 1, (int) $this->state['unpacked'] / max( 1, 5 * (int) $this->settings['size'] ) )
				: $unpack + ( 1 - $unpack ) * (int) $this->state['offset'] / max( 1, (int) $this->state['size'] );
		}

		return min( 99, (int) floor( $share * 100 ) );
	}
}

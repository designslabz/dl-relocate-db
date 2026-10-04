<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Transfer;

use CraftRoq\Relocate\Database\Lock;
use CraftRoq\Relocate\Installer;
use CraftRoq\Relocate\Jobs\JobRunner;
use CraftRoq\Relocate\Jobs\JobStatus;
use CraftRoq\Relocate\Logger;
use RuntimeException;

/**
 * Advances an export or import one step at a time, like JobRunner does for
 * search and replace: the caller keeps calling until it has finished, and a
 * named lock keeps two requests from working on the same transfer at once.
 */
final class TransferRunner {

	public function __construct(
		private \wpdb $wpdb,
		private TransferRepository $transfers,
		private Exporter $exporter,
		private Importer $importer,
		private Logger $logger
	) {}

	/**
	 * @return Transfer|null The transfer as saved after the step, or null when another request is processing it.
	 * @throws RuntimeException When progress cannot be saved.
	 */
	public function step( Transfer $transfer ): ?Transfer {
		return $this->locked(
			$transfer->id,
			0,
			function ( Transfer $transfer ): Transfer {
				if ( $transfer->status->is_finished() ) {
					return $transfer;
				}

				if ( JobStatus::Pending === $transfer->status ) {
					$transfer->status = JobStatus::Running;
				}

				$deadline = microtime( true ) + JobRunner::step_seconds();

				try {
					if ( $transfer->is_export() ) {
						$this->exporter->step( $transfer, $deadline );
					} else {
						$this->importer->step( $transfer, $deadline );
					}
				} catch ( RuntimeException $e ) {
					// Keep the position of the last save: the failed part did not count.
					$saved = $this->transfers->find( $transfer->id );
					if ( $saved ) {
						$transfer->state = $saved->state;
						$transfer->file  = $saved->file;
					}

					$transfer->finish( JobStatus::Failed, $e->getMessage() );
					$this->transfers->save( $transfer );
					$this->logger->error(
						ucfirst( $transfer->type ) . ' failed.',
						array(
							'transfer' => $transfer->id,
							'error'    => $e->getMessage(),
						)
					);
					return $transfer;
				}

				if ( JobStatus::Completed === $transfer->status ) {
					$this->logger->info( ucfirst( $transfer->type ) . ' completed.', array( 'transfer' => $transfer->id ) );
				}

				return $transfer;
			}
		);
	}

	/**
	 * Puts a failed transfer back to running; it carries on from its last save.
	 *
	 * @return Transfer|null Null when a step is processing it right now.
	 * @throws RuntimeException When it cannot be saved.
	 */
	public function resume( Transfer $transfer ): ?Transfer {
		return $this->locked(
			$transfer->id,
			0,
			function ( Transfer $transfer ): Transfer {
				if ( JobStatus::Failed === $transfer->status ) {
					$transfer->status        = JobStatus::Running;
					$transfer->error_message = null;
					$transfer->finished_at   = null;
					$this->transfers->save( $transfer );
				}

				return $transfer;
			}
		);
	}

	/**
	 * @return Transfer|null Null when a step in progress did not finish in time.
	 * @throws RuntimeException When it cannot be saved.
	 */
	public function cancel( Transfer $transfer ): ?Transfer {
		return $this->locked(
			$transfer->id,
			(int) ceil( JobRunner::step_seconds() ) + 5,
			function ( Transfer $transfer ): Transfer {
				if ( ! $transfer->status->is_finished() ) {
					$transfer->finish( JobStatus::Cancelled );
					$this->transfers->save( $transfer );
				}

				return $transfer;
			}
		);
	}

	/**
	 * Runs $work on a fresh copy of the transfer while holding its lock.
	 *
	 * @param callable(Transfer): Transfer $work
	 */
	private function locked( int $id, int $timeout, callable $work ): ?Transfer {
		$lock = new Lock( $this->wpdb );
		$name = $this->wpdb->prefix . Installer::TRANSFERS_TABLE . ':' . $id;

		if ( ! $lock->acquire( $name, $timeout ) ) {
			return null;
		}

		try {
			// Re-read under the lock: another request may have moved it on.
			$transfer = $this->transfers->find( $id );

			return null === $transfer ? null : $work( $transfer );
		} finally {
			$lock->release( $name );
		}
	}
}

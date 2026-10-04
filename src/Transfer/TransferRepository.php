<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Transfer;

use CraftRoq\Relocate\Installer;
use CraftRoq\Relocate\Jobs\JobStatus;
use RuntimeException;

/**
 * Loads and saves exports and imports in the transfers table.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading and writing the database directly is what this plugin is for, and results must never come from a cache.
 */
final class TransferRepository {

	public function __construct( private \wpdb $wpdb ) {}

	/**
	 * @throws RuntimeException When the transfer cannot be stored.
	 */
	public function create( Transfer $transfer ): Transfer {
		$row               = $this->to_row( $transfer );
		$row['type']       = $transfer->type;
		$row['user_id']    = $transfer->user_id;
		$row['created_at'] = $transfer->created_at;
		$row['token_hash'] = $transfer->token_hash;

		if ( false === $this->wpdb->insert( $this->table(), $row ) ) {
			throw new RuntimeException( 'Could not create the transfer: ' . $this->wpdb->last_error );
		}

		$transfer->id = (int) $this->wpdb->insert_id;

		return $transfer;
	}

	public function find( int $id ): ?Transfer {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ) );

		return $row ? $this->from_row( $row ) : null;
	}

	/**
	 * @throws RuntimeException When the transfer cannot be saved.
	 */
	public function save( Transfer $transfer ): void {
		if ( false === $this->wpdb->update( $this->table(), $this->to_row( $transfer ), array( 'id' => $transfer->id ) ) ) {
			throw new RuntimeException( 'Could not save the transfer: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * The most recent transfers of one type, newest first.
	 *
	 * @return list<Transfer>
	 */
	public function recent( string $type, int $limit ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE type = %s ORDER BY id DESC LIMIT %d', $this->table(), $type, $limit )
		);

		return array_map( array( $this, 'from_row' ), $rows );
	}

	public function delete( int $id ): void {
		$this->wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Deletes transfers last touched before the cutoff, finished or abandoned:
	 * an export or import nobody has worked on for that long will not be resumed.
	 *
	 * @param string $cutoff UTC datetime.
	 * @return list<string> Files of the deleted transfers, for the caller to remove.
	 * @throws RuntimeException When they cannot be deleted.
	 */
	public function delete_expired( string $cutoff ): array {
		$wpdb = $this->wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, file, state FROM %i WHERE updated_at < %s', $this->table(), $cutoff )
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
			throw new RuntimeException( 'Could not delete old transfers: ' . $wpdb->last_error );
		}

		$files = array();
		foreach ( $rows as $row ) {
			$files[] = (string) $row->file;
			// An import may also have left its unpacked copy behind.
			$files[] = (string) ( $this->decode( $row->state )['sql_file'] ?? '' );
		}

		return array_values( array_filter( $files ) );
	}

	/**
	 * @return array<string, string|int|null>
	 */
	private function to_row( Transfer $transfer ): array {
		return array(
			'status'        => $transfer->status->value,
			'settings'      => (string) wp_json_encode( $transfer->settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
			'state'         => (string) wp_json_encode( $transfer->state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE ),
			'file'          => $transfer->file,
			'error_message' => $transfer->error_message,
			'finished_at'   => $transfer->finished_at,
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private function from_row( object $row ): Transfer {
		return new Transfer(
			(int) $row->id,
			(string) $row->type,
			JobStatus::tryFrom( (string) $row->status ) ?? JobStatus::Failed,
			$this->decode( $row->settings ),
			$this->decode( $row->state ),
			(string) $row->file,
			(int) $row->user_id,
			(string) $row->created_at,
			$row->finished_at,
			$row->error_message,
			(string) $row->token_hash,
			$row->updated_at
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decode( ?string $json ): array {
		$data = json_decode( (string) $json, true );

		return is_array( $data ) ? $data : array();
	}

	private function table(): string {
		return $this->wpdb->prefix . Installer::TRANSFERS_TABLE;
	}
}

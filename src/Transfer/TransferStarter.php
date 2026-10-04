<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Transfer;

use CraftRoq\Relocate\Database\Schema;
use CraftRoq\Relocate\Jobs\JobException;
use CraftRoq\Relocate\Jobs\JobStarter;
use CraftRoq\Relocate\Jobs\JobStatus;
use CraftRoq\Relocate\Logger;
use CraftRoq\Relocate\Replace\Replacement;
use CraftRoq\Relocate\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Validates and creates exports and imports.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 */
final class TransferStarter {

	public const SCOPE_CORE = 'core';
	public const SCOPE_ALL  = 'all';

	public function __construct(
		private \wpdb $wpdb,
		private TransferRepository $transfers,
		private Schema $schema,
		private Logger $logger
	) {}

	/**
	 * @param string                            $scope self::SCOPE_CORE for the WordPress tables, self::SCOPE_ALL for every table.
	 * @param list<array{0: string, 1: string}> $pairs Text to change while exporting; may be empty.
	 * @throws JobException When the request is invalid or the export cannot be saved.
	 */
	public function export( string $scope, array $pairs ): Transfer {
		$pairs = $this->pairs( $pairs );

		try {
			$tables = $this->schema->searchable_tables();
		} catch ( RuntimeException $e ) {
			throw new JobException( 'crq_relocate_database_error', $e->getMessage(), 500 );
		}

		if ( self::SCOPE_ALL !== $scope ) {
			$tables = array_filter( $tables, fn( $table ): bool => $table->prefixed );
		}

		if ( ! $tables ) {
			throw new JobException( 'crq_relocate_no_tables', __( 'There are no tables to export.', 'cr-relocate-db' ) );
		}

		$export = $this->create(
			new Transfer(
				0,
				Transfer::EXPORT,
				JobStatus::Pending,
				array(
					'scope'  => self::SCOPE_ALL === $scope ? self::SCOPE_ALL : self::SCOPE_CORE,
					'tables' => array_keys( $tables ),
					'pairs'  => $pairs,
				),
				Exporter::initial_state( array_sum( array_map( fn( $table ): int => $table->approx_rows, $tables ) ) ),
				'',
				get_current_user_id(),
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		$export->file = Storage::name( 'export-' . $export->id, '.sql.gz' );
		$this->save( $export );

		$this->logger->info(
			'Export started.',
			array(
				'transfer' => $export->id,
				'tables'   => count( $tables ),
			)
		);

		return $export;
	}

	/**
	 * Takes an uploaded file from $_FILES and starts importing it.
	 *
	 * @param array{name?: string, tmp_name?: string, error?: int, size?: int} $upload
	 * @param list<array{0: string, 1: string}>                                 $pairs  Text to change while importing; may be empty.
	 * @return array{0: Transfer, 1: string} The import, and the key that lets its steps run even if the import logs the user out.
	 * @throws JobException When the file or the pairs cannot be used.
	 */
	public function import_upload( array $upload, array $pairs = array() ): array {
		// Checked before the upload is kept, so a mistake does not leave a file behind.
		$pairs = $this->pairs( $pairs );

		$error = (int) ( $upload['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( UPLOAD_ERR_OK !== $error ) {
			throw new JobException(
				'crq_relocate_upload_failed',
				in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
					/* translators: %s: maximum upload size, e.g. 64 MB. */
					? sprintf( __( 'The file is larger than this server accepts (%s).', 'cr-relocate-db' ), size_format( wp_max_upload_size() ) )
					: __( 'The file could not be uploaded. Choose it and try again.', 'cr-relocate-db' )
			);
		}

		$name       = sanitize_file_name( (string) ( $upload['name'] ?? '' ) );
		$compressed = (bool) preg_match( '/\.gz$/i', $name );

		if ( ! preg_match( '/\.sql(?:\.gz)?$|\.gz$/i', $name ) ) {
			throw new JobException( 'crq_relocate_wrong_file', __( 'Choose an .sql or .sql.gz file.', 'cr-relocate-db' ) );
		}

		$stored = Storage::name( 'upload', $compressed ? '.sql.gz' : '.sql' );

		try {
			$moved = Storage::receive( $upload, $stored );
		} catch ( RuntimeException $e ) {
			throw new JobException( 'crq_relocate_storage', $e->getMessage(), 500 );
		}

		if ( ! $moved ) {
			throw new JobException( 'crq_relocate_upload_failed', __( 'The file could not be uploaded. Choose it and try again.', 'cr-relocate-db' ), 500 );
		}

		return $this->import( $stored, $name, $pairs );
	}

	/**
	 * Starts importing a file already in the Storage folder.
	 *
	 * @param string                            $stored File name in Storage.
	 * @param string                            $name   Name the file was uploaded with, for display.
	 * @param list<array{0: string, 1: string}> $pairs  Text to change while importing; may be empty.
	 * @return array{0: Transfer, 1: string} The import, and the key for its steps.
	 * @throws JobException When the file or the pairs cannot be used.
	 */
	public function import( string $stored, string $name, array $pairs = array() ): array {
		try {
			$pairs = $this->pairs( $pairs );
		} catch ( JobException $e ) {
			Storage::delete( $stored );
			throw $e;
		}

		$path = Storage::path( $stored );

		if ( null === $path || 0 === filesize( $path ) ) {
			Storage::delete( $stored );
			throw new JobException( 'crq_relocate_wrong_file', __( 'The file is empty.', 'cr-relocate-db' ) );
		}

		// Tables with another prefix would sit next to this site's, unused.
		$prefix = Importer::file_prefix( $path );

		if ( null !== $prefix && $prefix !== $this->wpdb->prefix ) {
			Storage::delete( $stored );
			throw new JobException(
				'crq_relocate_prefix_mismatch',
				/* translators: 1: table prefix in the file, 2: table prefix of this site. */
				sprintf( __( 'This file is from a site whose tables start with %1$s, but this site’s tables start with %2$s. Importing it would not replace this site’s data.', 'cr-relocate-db' ), $prefix, $this->wpdb->prefix )
			);
		}

		$compressed = (bool) preg_match( '/\.gz$/i', $stored );
		$token      = wp_generate_password( 32, false );

		try {
			$import = $this->transfers->create(
				new Transfer(
					0,
					Transfer::IMPORT,
					JobStatus::Pending,
					array(
						'name'  => $name,
						'size'  => (int) filesize( $path ),
						'pairs' => $pairs,
					),
					Importer::initial_state( $compressed, (int) filesize( $path ) ),
					$stored,
					get_current_user_id(),
					gmdate( 'Y-m-d H:i:s' ),
					null,
					null,
					hash( 'sha256', $token )
				)
			);
		} catch ( RuntimeException $e ) {
			Storage::delete( $stored );
			$this->logger->error( 'Could not create an import.', array( 'error' => $e->getMessage() ) );
			throw new JobException( 'crq_relocate_database_error', __( 'The import could not be saved to the database.', 'cr-relocate-db' ), 500 );
		}

		$this->logger->info( 'Import started.', array( 'transfer' => $import->id ) );

		return array( $import, $token );
	}

	/**
	 * Text to change while exporting or importing. Rows left completely empty
	 * are ignored, since changing text is optional.
	 *
	 * @param list<array{0: string, 1: string}> $pairs
	 * @return list<array{0: string, 1: string}>
	 * @throws JobException When a pair cannot be used.
	 */
	private function pairs( array $pairs ): array {
		$pairs = array_values( array_filter( $pairs, fn( array $pair ): bool => '' !== $pair[0] || '' !== $pair[1] ) );

		if ( $pairs ) {
			JobStarter::validate_pairs( $pairs, true );

			try {
				new Replacement( $pairs );
			} catch ( InvalidArgumentException ) {
				throw new JobException( 'crq_relocate_invalid_values', __( 'The search and replacement values must be valid UTF-8 text.', 'cr-relocate-db' ) );
			}
		}

		return $pairs;
	}

	/**
	 * @throws JobException When the transfer cannot be saved.
	 */
	private function create( Transfer $transfer ): Transfer {
		try {
			return $this->transfers->create( $transfer );
		} catch ( RuntimeException $e ) {
			$this->logger->error( 'Could not create a transfer.', array( 'error' => $e->getMessage() ) );
			throw new JobException( 'crq_relocate_database_error', __( 'The export could not be saved to the database.', 'cr-relocate-db' ), 500 );
		}
	}

	/**
	 * @throws JobException When the transfer cannot be saved.
	 */
	private function save( Transfer $transfer ): void {
		try {
			$this->transfers->save( $transfer );
		} catch ( RuntimeException $e ) {
			throw new JobException( 'crq_relocate_database_error', $e->getMessage(), 500 );
		}
	}
}

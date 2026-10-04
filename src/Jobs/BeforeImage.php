<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Jobs;

use CraftRoq\Relocate\Database\TableLayout;
use CraftRoq\Relocate\Storage;
use RuntimeException;

/**
 * A gzipped SQL file holding the original value of every cell a live job changes,
 * as UPDATE statements keyed on each row's primary or unique key.
 *
 * It is a recovery aid, not an undo button: running it puts those cells back
 * exactly as they were, including over any edits made after the job.
 *
 * Files live in the plugin's Storage folder and are only handed out through
 * the authenticated download handler.
 *
 * The native gz* functions are used because WP_Filesystem cannot append, and
 * each window's statements must reach the disk before its transaction commits.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 */
final class BeforeImage {

	public function __construct( private \wpdb $wpdb ) {}

	/**
	 * @return string File name, relative to the before-image directory.
	 * @throws RuntimeException When the file cannot be created.
	 */
	public function create( Job $job ): string {
		Storage::make_directory();

		$file = Storage::name( 'job-' . $job->id, '.sql.gz' );

		$this->write(
			$file,
			implode(
				"\n",
				array(
					sprintf( '-- CR Relocate DB: original values changed by job %d, started %s UTC.', $job->id, gmdate( 'Y-m-d H:i:s' ) ),
					'-- Running this file puts every changed value back, overwriting any edits made to those values since.',
					'-- Statements from a batch that was rolled back restore values that never changed, so they are harmless.',
					'SET NAMES ' . ( $this->wpdb->charset ? $this->wpdb->charset : 'utf8mb4' ) . ';',
					'',
				)
			)
		);

		return $file;
	}

	/**
	 * @param list<array{key: array<string, string>, before: array<string, string>, after: array<string, string>, counts: array<string, int>}> $rows
	 * @throws RuntimeException When the statements cannot be written.
	 */
	public function append( string $file, TableLayout $layout, array $rows ): void {
		$wpdb = $this->wpdb;
		$sql  = '';

		foreach ( $rows as $row ) {
			$sets  = array();
			$where = array();
			$args  = array( $layout->name );

			foreach ( $row['before'] as $column => $value ) {
				$sets[] = '%i = %s';
				array_push( $args, $column, $value );
			}

			foreach ( $row['key'] as $column => $value ) {
				$where[] = '%i = ' . ( $layout->key[ $column ] ? '%d' : '%s' );
				array_push( $args, $column, $value );
			}

			// prepare() swaps literal % signs for a placeholder token that query() would normally undo.
			$sql .= $wpdb->remove_placeholder_escape(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sets and $where hold placeholders only; every name and value is in $args.
				$wpdb->prepare( 'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE ' . implode( ' AND ', $where ) . ';', $args )
			) . "\n";
		}

		$this->write( $file, $sql );
	}

	/**
	 * Full path of an existing before-image file, or null.
	 */
	public function path( string $file ): ?string {
		return Storage::path( $file );
	}

	public function delete( string $file ): void {
		Storage::delete( $file );
	}

	/**
	 * Appends one gzip member per call. gzip readers treat concatenated members
	 * as one stream, and closing each time guarantees the data is written.
	 *
	 * @throws RuntimeException When the file cannot be written.
	 */
	private function write( string $file, string $contents ): void {
		$path   = Storage::directory() . '/' . $file;
		$handle = gzopen( $path, 'ab' );

		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Could not open %s for writing.', $path ) );
		}

		$written = gzwrite( $handle, $contents );

		if ( ! gzclose( $handle ) || strlen( $contents ) !== $written ) {
			throw new RuntimeException( sprintf( 'Could not write to %s. The disk may be full.', $path ) );
		}
	}
}

<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Transfer;

use CraftRoq\Relocate\Database\Schema;
use CraftRoq\Relocate\Database\TableLayout;
use CraftRoq\Relocate\Jobs\JobStatus;
use CraftRoq\Relocate\Replace\Replacement;
use CraftRoq\Relocate\Replace\Replacer;
use CraftRoq\Relocate\Storage;
use RuntimeException;

/**
 * Writes tables to a gzipped SQL file, a batch of rows at a time.
 *
 * Text can be changed on the way out with the same serialization-safe
 * Replacer as Search & Replace, so a staging site exports with its live
 * addresses already in place. The database itself is only read.
 *
 * Rows are paged by primary key, like Search & Replace, or by offset for
 * tables without one. After every batch the file's size is saved with the
 * position; a step that died part-way is cut back to that size before
 * carrying on, so no row is written twice.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading and writing the database directly is what this plugin is for, and results must never come from a cache.
 */
final class Exporter {

	// Written as 0x... hex literals so no character set conversion can touch them.
	private const BINARY_TYPES = array( 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection' );

	private const NUMERIC_TYPES = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'year' );

	// Keeps each INSERT well under the default max_allowed_packet.
	private const MAX_STATEMENT = 1048576;

	/** @var array<string, array{columns: array<string, string>, layout: TableLayout|null}> */
	private array $tables = array();

	public function __construct(
		private \wpdb $wpdb,
		private Schema $schema,
		private TransferRepository $transfers,
		private int $batch_size
	) {}

	/**
	 * @param int $total_rows Estimated, for the progress bar.
	 * @return array<string, mixed>
	 */
	public static function initial_state( int $total_rows ): array {
		return array(
			'header'        => false,
			'table_index'   => 0,
			'table_started' => false,
			'last_key'      => null,
			'offset'        => 0,
			'bytes'         => 0,
			'rows'          => 0,
			'total_rows'    => $total_rows,
			'replacements'  => 0,
			'skipped'       => 0,
		);
	}

	/**
	 * Works until the deadline, saving after every batch.
	 *
	 * @throws RuntimeException On a database or file error.
	 */
	public function step( Transfer $export, float $deadline ): void {
		$path = Storage::make_directory() . '/' . $export->file;
		Storage::truncate( $path, (int) $export->state['bytes'] );

		// Dates in TIMESTAMP columns are read and written in UTC, whatever the server's zone.
		$this->wpdb->query( "SET time_zone = '+00:00'" );

		$replacer = $export->settings['pairs'] ? new Replacer( new Replacement( $export->settings['pairs'] ) ) : null;

		if ( ! $export->state['header'] ) {
			$this->write( $path, $this->header( $export ) );
			$export->state['header'] = true;
			$this->save( $export, $path );
		}

		$tables = $export->settings['tables'];
		$count  = count( $tables );

		while ( $export->state['table_index'] < $count ) {
			$table = $tables[ $export->state['table_index'] ];

			if ( ! $export->state['table_started'] ) {
				$this->write( $path, $this->create_statement( $table ) );
				$export->state['table_started'] = true;
				$this->save( $export, $path );
			}

			if ( $this->write_rows( $export, $path, $table, $replacer ) ) {
				++$export->state['table_index'];
				$export->state['table_started'] = false;
				$export->state['last_key']      = null;
				$export->state['offset']        = 0;
			}

			$this->save( $export, $path );

			if ( microtime( true ) >= $deadline ) {
				return;
			}
		}

		$this->write( $path, "\nSET FOREIGN_KEY_CHECKS = 1;\n-- End of export\n" );
		$export->finish( JobStatus::Completed );
		$this->save( $export, $path );
	}

	/**
	 * Writes the next batch of rows of a table.
	 *
	 * @return bool Whether the table is finished.
	 * @throws RuntimeException On a database or file error.
	 */
	private function write_rows( Transfer $export, string $path, string $table, ?Replacer $replacer ): bool {
		$wpdb    = $this->wpdb;
		$details = $this->table( $table );
		$layout  = $details['layout'];

		if ( $layout && $layout->key ) {
			$key   = array_keys( $layout->key );
			$where = null === $export->state['last_key'] ? array( '1 = 1', array() ) : $layout->compare( $export->state['last_key'], '>' );

			// $where[0] holds placeholders only, with its names and values in $where[1].
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE ' . $where[0] . ' ORDER BY ' . implode( ', ', array_fill( 0, count( $key ), '%i' ) ) . ' LIMIT %d',
					array_merge( array( $table ), $where[1], $key, array( $this->batch_size ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, $this->batch_size, (int) $export->state['offset'] ),
				ARRAY_A
			);
		}

		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( $wpdb->last_error );
		}

		if ( ! $rows ) {
			return true;
		}

		$this->write( $path, $this->insert_statements( $export, $table, $details['columns'], $rows, $replacer ) );

		$export->state['rows'] += count( $rows );

		if ( $layout && $layout->key ) {
			$export->state['last_key'] = array_intersect_key( end( $rows ), $layout->key );
		} else {
			$export->state['offset'] += count( $rows );
		}

		return count( $rows ) < $this->batch_size;
	}

	/**
	 * @param array<string, string>                   $columns Column => type.
	 * @param list<array<string, string|null>>        $rows
	 */
	private function insert_statements( Transfer $export, string $table, array $columns, array $rows, ?Replacer $replacer ): string {
		$head   = 'INSERT INTO ' . self::identifier( $table ) . ' (' . implode( ', ', array_map( array( self::class, 'identifier' ), array_keys( $columns ) ) ) . ") VALUES\n";
		$sql    = '';
		$tuples = array();
		$size   = 0;

		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $columns as $column => $type ) {
				$values[] = $this->literal( $export, $row[ $column ] ?? null, $type, $replacer );
			}

			$tuple = '(' . implode( ', ', $values ) . ')';

			if ( $tuples && $size + strlen( $tuple ) > self::MAX_STATEMENT ) {
				$sql   .= $head . implode( ",\n", $tuples ) . ";\n";
				$tuples = array();
				$size   = 0;
			}

			$tuples[] = $tuple;
			$size    += strlen( $tuple ) + 2;
		}

		$sql .= $head . implode( ",\n", $tuples ) . ";\n";

		// _real_escape() swaps % for a placeholder token that query() would normally undo.
		return $this->wpdb->remove_placeholder_escape( $sql );
	}

	private function literal( Transfer $export, ?string $value, string $type, ?Replacer $replacer ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		if ( in_array( $type, self::BINARY_TYPES, true ) ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}

		if ( in_array( $type, self::NUMERIC_TYPES, true ) && is_numeric( $value ) ) {
			return $value;
		}

		if ( $replacer && in_array( $type, Schema::TEXT_TYPES, true ) ) {
			try {
				$result = $replacer->replace( $value );

				if ( null !== $result->skipped ) {
					++$export->state['skipped'];
				} elseif ( $result->count ) {
					$value                          = $result->value;
					$export->state['replacements'] += $result->count;
				}
			} catch ( RuntimeException ) {
				++$export->state['skipped'];
			}
		}

		return "'" . $this->wpdb->_real_escape( $value ) . "'";
	}

	/**
	 * @throws RuntimeException When the table cannot be read.
	 */
	private function create_statement( string $table ): string {
		$wpdb = $this->wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );

		if ( '' !== $wpdb->last_error || ! isset( $row[1] ) ) {
			throw new RuntimeException( '' !== $wpdb->last_error ? $wpdb->last_error : sprintf( 'Could not read the structure of %s.', $table ) );
		}

		return sprintf( "\n-- Table %1\$s\nDROP TABLE IF EXISTS %2\$s;\n%3\$s;\n", $table, self::identifier( $table ), $row[1] );
	}

	private function header( Transfer $export ): string {
		$lines = array(
			'-- CR Relocate DB database export',
			'-- Site: ' . home_url(),
			'-- Created: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'-- Table prefix: ' . $this->wpdb->prefix,
			'-- Tables: ' . count( $export->settings['tables'] ),
		);

		foreach ( $export->settings['pairs'] as [ $search, $replace ] ) {
			// JSON keeps any line break in the values from ending the comment.
			$lines[] = '-- Changed while exporting: ' . wp_json_encode( $search, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' -> ' . wp_json_encode( $replace, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$lines[] = '';
		$lines[] = 'SET NAMES ' . ( $this->wpdb->charset ? $this->wpdb->charset : 'utf8mb4' ) . ';';
		$lines[] = "SET time_zone = '+00:00';";
		$lines[] = 'SET FOREIGN_KEY_CHECKS = 0;';
		$lines[] = "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';";

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @return array{columns: array<string, string>, layout: TableLayout|null}
	 * @throws RuntimeException When the table cannot be inspected.
	 */
	private function table( string $table ): array {
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array(
				'columns' => $this->schema->stored_columns( $table ),
				'layout'  => $this->schema->describe( $table ),
			);
		}

		return $this->tables[ $table ];
	}

	/**
	 * Appends one gzip member. Readers treat concatenated members as one stream.
	 *
	 * @throws RuntimeException When the file cannot be written.
	 */
	private function write( string $path, string $contents ): void {
		$handle = gzopen( $path, 'ab' );

		if ( false === $handle ) {
			throw new RuntimeException( sprintf( 'Could not open %s for writing.', $path ) );
		}

		$written = gzwrite( $handle, $contents );

		if ( ! gzclose( $handle ) || strlen( $contents ) !== $written ) {
			throw new RuntimeException( sprintf( 'Could not write to %s. The disk may be full.', $path ) );
		}
	}

	/**
	 * @throws RuntimeException When the transfer cannot be saved.
	 */
	private function save( Transfer $export, string $path ): void {
		clearstatcache( true, $path );
		$export->state['bytes'] = (int) filesize( $path );
		$this->transfers->save( $export );
	}

	private static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}
}

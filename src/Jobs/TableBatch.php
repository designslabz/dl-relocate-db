<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Jobs;

use CraftRoq\Relocate\Database\TableLayout;
use CraftRoq\Relocate\Replace\Replacement;
use CraftRoq\Relocate\Replace\Replacer;
use RuntimeException;

/**
 * Reads one window of a table and works out what would change in it.
 *
 * Windows are ranges of the row key rather than LIMIT/OFFSET pages, so each
 * one costs the same no matter how far into a large table it is, and rows
 * added or removed meanwhile do not shift later windows. Within a window only
 * rows whose text columns LIKE-match a search value are fetched in full.
 *
 * For a live job those rows are read with FOR UPDATE inside the caller's
 * transaction, so nothing can change them between reading and writing.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading and writing the database directly is what this plugin is for, and results must never come from a cache.
 */
final class TableBatch {

	public function __construct(
		private \wpdb $wpdb,
		private Replacement $replacement,
		private Replacer $replacer,
		private int $size,
		private bool $lock_rows = false
	) {}

	/**
	 * @param array<string, string>|null $after Key of the last row already processed, or null to start.
	 * @throws RuntimeException On a database error.
	 */
	public function scan( TableLayout $layout, ?array $after ): BatchResult {
		$keys = $this->window_keys( $layout, $after );

		if ( ! $keys ) {
			return new BatchResult( 0, null, array(), array() );
		}

		$last    = end( $keys );
		$changed = array();
		$skipped = array();

		foreach ( $this->matching_rows( $layout, $after, $last ) as $row ) {
			$key    = array_intersect_key( $row, $layout->key );
			$before = array();
			$values = array();
			$counts = array();

			foreach ( array_keys( $layout->columns ) as $column ) {
				if ( null === $row[ $column ] ) {
					continue;
				}

				try {
					$result = $this->replacer->replace( $row[ $column ] );
				} catch ( RuntimeException ) {
					$skipped[] = array(
						'key'    => $key,
						'column' => $column,
						'reason' => 'search_error',
					);
					continue;
				}

				if ( null !== $result->skipped ) {
					$skipped[] = array(
						'key'    => $key,
						'column' => $column,
						'reason' => $result->skipped,
					);
				} elseif ( $result->value !== $row[ $column ] ) {
					$before[ $column ] = $row[ $column ];
					$values[ $column ] = $result->value;
					$counts[ $column ] = $result->count;
				}
			}

			if ( $counts ) {
				$changed[] = array(
					'key'    => $key,
					'before' => $before,
					'after'  => $values,
					'counts' => $counts,
				);
			}
		}

		// A short window means the end of the table was reached.
		return new BatchResult( count( $keys ), count( $keys ) < $this->size ? null : $last, $changed, $skipped );
	}

	/**
	 * Writes the new values of changed rows. Any failure throws, so the caller
	 * can roll the whole window back rather than leave it half applied.
	 *
	 * @param list<array{key: array<string, string>, before: array<string, string>, after: array<string, string>, counts: array<string, int>}> $rows
	 * @throws RuntimeException When a row cannot be updated.
	 */
	public function apply( TableLayout $layout, array $rows ): void {
		foreach ( $rows as $row ) {
			$updated = $this->wpdb->update(
				$layout->name,
				$row['after'],
				$row['key'],
				array_fill( 0, count( $row['after'] ), '%s' ),
				array_map( fn( bool $integer ): string => $integer ? '%d' : '%s', array_intersect_key( $layout->key, $row['key'] ) )
			);

			if ( false === $updated ) {
				// wpdb refuses, without a database error, values the column's character set cannot store.
				$error = '' !== $this->wpdb->last_error ? $this->wpdb->last_error : 'A new value contains characters the column cannot store.';

				throw new RuntimeException( $error );
			}
		}
	}

	/**
	 * @param array<string, string>|null $after
	 * @return list<array<string, string>>
	 */
	private function window_keys( TableLayout $layout, ?array $after ): array {
		$columns    = array_keys( $layout->key );
		$list       = implode( ', ', array_fill( 0, count( $columns ), '%i' ) );
		$conditions = array( $this->filters( $layout ) );

		if ( null !== $after ) {
			$conditions[] = $layout->compare( $after, '>' );
		}

		[ $where, $where_args ] = $this->all_of( $conditions );

		return $this->query(
			'SELECT ' . $list . ' FROM %i WHERE ' . $where . ' ORDER BY ' . $list . ' LIMIT %d',
			array_merge( $columns, array( $layout->name ), $where_args, $columns, array( $this->size ) )
		);
	}

	/**
	 * @param array<string, string>|null $after
	 * @param array<string, string>      $last
	 * @return list<array<string, string|null>>
	 */
	private function matching_rows( TableLayout $layout, ?array $after, array $last ): array {
		$columns    = array_merge( array_keys( $layout->key ), array_keys( $layout->columns ) );
		$conditions = array( $layout->compare( $last, '<=' ), $this->like( $layout ), $this->filters( $layout ) );

		if ( null !== $after ) {
			$conditions[] = $layout->compare( $after, '>' );
		}

		[ $where, $where_args ] = $this->all_of( $conditions );

		return $this->query(
			'SELECT ' . implode( ', ', array_fill( 0, count( $columns ), '%i' ) ) . ' FROM %i WHERE ' . $where . ( $this->lock_rows ? ' FOR UPDATE' : '' ),
			array_merge( $columns, array( $layout->name ), $where_args )
		);
	}

	/**
	 * The layout's row filters as IN / NOT IN conditions.
	 *
	 * @return array{0: string, 1: list<string>}
	 */
	private function filters( TableLayout $layout ): array {
		$parts = array();
		$args  = array();

		foreach ( array(
			'IN'     => $layout->only,
			'NOT IN' => $layout->except,
		) as $operator => $filters ) {
			foreach ( $filters as $column => $values ) {
				$parts[] = '%i ' . $operator . ' (' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')';
				$args    = array_merge( $args, array( $column ), $values );
			}
		}

		return array( $parts ? implode( ' AND ', $parts ) : '1 = 1', $args );
	}

	/**
	 * @param list<array{0: string, 1: list<string>}> $conditions
	 * @return array{0: string, 1: list<string>}
	 */
	private function all_of( array $conditions ): array {
		return array(
			implode( ' AND ', array_column( $conditions, 0 ) ),
			array_merge( ...array_column( $conditions, 1 ) ),
		);
	}

	/**
	 * Cheap pre-filter so only rows that could match are pulled into PHP. With a
	 * case-insensitive (_ci) collation LIKE matches a superset of what the
	 * Replacer will, which is fine. Case-sensitive (_bin, _cs, JSON) columns are
	 * lowercased when the search ignores case, or they would be missed.
	 *
	 * @return array{0: string, 1: list<string>}
	 */
	private function like( TableLayout $layout ): array {
		$parts = array();
		$args  = array();

		foreach ( $layout->columns as $column => $collation ) {
			$lower = ! $this->replacement->case_sensitive
				&& ( '' === $collation || str_ends_with( $collation, '_bin' ) || str_ends_with( $collation, '_cs' ) );

			foreach ( $this->replacement->pairs as $pair ) {
				$parts[] = $lower ? 'LOWER(%i) LIKE LOWER(%s)' : '%i LIKE %s';
				$args[]  = $column;
				$args[]  = '%' . $this->wpdb->esc_like( $pair['search'] ) . '%';
			}
		}

		return array( '(' . implode( ' OR ', $parts ) . ')', $args );
	}

	/**
	 * @param list<string|int> $args
	 * @return list<array<string, string|null>>
	 * @throws RuntimeException On a database error.
	 */
	private function query( string $sql, array $args ): array {
		$wpdb = $this->wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built above from placeholders only; every name and value is in $args.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( $wpdb->last_error );
		}

		return $rows;
	}
}

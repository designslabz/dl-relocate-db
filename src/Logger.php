<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate;

/**
 * Writes diagnostic entries to the plugin's log table.
 *
 * Messages are for developers and stay in English. Never pass database cell
 * contents in the context: identify rows by table, column and key instead.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading and writing the database directly is what this plugin is for, and results must never come from a cache.
 */
final class Logger {

	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const INFO    = 'info';

	public function __construct( private \wpdb $wpdb ) {}

	/**
	 * @param array<string, mixed> $context Extra details, stored as JSON.
	 */
	public function error( string $message, array $context = array(), ?int $job_id = null ): void {
		$this->log( self::ERROR, $message, $context, $job_id );
	}

	/**
	 * @param array<string, mixed> $context Extra details, stored as JSON.
	 */
	public function warning( string $message, array $context = array(), ?int $job_id = null ): void {
		$this->log( self::WARNING, $message, $context, $job_id );
	}

	/**
	 * @param array<string, mixed> $context Extra details, stored as JSON.
	 */
	public function info( string $message, array $context = array(), ?int $job_id = null ): void {
		$this->log( self::INFO, $message, $context, $job_id );
	}

	/**
	 * @param string $search Matched against the message and its details.
	 * @return array{0: list<object>, 1: int} The page of entries and the total number of matching entries.
	 */
	public function entries( int $page, int $per_page, ?int $job_id = null, ?string $level = null, string $search = '', string $order = 'desc', string $orderby = 'id' ): array {
		$wpdb       = $this->wpdb;
		$conditions = array();
		$args       = array( $this->table() );

		if ( null !== $job_id ) {
			$conditions[] = 'job_id = %d';
			$args[]       = $job_id;
		}

		if ( null !== $level ) {
			$conditions[] = 'level = %s';
			$args[]       = $level;
		}

		if ( '' !== $search ) {
			$like         = '%' . $wpdb->esc_like( $search ) . '%';
			$conditions[] = '(message LIKE %s OR context LIKE %s)';
			array_push( $args, $like, $like );
		}

		$where   = $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';
		$orderby = 'level' === $orderby ? 'level' : 'id';
		$order   = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';

		// $where holds only placeholders, with their values in $args; $order is ASC or DESC.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i' . $where . ' ORDER BY %i ' . $order . ', id ' . $order . ' LIMIT %d OFFSET %d',
				array_merge( $args, array( $orderby, $per_page, max( 0, $page - 1 ) * $per_page ) )
			)
		);
		$total   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, $args ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array( $entries, $total );
	}

	public function delete_for_job( int $job_id ): void {
		$this->wpdb->delete( $this->table(), array( 'job_id' => $job_id ), array( '%d' ) );
	}

	/**
	 * @param string $cutoff UTC datetime.
	 */
	public function delete_before( string $cutoff ): void {
		$wpdb = $this->wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table(), $cutoff ) );
	}

	private function table(): string {
		return $this->wpdb->prefix . Installer::LOG_TABLE;
	}

	/**
	 * @param array<string, mixed> $context Extra details, stored as JSON.
	 */
	private function log( string $level, string $message, array $context, ?int $job_id ): void {
		$context = $context ? (string) wp_json_encode( $context, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES ) : null;

		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'job_id'     => $job_id,
				'level'      => $level,
				'message'    => $message,
				'context'    => $context,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);

		// If the log table itself is unavailable, the PHP error log is the only place left.
		if ( false === $inserted || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'CR Relocate DB [%s]%s %s %s', $level, $job_id ? " job {$job_id}:" : '', $message, (string) $context ) );
		}
	}
}

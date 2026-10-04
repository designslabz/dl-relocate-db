<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Admin;

use CraftRoq\Relocate\Logger;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The plugin's log on the History screen: searchable, sortable, paged, and
 * filterable by level or by job.
 */
final class LogTable extends \WP_List_Table {

	private const PER_PAGE = 50;

	public function __construct( private Logger $logger ) {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'created_at' => __( 'Time', 'cr-relocate-db' ),
			'level'      => __( 'Level', 'cr-relocate-db' ),
			'job_id'     => __( 'Job', 'cr-relocate-db' ),
			'message'    => __( 'Message', 'cr-relocate-db' ),
		);
	}

	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sorting.
		$orderby = isset( $_GET['orderby'] ) && 'level' === $_GET['orderby'] ? 'level' : 'id';
		$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		// phpcs:enable

		[ $this->items, $total ] = $this->logger->entries(
			$this->get_pagenum(),
			self::PER_PAGE,
			$this->job_filter(),
			$this->level_filter(),
			$this->search_term(),
			$order,
			$orderby
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'message' );
	}

	public function no_items(): void {
		if ( '' !== $this->search_term() || $this->job_filter() || $this->level_filter() ) {
			esc_html_e( 'No log entries match.', 'cr-relocate-db' );
			return;
		}

		esc_html_e( 'The log is empty.', 'cr-relocate-db' );
	}

	/**
	 * The job whose entries are shown, if the list is narrowed to one.
	 */
	public function job_filter(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$job = isset( $_GET['log_job'] ) ? absint( $_GET['log_job'] ) : 0;

		return $job ? $job : null;
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array( 'id', true ),
			'level'      => array( 'level', false ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current = $this->level_filter();
		$links   = array();

		foreach ( array( '' => __( 'All', 'cr-relocate-db' ) ) + self::levels() as $level => $label ) {
			$args = array_filter(
				array(
					'view'    => 'log',
					'level'   => $level,
					'log_job' => (int) $this->job_filter(),
				)
			);

			$links[ '' === $level ? 'all' : $level ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( Admin::url( 'history', $args ) ),
				( '' === $level ? null : $level ) === $current ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}

		return $links;
	}

	/**
	 * @param object $entry Log row.
	 */
	protected function column_created_at( object $entry ): string {
		return esc_html( Admin::format_date( $entry->created_at ) );
	}

	/**
	 * @param object $entry Log row.
	 */
	protected function column_level( object $entry ): string {
		return self::level_badge( (string) $entry->level );
	}

	/**
	 * @param object $entry Log row.
	 */
	protected function column_job_id( object $entry ): string {
		return $entry->job_id
			? sprintf( '<a href="%1$s">#%2$d</a>', esc_url( Admin::job_url( (int) $entry->job_id ) ), (int) $entry->job_id )
			: '—';
	}

	/**
	 * @param object $entry Log row.
	 */
	protected function column_message( object $entry ): string {
		return self::message( $entry );
	}

	/**
	 * Message plus its context, collapsed. Shared with the job page.
	 *
	 * @param object $entry Log row.
	 * @return string Escaped HTML.
	 */
	public static function message( object $entry ): string {
		$html    = esc_html( (string) $entry->message );
		$context = json_decode( (string) $entry->context, true );

		if ( is_array( $context ) && $context ) {
			$html .= sprintf(
				'<details class="crq-log-context"><summary>%1$s</summary><pre>%2$s</pre></details>',
				esc_html__( 'Details', 'cr-relocate-db' ),
				esc_html( (string) wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
			);
		}

		return $html;
	}

	/**
	 * @return string Escaped HTML.
	 */
	public static function level_badge( string $level ): string {
		[ $icon, $label ] = match ( $level ) {
			Logger::ERROR   => array( 'warning', __( 'Error', 'cr-relocate-db' ) ),
			Logger::WARNING => array( 'flag', __( 'Warning', 'cr-relocate-db' ) ),
			default         => array( 'info-outline', __( 'Info', 'cr-relocate-db' ) ),
		};

		return sprintf(
			'<span class="crq-badge crq-badge-%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span> %3$s</span>',
			esc_attr( $level ),
			esc_attr( $icon ),
			esc_html( $label )
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function levels(): array {
		return array(
			Logger::ERROR   => __( 'Errors', 'cr-relocate-db' ),
			Logger::WARNING => __( 'Warnings', 'cr-relocate-db' ),
			Logger::INFO    => __( 'Information', 'cr-relocate-db' ),
		);
	}

	private function search_term(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		return isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
	}

	private function level_filter(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';

		return isset( self::levels()[ $level ] ) ? $level : null;
	}
}

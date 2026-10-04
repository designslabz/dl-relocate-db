<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Admin;

use CraftRoq\Relocate\Database\Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The database's tables on the Database screen. Every table is already in
 * memory from information_schema, so searching, sorting and paging happen here.
 */
final class TablesTable extends \WP_List_Table {

	private const PER_PAGE = 50;

	/**
	 * @param Table[] $tables
	 */
	public function __construct( private array $tables ) {
		parent::__construct(
			array(
				'singular' => 'table',
				'plural'   => 'tables',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'name'      => __( 'Table', 'cr-relocate-db' ),
			'engine'    => __( 'Engine', 'cr-relocate-db' ),
			'rows'      => __( 'Rows (approx.)', 'cr-relocate-db' ),
			'size'      => __( 'Size', 'cr-relocate-db' ),
			'collation' => __( 'Collation', 'cr-relocate-db' ),
		);
	}

	public function prepare_items(): void {
		$search = $this->search_term();
		$group  = $this->group_filter();

		$tables = array_filter(
			$this->tables,
			fn( Table $table ): bool => ( '' === $search || false !== stripos( $table->name, $search ) )
				&& ( null === $group || $group === $table->prefixed )
		);

		[ $orderby, $order ] = $this->sorting();

		usort(
			$tables,
			function ( Table $a, Table $b ) use ( $orderby, $order ): int {
				$result = match ( $orderby ) {
					'engine'    => strcasecmp( $a->engine, $b->engine ),
					'rows'      => $a->approx_rows <=> $b->approx_rows,
					'size'      => $a->size() <=> $b->size(),
					'collation' => strcasecmp( $a->collation, $b->collation ),
					default     => strnatcasecmp( $a->name, $b->name ),
				};

				return 'desc' === $order ? -$result : $result;
			}
		);

		$this->items = array_slice( $tables, ( $this->get_pagenum() - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$this->set_pagination_args(
			array(
				'total_items' => count( $tables ),
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
	}

	public function no_items(): void {
		esc_html_e( 'No tables match your search.', 'cr-relocate-db' );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'name'      => array( 'name', false ),
			'engine'    => array( 'engine', false ),
			'rows'      => array( 'rows', true ),
			'size'      => array( 'size', true ),
			'collation' => array( 'collation', false ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current = $this->group_filter();
		$counts  = array(
			'all'   => count( $this->tables ),
			'core'  => count( array_filter( $this->tables, fn( Table $table ): bool => $table->prefixed ) ),
			'other' => count( array_filter( $this->tables, fn( Table $table ): bool => ! $table->prefixed ) ),
		);
		$views   = array(
			'all'   => array( null, __( 'All', 'cr-relocate-db' ) ),
			'core'  => array( true, __( 'WordPress prefix', 'cr-relocate-db' ) ),
			'other' => array( false, __( 'Other', 'cr-relocate-db' ) ),
		);

		$links = array();
		foreach ( $views as $key => [ $prefixed, $label ] ) {
			$links[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( Admin::url( 'settings', array( 'view' => 'database' ) + ( 'all' === $key ? array() : array( 'group' => $key ) ) ) ),
				$current === $prefixed ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $key ] ) )
			);
		}

		return $links;
	}

	protected function column_name( Table $table ): string {
		return '<code>' . esc_html( $table->name ) . '</code>';
	}

	protected function column_engine( Table $table ): string {
		return esc_html( '' !== $table->engine ? $table->engine : '—' );
	}

	protected function column_rows( Table $table ): string {
		return esc_html( number_format_i18n( $table->approx_rows ) );
	}

	protected function column_size( Table $table ): string {
		return esc_html( (string) size_format( $table->size(), 1 ) );
	}

	protected function column_collation( Table $table ): string {
		return esc_html( '' !== $table->collation ? $table->collation : '—' );
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function sorting(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sorting.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name';
		$order   = isset( $_GET['order'] ) && 'desc' === $_GET['order'] ? 'desc' : 'asc';
		// phpcs:enable

		return array( $orderby, $order );
	}

	private function search_term(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		return isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
	}

	private function group_filter(): ?bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$group = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : '';

		return match ( $group ) {
			'core'  => true,
			'other' => false,
			default => null,
		};
	}
}

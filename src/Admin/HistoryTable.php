<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Admin;

use CraftRoq\Relocate\Jobs\Job;
use CraftRoq\Relocate\Jobs\JobRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The list of past jobs on the History screen: searchable, sortable, paged,
 * with single and bulk delete.
 */
final class HistoryTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct( private JobRepository $jobs ) {
		parent::__construct(
			array(
				'singular' => 'job',
				'plural'   => 'jobs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox">',
			'job'          => __( 'Job', 'cr-relocate-db' ),
			'tables'       => __( 'Tables', 'cr-relocate-db' ),
			'rows_changed' => __( 'Rows changed', 'cr-relocate-db' ),
			'replacements' => __( 'Replacements', 'cr-relocate-db' ),
			'status'       => __( 'Status', 'cr-relocate-db' ),
		);
	}

	public function prepare_items(): void {
		[ $orderby, $order ] = $this->sorting();

		[ $this->items, $total ] = $this->jobs->page( $this->get_pagenum(), self::PER_PAGE, $this->type_filter(), $orderby, $order, $this->search_term() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'job' );
	}

	public function no_items(): void {
		if ( '' !== $this->search_term() ) {
			esc_html_e( 'No jobs match your search.', 'cr-relocate-db' );
			return;
		}

		esc_html_e( 'No jobs yet. Run a dry run from Search & Replace to get started.', 'cr-relocate-db' );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'job'          => array( 'id', true ),
			'rows_changed' => array( 'rows_changed', true ),
			'replacements' => array( 'replacements', true ),
			'status'       => array( 'status', false ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array( 'delete' => __( 'Delete', 'cr-relocate-db' ) );
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current = $this->type_filter();
		$views   = array(
			'all'  => array( null, __( 'All', 'cr-relocate-db' ) ),
			'dry'  => array( true, __( 'Dry runs', 'cr-relocate-db' ) ),
			'live' => array( false, __( 'Replacements', 'cr-relocate-db' ) ),
		);

		$links = array();
		foreach ( $views as $key => [ $dry_run, $label ] ) {
			$links[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( Admin::url( 'history', 'all' === $key ? array() : array( 'type' => $key ) ) ),
				$current === $dry_run ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}

		return $links;
	}

	/**
	 * @param Job $job Row item.
	 */
	protected function column_cb( $job ): string {
		if ( ! Admin::can_delete( $job ) ) {
			return '';
		}

		return sprintf(
			'<label class="screen-reader-text" for="crq-job-%1$d">%2$s</label><input type="checkbox" id="crq-job-%1$d" name="job_ids[]" value="%1$d">',
			(int) $job->id,
			/* translators: %s: job title, e.g. Dry run #12. */
			esc_html( sprintf( __( 'Select %s', 'cr-relocate-db' ), Admin::job_title( $job ) ) )
		);
	}

	protected function column_job( Job $job ): string {
		$actions = array(
			'view' => sprintf( '<a href="%1$s">%2$s</a>', esc_url( Admin::job_url( $job->id ) ), esc_html__( 'View', 'cr-relocate-db' ) ),
		);

		if ( Admin::can_delete( $job ) ) {
			$actions['delete'] = sprintf(
				'<a href="%1$s" class="crq-delete-job" data-job="%2$s">%3$s</a>',
				esc_url( Admin::delete_url( $job->id ) ),
				esc_attr( Admin::job_title( $job ) ),
				esc_html__( 'Delete', 'cr-relocate-db' )
			);
		}

		// What changed leads; the job number and date are secondary.
		return sprintf(
			'<a href="%1$s" class="crq-job-link">%2$s</a><span class="crq-job-meta"><span class="crq-type crq-type-%3$s">%4$s</span> %5$s</span>%6$s',
			esc_url( Admin::job_url( $job->id ) ),
			Admin::job_change( $job ),
			$job->dry_run ? 'dry' : 'live',
			esc_html( Admin::job_title( $job ) ),
			esc_html( Admin::format_date( $job->created_at ) ),
			$this->row_actions( $actions )
		);
	}

	protected function column_tables( Job $job ): string {
		return esc_html( number_format_i18n( count( $job->settings['tables'] ) ) );
	}

	protected function column_rows_changed( Job $job ): string {
		return esc_html( number_format_i18n( $job->report->totals()['rows_changed'] ) );
	}

	protected function column_replacements( Job $job ): string {
		return esc_html( number_format_i18n( $job->report->totals()['replacements'] ) );
	}

	protected function column_status( Job $job ): string {
		return Admin::status_badge( $job );
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function sorting(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sorting.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable

		return array( in_array( $orderby, JobRepository::SORTABLE, true ) ? $orderby : 'id', 'asc' === $order ? 'asc' : 'desc' );
	}

	private function search_term(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		return isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
	}

	private function type_filter(): ?bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';

		return match ( $type ) {
			'dry'   => true,
			'live'  => false,
			default => null,
		};
	}
}

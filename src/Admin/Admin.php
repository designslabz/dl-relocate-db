<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Admin;

use CraftRoq\Relocate\Database\Schema;
use CraftRoq\Relocate\Jobs\BeforeImage;
use CraftRoq\Relocate\Jobs\Cleanup;
use CraftRoq\Relocate\Jobs\Job;
use CraftRoq\Relocate\Jobs\JobRepository;
use CraftRoq\Relocate\Jobs\JobStatus;
use CraftRoq\Relocate\Logger;
use CraftRoq\Relocate\Plugin;
use CraftRoq\Relocate\Rest\JobFormatter;
use CraftRoq\Relocate\Settings;
use CraftRoq\Relocate\Storage;
use RuntimeException;

/**
 * The plugin's own admin menu: Dashboard, Search & Replace, Import / Export,
 * History and Settings (which also holds the database overview and system status).
 */
final class Admin {

	public const PAGE = 'cr-relocate-db';

	private const DOWNLOAD_ACTION = 'crq_relocate_before_image';

	private const DELETE_ACTION = 'crq_relocate_delete_jobs';

	private const QUICK_ACTION = 'crq_relocate_quick';

	/** @var array<string, string> Hook suffix => section. */
	private array $hooks = array();

	public function __construct(
		private string $file,
		private Schema $schema,
		private JobRepository $jobs,
		private BeforeImage $before_images,
		private JobFormatter $formatter,
		private Logger $logger,
		private Settings $settings,
		private ImportExport $import_export
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'download_before_image' ) );
	}

	/**
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public static function url( string $section = 'dashboard', array $args = array() ): string {
		return add_query_arg( array( 'page' => self::page_slug( $section ) ) + $args, admin_url( 'admin.php' ) );
	}

	public static function job_url( int $job_id ): string {
		return self::url( 'history', array( 'job' => $job_id ) );
	}

	/**
	 * Raw URL, not HTML-escaped (wp_nonce_url() would add &amp;): it is also sent as JSON.
	 */
	public static function before_image_url( int $job_id ): string {
		return add_query_arg(
			array(
				'action'   => self::DOWNLOAD_ACTION,
				'job'      => $job_id,
				'_wpnonce' => wp_create_nonce( self::DOWNLOAD_ACTION . '_' . $job_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function delete_url( int $job_id ): string {
		return self::url(
			'history',
			array(
				'crq_action' => 'delete',
				'job_ids'    => $job_id,
				'_wpnonce'   => wp_create_nonce( self::DELETE_ACTION ),
			)
		);
	}

	public static function job_title( Job $job ): string {
		return $job->dry_run
			/* translators: %d: job number. */
			? sprintf( __( 'Dry run #%d', 'cr-relocate-db' ), $job->id )
			/* translators: %d: job number. */
			: sprintf( __( 'Replacement #%d', 'cr-relocate-db' ), $job->id );
	}

	/**
	 * What a job changes, e.g. "old.test → new.test (+1 more)", so lists say
	 * more than a job number.
	 *
	 * @return string Escaped HTML.
	 */
	public static function job_change( Job $job ): string {
		$more = count( $job->pairs() ) - 1;

		return sprintf(
			'<span class="crq-change-line"><code>%1$s</code> <span aria-hidden="true">→</span><span class="screen-reader-text">%2$s</span> <code>%3$s</code>%4$s</span>',
			esc_html( self::excerpt( $job->search ) ),
			esc_html__( 'replaced with', 'cr-relocate-db' ),
			esc_html( '' === $job->replace ? __( '(removed)', 'cr-relocate-db' ) : self::excerpt( $job->replace ) ),
			$more > 0
				/* translators: %s: number of further search and replacement pairs. */
				? ' <span class="crq-more">' . esc_html( sprintf( _n( '+ %s more', '+ %s more', $more, 'cr-relocate-db' ), number_format_i18n( $more ) ) ) . '</span>'
				: ''
		);
	}

	private static function excerpt( string $text ): string {
		return mb_strlen( $text ) > 48 ? mb_substr( $text, 0, 47 ) . '…' : $text;
	}

	/**
	 * @param string|null $utc UTC datetime as stored.
	 */
	public static function format_date( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return '—';
		}

		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $utc . ' UTC' ) );
	}

	/**
	 * Status as an icon plus text, so it never relies on colour alone.
	 *
	 * @return string Escaped HTML.
	 */
	public static function status_badge( Job $job ): string {
		[ $key, $icon, $label ] = $job->is_interrupted()
			? array( 'interrupted', 'warning', __( 'Interrupted', 'cr-relocate-db' ) )
			: match ( $job->status ) {
				JobStatus::Completed => array( 'completed', 'yes-alt', $job->status->label() ),
				JobStatus::Failed    => array( 'failed', 'warning', $job->status->label() ),
				JobStatus::Cancelled => array( 'cancelled', 'dismiss', $job->status->label() ),
				default              => array( 'running', 'update', $job->status->label() ),
			};

		return sprintf(
			'<span class="crq-badge crq-badge-%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span> %3$s</span>',
			esc_attr( $key ),
			esc_attr( $icon ),
			esc_html( $label )
		);
	}

	/**
	 * A job that is being processed right now must not be deleted from under the runner.
	 */
	public static function can_delete( Job $job ): bool {
		return $job->status->is_finished() || $job->is_interrupted();
	}

	/**
	 * Streams a job's before-image file. The files are never linked directly.
	 */
	public function download_before_image(): void {
		$job_id = isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;

		check_admin_referer( self::DOWNLOAD_ACTION . '_' . $job_id );

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to download this file.', 'cr-relocate-db' ), '', array( 'response' => 403 ) );
		}

		$job  = $this->jobs->find( $job_id );
		$path = $job ? $this->before_images->path( $job->before_image ) : null;

		if ( ! $path ) {
			wp_die( esc_html__( 'That file no longer exists.', 'cr-relocate-db' ), '', array( 'response' => 404 ) );
		}

		Storage::send( $path, 'relocate-job-' . $job_id . '-original-values.sql.gz', 'application/gzip' );
	}

	public function add_pages(): void {
		$sections = $this->sections();

		$hook                 = add_menu_page(
			__( 'CR Relocate DB', 'cr-relocate-db' ),
			__( 'Relocate DB', 'cr-relocate-db' ),
			Plugin::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' ),
			$this->menu_icon(),
			80
		);
		$this->hooks[ $hook ] = 'dashboard';

		foreach ( $sections as $section => $label ) {
			$hook = (string) add_submenu_page(
				self::PAGE,
				$label . ' ‹ ' . __( 'CR Relocate DB', 'cr-relocate-db' ),
				$label,
				Plugin::CAPABILITY,
				self::page_slug( $section ),
				array( $this, 'render_page' )
			);

			$this->hooks[ $hook ] = $section;
		}

		$history = array_search( 'history', $this->hooks, true );
		if ( false !== $history ) {
			add_action( 'load-' . $history, array( $this, 'handle_history_actions' ) );
		}
	}

	/**
	 * Deletes jobs from a row link or the bulk action, then redirects back.
	 * Runs before any output, so it can redirect.
	 */
	public function handle_history_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only reads which action was asked for; the nonce is checked before acting.
		$row  = isset( $_GET['crq_action'] ) && 'delete' === $_GET['crq_action'];
		$bulk = ( isset( $_GET['action'] ) && 'delete' === $_GET['action'] ) || ( isset( $_GET['action2'] ) && 'delete' === $_GET['action2'] );
		// phpcs:enable

		if ( ! $row && ! $bulk ) {
			return;
		}

		check_admin_referer( $row ? self::DELETE_ACTION : 'bulk-jobs' );

		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to delete jobs.', 'cr-relocate-db' ), '', array( 'response' => 403 ) );
		}

		$ids     = isset( $_GET['job_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_GET['job_ids'] ) ) : array();
		$deleted = 0;
		$kept    = 0;

		foreach ( array_filter( $ids ) as $id ) {
			$job = $this->jobs->find( $id );

			if ( ! $job ) {
				continue;
			}

			if ( ! self::can_delete( $job ) ) {
				++$kept;
				continue;
			}

			$this->jobs->delete( $job->id );
			$this->logger->delete_for_job( $job->id );
			$this->before_images->delete( $job->before_image );
			++$deleted;
		}

		wp_safe_redirect(
			self::url(
				'history',
				array(
					'deleted' => $deleted,
					'kept'    => $kept,
				)
			)
		);
		exit;
	}

	public function enqueue_assets( string $hook_suffix ): void {
		$section = $this->hooks[ $hook_suffix ] ?? null;

		if ( null === $section ) {
			return;
		}

		wp_enqueue_style( 'crq-relocate-admin', plugins_url( 'assets/css/admin.css', $this->file ), array( 'dashicons' ), Plugin::VERSION );

		if ( 'search-replace' === $section || ( 'history' === $section && $this->requested_job_id() ) ) {
			wp_enqueue_script(
				'crq-relocate-search-replace',
				plugins_url( 'assets/js/search-replace.js', $this->file ),
				array( 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ),
				Plugin::VERSION,
				array( 'in_footer' => true )
			);
			wp_set_script_translations( 'crq-relocate-search-replace', 'cr-relocate-db', dirname( $this->file ) . '/languages' );
		}

		if ( 'import-export' === $section ) {
			wp_enqueue_script(
				'crq-relocate-transfer',
				plugins_url( 'assets/js/transfer.js', $this->file ),
				array( 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ),
				Plugin::VERSION,
				array( 'in_footer' => true )
			);
			wp_set_script_translations( 'crq-relocate-transfer', 'cr-relocate-db', dirname( $this->file ) . '/languages' );
		}

		if ( 'history' === $section ) {
			wp_enqueue_script( 'crq-relocate-history', plugins_url( 'assets/js/history.js', $this->file ), array( 'wp-i18n' ), Plugin::VERSION, array( 'in_footer' => true ) );
			wp_set_script_translations( 'crq-relocate-history', 'cr-relocate-db', dirname( $this->file ) . '/languages' );
		}
	}

	public function render_page(): void {
		$sections = $this->sections();
		$current  = $this->current_section();

		try {
			$args = match ( $current ) {
				'dashboard'      => $this->dashboard_args(),
				'search-replace' => $this->search_replace_args(),
				'import-export'  => $this->import_export->args(),
				'history'        => $this->history_args(),
				'settings'       => $this->settings_args(),
				default          => array(),
			};
		} catch ( RuntimeException $e ) {
			$args = array( 'error' => $e->getMessage() );
		}

		$this->template(
			'page',
			array(
				'sections' => $sections,
				'current'  => $current,
				'logo'     => plugins_url( 'assets/images/logo.svg', $this->file ),
			) + $args
		);
	}

	/**
	 * The plugin's logo mark as a one-colour SVG. WordPress repaints a base64 SVG
	 * menu icon to match the admin colour scheme, so it looks native in the menu.
	 */
	private function menu_icon(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A small file inside the plugin.
		$svg = (string) file_get_contents( dirname( $this->file ) . '/assets/images/menu-icon.svg' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The data URI format WordPress requires for menu icons.
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	private static function page_slug( string $section ): string {
		return 'dashboard' === $section ? self::PAGE : self::PAGE . '-' . $section;
	}

	/**
	 * @return array<string, string> Section => label.
	 */
	private function sections(): array {
		return array(
			'dashboard'      => __( 'Dashboard', 'cr-relocate-db' ),
			'search-replace' => __( 'Search & Replace', 'cr-relocate-db' ),
			'import-export'  => __( 'Import / Export', 'cr-relocate-db' ),
			'history'        => __( 'History', 'cr-relocate-db' ),
			'settings'       => __( 'Settings', 'cr-relocate-db' ),
		);
	}

	private function current_section(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		foreach ( array_keys( $this->sections() ) as $section ) {
			if ( self::page_slug( $section ) === $page ) {
				return $section;
			}
		}

		return 'dashboard';
	}

	private function requested_job_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		return isset( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dashboard_args(): array {
		[ $recent ] = $this->jobs->page( 1, 5 );

		return array(
			'attention'    => $this->jobs->needing_attention(),
			'recent'       => $recent,
			'jobs'         => $this->jobs->stats()['jobs'],
			'quick_action' => self::QUICK_ACTION,
		);
	}

	/**
	 * Settings has three views: the settings form, the database overview and system status.
	 *
	 * @return array<string, mixed>
	 */
	private function settings_args(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';

		return match ( $view ) {
			'database' => array( 'view' => 'database' ) + $this->database_args(),
			'status'   => array( 'view' => 'status' ) + $this->status_args(),
			default    => array( 'view' => 'general' ),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function status_args(): array {
		$uploads   = wp_upload_dir( null, false );
		$retention = $this->settings->retention_days();
		$cleanup   = wp_next_scheduled( Cleanup::HOOK );

		return array(
			// What can affect a replacement, each marked ok, warning or info.
			'checks'      => array(
				wp_is_writable( $uploads['basedir'] )
					? array( 'ok', __( 'Folder for original values', 'cr-relocate-db' ), __( 'Writable', 'cr-relocate-db' ) )
					: array( 'warning', __( 'Folder for original values', 'cr-relocate-db' ), __( 'Not writable: replacements can only run without saving original values.', 'cr-relocate-db' ) ),
				array( 'ok', __( 'PHP time limit', 'cr-relocate-db' ), $this->time_limit() ),
				array( 'ok', __( 'PHP memory limit', 'cr-relocate-db' ), (string) ini_get( 'memory_limit' ) ),
				array(
					'info',
					__( 'Persistent object cache', 'cr-relocate-db' ),
					wp_using_ext_object_cache()
						? __( 'Yes. It is flushed after every replacement.', 'cr-relocate-db' )
						: __( 'No', 'cr-relocate-db' ),
				),
				array(
					'info',
					__( 'History clean-up', 'cr-relocate-db' ),
					0 === $retention
						? __( 'Off: all history is kept.', 'cr-relocate-db' )
						: sprintf(
							/* translators: 1: number of days, 2: date of the next clean-up. */
							_n( 'After %1$s day. Next run: %2$s', 'After %1$s days. Next run: %2$s', $retention, 'cr-relocate-db' ),
							number_format_i18n( $retention ),
							$cleanup ? self::format_date( gmdate( 'Y-m-d H:i:s', $cleanup ) ) : __( 'not scheduled yet', 'cr-relocate-db' )
						),
				),
			),
			'environment' => array(
				__( 'Plugin', 'cr-relocate-db' )    => Plugin::VERSION,
				__( 'WordPress', 'cr-relocate-db' ) => get_bloginfo( 'version' ),
				__( 'PHP', 'cr-relocate-db' )       => PHP_VERSION,
				__( 'Database', 'cr-relocate-db' )  => $this->schema->server_info()['version'],
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function search_replace_args(): array {
		$prefill = array(
			'search'  => '',
			'replace' => '',
		);

		// Values sent from the dashboard's quick form. Only used to fill in the fields.
		if ( isset( $_POST['_wpnonce'], $_POST['search'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), self::QUICK_ACTION ) ) {
			// Search values are matched byte for byte, so they are deliberately not sanitized. They are escaped on output.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$prefill['search'] = (string) wp_unslash( $_POST['search'] );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$prefill['replace'] = isset( $_POST['replace'] ) ? (string) wp_unslash( $_POST['replace'] ) : '';
		}

		return array(
			'tables'  => $this->schema->searchable_tables(),
			'columns' => $this->schema->searchable_columns(),
			'prefix'  => $this->schema->server_info()['prefix'],
			'prefill' => $prefill,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function history_args(): array {
		$job_id = $this->requested_job_id();

		if ( $job_id ) {
			$job = $this->jobs->find( $job_id );

			if ( ! $job ) {
				return array( 'error' => __( 'That job does not exist. It may have been deleted or removed by the history clean-up.', 'cr-relocate-db' ) );
			}

			[ $logs, $log_total ] = $this->logger->entries( 1, 20, $job->id );

			return array(
				'view'      => 'job',
				'job'       => $job,
				'job_data'  => $this->formatter->format( $job ),
				'child_id'  => $job->dry_run ? $this->jobs->child_id( $job->id ) : null,
				'logs'      => $logs,
				'log_total' => $log_total,
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$view  = isset( $_GET['view'] ) && 'log' === $_GET['view'] ? 'log' : 'jobs';
		$table = 'log' === $view ? new LogTable( $this->logger ) : new HistoryTable( $this->jobs );
		$table->prepare_items();

		return array(
			'view'    => $view,
			'table'   => $table,
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Counts for the notice after a redirect.
			'deleted' => isset( $_GET['deleted'] ) ? absint( $_GET['deleted'] ) : null,
			'kept'    => isset( $_GET['kept'] ) ? absint( $_GET['kept'] ) : 0,
			// phpcs:enable
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function database_args(): array {
		$tables = $this->schema->tables();
		$list   = new TablesTable( $tables );
		$list->prepare_items();

		return array(
			'server' => $this->schema->server_info(),
			'list'   => $list,
			'count'  => count( $tables ),
			'size'   => array_sum( array_map( fn( $table ): int => $table->size(), $tables ) ),
			'rows'   => array_sum( array_map( fn( $table ): int => $table->approx_rows, $tables ) ),
		);
	}

	private function time_limit(): string {
		$limit = (int) ini_get( 'max_execution_time' );

		return 0 === $limit
			? __( 'None', 'cr-relocate-db' )
			/* translators: %s: number of seconds. */
			: sprintf( _n( '%s second', '%s seconds', $limit, 'cr-relocate-db' ), number_format_i18n( $limit ) );
	}

	/**
	 * @param array<string, mixed> $args Variables for the template, available as $args.
	 */
	private function template( string $name, array $args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Read by the template.
		require dirname( $this->file ) . '/templates/admin/' . $name . '.php';
	}
}

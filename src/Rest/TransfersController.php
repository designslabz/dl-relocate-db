<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Rest;

use CraftRoq\Relocate\Admin\Admin;
use CraftRoq\Relocate\Admin\ImportExport;
use CraftRoq\Relocate\Jobs\JobException;
use CraftRoq\Relocate\Plugin;
use CraftRoq\Relocate\Storage;
use CraftRoq\Relocate\Transfer\Transfer;
use CraftRoq\Relocate\Transfer\TransferRepository;
use CraftRoq\Relocate\Transfer\TransferRunner;
use CraftRoq\Relocate\Transfer\TransferStarter;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST routes for database exports and imports.
 *
 * An import can replace the users table and so end the session that started
 * it. Its steps therefore also accept the key handed out when it was created
 * (X-CRQ-Transfer-Key), which only ever works for that one import.
 */
final class TransfersController {

	public const KEY_HEADER = 'X-CRQ-Transfer-Key';

	// Tables whose replacement can log people out or move the site.
	private const SESSION_TABLES = array( 'users', 'usermeta', 'options' );

	public function __construct(
		private TransferRepository $transfers,
		private TransferRunner $runner,
		private TransferStarter $starter
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			JobsController::NAMESPACE,
			'/exports',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_export' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'scope' => array(
						'type'    => 'string',
						'enum'    => array( TransferStarter::SCOPE_CORE, TransferStarter::SCOPE_ALL ),
						'default' => TransferStarter::SCOPE_CORE,
					),
					'pairs' => self::pairs_schema(),
				),
			)
		);

		register_rest_route(
			JobsController::NAMESPACE,
			'/imports',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_import' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					// Explicit, so a stray request cannot overwrite the database.
					'confirmed' => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'pairs'     => self::pairs_schema(),
				),
			)
		);

		foreach ( array(
			''        => array( WP_REST_Server::READABLE, 'get_transfer' ),
			'/run'    => array( WP_REST_Server::CREATABLE, 'run_transfer' ),
			'/resume' => array( WP_REST_Server::CREATABLE, 'resume_transfer' ),
			'/cancel' => array( WP_REST_Server::CREATABLE, 'cancel_transfer' ),
		) as $path => [ $methods, $callback ] ) {
			register_rest_route(
				JobsController::NAMESPACE,
				'/transfers/(?P<id>\d+)' . $path,
				array(
					'methods'             => $methods,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'can_use_transfer' ),
				)
			);
		}
	}

	public function can_manage(): bool {
		return current_user_can( Plugin::CAPABILITY );
	}

	public function can_use_transfer( WP_REST_Request $request ): bool {
		if ( $this->can_manage() ) {
			return true;
		}

		$key      = (string) $request->get_header( self::KEY_HEADER );
		$transfer = '' === $key ? null : $this->transfers->find( (int) $request['id'] );

		return null !== $transfer
			&& ! $transfer->is_export()
			&& ! $transfer->status->is_finished()
			&& '' !== $transfer->token_hash
			&& hash_equals( $transfer->token_hash, hash( 'sha256', $key ) );
	}

	public function create_export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$export = $this->starter->export( (string) $request['scope'], $this->pairs( $request ) );
		} catch ( JobException $e ) {
			return new WP_Error( $e->error_code, $e->getMessage(), array( 'status' => $e->status ) );
		}

		return new WP_REST_Response( $this->format( $export ), 201 );
	}

	public function create_import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( true !== $request['confirmed'] ) {
			return new WP_Error( 'crq_relocate_not_confirmed', __( 'Confirm the import before starting it.', 'cr-relocate-db' ), array( 'status' => 400 ) );
		}

		$files = $request->get_file_params();

		try {
			[ $import, $key ] = $this->starter->import_upload( (array) ( $files['file'] ?? array() ), $this->pairs( $request ) );
		} catch ( JobException $e ) {
			return new WP_Error( $e->error_code, $e->getMessage(), array( 'status' => $e->status ) );
		}

		return new WP_REST_Response( array( 'key' => $key ) + $this->format( $import ), 201 );
	}

	public function get_transfer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$transfer = $this->transfers->find( (int) $request['id'] );

		return $transfer ? rest_ensure_response( $this->format( $transfer ) ) : $this->not_found();
	}

	public function run_transfer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->act( $request, fn( Transfer $transfer ): ?Transfer => $this->runner->step( $transfer ) );
	}

	public function resume_transfer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->act( $request, fn( Transfer $transfer ): ?Transfer => $this->runner->resume( $transfer ) );
	}

	public function cancel_transfer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->act( $request, fn( Transfer $transfer ): ?Transfer => $this->runner->cancel( $transfer ) );
	}

	/**
	 * What the Import / Export screen shows about a transfer.
	 *
	 * @return array<string, mixed>
	 */
	public function format( Transfer $transfer ): array {
		$data = array(
			'id'           => $transfer->id,
			'type'         => $transfer->type,
			'status'       => $transfer->status->value,
			'status_label' => $transfer->status->label(),
			'finished'     => $transfer->status->is_finished(),
			'interrupted'  => $transfer->is_interrupted(),
			'progress'     => $transfer->progress(),
			'error'        => $transfer->error_message,
			'created'      => Admin::format_date( $transfer->created_at ),
		);

		if ( $transfer->is_export() ) {
			$tables = $transfer->settings['tables'];
			$path   = Storage::path( $transfer->file );

			return $data + array(
				'tables_total'  => count( $tables ),
				'tables_done'   => min( (int) $transfer->state['table_index'], count( $tables ) ),
				'current_table' => $tables[ $transfer->state['table_index'] ] ?? null,
				'rows'          => (int) $transfer->state['rows'],
				'replacements'  => (int) $transfer->state['replacements'],
				'skipped'       => (int) $transfer->state['skipped'],
				'pairs'         => count( $transfer->settings['pairs'] ),
				'size'          => $path ? size_format( (int) filesize( $path ), 1 ) : null,
				'download_url'  => $path && 'completed' === $transfer->status->value ? ImportExport::export_url( $transfer->id ) : null,
			);
		}

		global $wpdb;
		$touched = array_map( fn( string $table ): string => substr( $table, strlen( $wpdb->prefix ) ), $transfer->state['tables'] );

		return $data + array(
			'name'         => $transfer->settings['name'],
			'phase'        => $transfer->state['phase'],
			'statements'   => (int) $transfer->state['statements'],
			'skipped'      => (int) $transfer->state['skipped'],
			'pairs'        => count( $transfer->settings['pairs'] ?? array() ),
			'replacements' => (int) ( $transfer->state['replacements'] ?? 0 ),
			'unchanged'    => (int) ( $transfer->state['unchanged'] ?? 0 ),
			'tables'       => count( $transfer->state['tables'] ),
			'relogin'      => (bool) array_intersect( self::SESSION_TABLES, $touched ),
			'login_url'    => wp_login_url( Admin::url( 'import-export' ) ),
		);
	}

	/**
	 * Text to change while exporting or importing, as search/replace objects.
	 *
	 * @return array<string, mixed>
	 */
	private static function pairs_schema(): array {
		return array(
			'type'    => 'array',
			'default' => array(),
			'items'   => array(
				'type'                 => 'object',
				'properties'           => array(
					'search'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'replace' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * @return list<array{0: string, 1: string}>
	 */
	private function pairs( WP_REST_Request $request ): array {
		return array_values(
			array_map(
				fn( array $pair ): array => array( (string) $pair['search'], (string) $pair['replace'] ),
				(array) $request['pairs']
			)
		);
	}

	/**
	 * @param callable(Transfer): ?Transfer $action
	 */
	private function act( WP_REST_Request $request, callable $action ): WP_REST_Response|WP_Error {
		$transfer = $this->transfers->find( (int) $request['id'] );

		if ( ! $transfer ) {
			return $this->not_found();
		}

		try {
			$transfer = $action( $transfer );
		} catch ( RuntimeException ) {
			return new WP_Error( 'crq_relocate_database_error', __( 'Progress could not be saved to the database.', 'cr-relocate-db' ), array( 'status' => 500 ) );
		}

		if ( ! $transfer ) {
			return new WP_Error( 'crq_relocate_job_busy', __( 'This is already being processed in another browser tab.', 'cr-relocate-db' ), array( 'status' => 409 ) );
		}

		return rest_ensure_response( $this->format( $transfer ) );
	}

	private function not_found(): WP_Error {
		return new WP_Error( 'crq_relocate_transfer_not_found', __( 'That export or import does not exist.', 'cr-relocate-db' ), array( 'status' => 404 ) );
	}
}

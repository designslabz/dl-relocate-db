<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Admin;

use CraftRoq\Relocate\Database\Schema;
use CraftRoq\Relocate\Plugin;
use CraftRoq\Relocate\Settings;
use CraftRoq\Relocate\Storage;
use CraftRoq\Relocate\Transfer\Transfer;
use CraftRoq\Relocate\Transfer\TransferRepository;

/**
 * The Import / Export screen's server side: what the screen shows, export
 * downloads, and the plugin settings file. Database exports and imports
 * themselves run over REST (see TransfersController).
 */
final class ImportExport {

	private const DOWNLOAD_ACTION        = 'crq_relocate_download_export';
	private const SETTINGS_EXPORT_ACTION = 'crq_relocate_export_settings';
	private const SETTINGS_IMPORT_ACTION = 'crq_relocate_import_settings';

	// A settings file is a few hundred bytes; anything much bigger is not one.
	private const MAX_SETTINGS_FILE = 65536;

	public function __construct(
		private TransferRepository $transfers,
		private Schema $schema,
		private Settings $settings
	) {}

	public function register(): void {
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'download_export' ) );
		add_action( 'admin_post_' . self::SETTINGS_EXPORT_ACTION, array( $this, 'export_settings' ) );
		add_action( 'admin_post_' . self::SETTINGS_IMPORT_ACTION, array( $this, 'import_settings' ) );
	}

	/**
	 * Raw URL, not HTML-escaped: it is also sent as JSON.
	 */
	public static function export_url( int $transfer_id ): string {
		return add_query_arg(
			array(
				'action'   => self::DOWNLOAD_ACTION,
				'transfer' => $transfer_id,
				'_wpnonce' => wp_create_nonce( self::DOWNLOAD_ACTION . '_' . $transfer_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function args(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation and a notice after a redirect.
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$notice = isset( $_GET['settings'] ) ? sanitize_key( wp_unslash( $_GET['settings'] ) ) : '';
		// phpcs:enable

		$tables = $this->schema->searchable_tables();

		return array(
			'view'            => in_array( $view, array( 'import', 'settings' ), true ) ? $view : 'export',
			'core_tables'     => count( array_filter( $tables, fn( $table ): bool => $table->prefixed ) ),
			'all_tables'      => count( $tables ),
			'prefix'          => $this->schema->server_info()['prefix'],
			'max_upload'      => (string) size_format( wp_max_upload_size() ),
			'exports'         => $this->transfers->recent( Transfer::EXPORT, 5 ),
			'settings_notice' => $notice,
			'actions'         => array(
				'settings_export' => self::SETTINGS_EXPORT_ACTION,
				'settings_import' => self::SETTINGS_IMPORT_ACTION,
			),
			'rest_root'       => esc_url_raw( rest_url() ),
		);
	}

	public function download_export(): void {
		$transfer_id = isset( $_GET['transfer'] ) ? absint( $_GET['transfer'] ) : 0;

		check_admin_referer( self::DOWNLOAD_ACTION . '_' . $transfer_id );
		$this->require_capability();

		$export = $this->transfers->find( $transfer_id );
		$path   = $export && $export->is_export() ? Storage::path( $export->file ) : null;

		if ( ! $path ) {
			wp_die( esc_html__( 'That file no longer exists.', 'cr-relocate-db' ), '', array( 'response' => 404 ) );
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$name = sanitize_file_name( $host . '-' . str_replace( array( ' ', ':' ), '-', (string) $export->created_at ) ) . '.sql.gz';

		Storage::send( $path, $name, 'application/gzip' );
	}

	public function export_settings(): void {
		check_admin_referer( self::SETTINGS_EXPORT_ACTION );
		$this->require_capability();

		$json = (string) wp_json_encode(
			array(
				'plugin'   => 'cr-relocate-db',
				'version'  => Plugin::VERSION,
				'exported' => gmdate( 'c' ),
				'settings' => $this->settings->sanitize( get_option( Settings::OPTION, array() ) ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="cr-relocate-db-settings.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON download, not HTML.
		exit;
	}

	public function import_settings(): void {
		check_admin_referer( self::SETTINGS_IMPORT_ACTION );
		$this->require_capability();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the temporary path is used, and checked below.
		$upload = isset( $_FILES['settings_file'] ) && is_array( $_FILES['settings_file'] ) ? $_FILES['settings_file'] : array();
		$path   = (string) ( $upload['tmp_name'] ?? '' );
		$result = 'invalid';

		if ( '' !== $path && is_uploaded_file( $path ) && filesize( $path ) <= self::MAX_SETTINGS_FILE ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A small uploaded file.
			$data = json_decode( (string) file_get_contents( $path ), true );

			if ( is_array( $data ) && 'cr-relocate-db' === ( $data['plugin'] ?? '' ) && is_array( $data['settings'] ?? null ) ) {
				update_option( Settings::OPTION, $this->settings->sanitize( $data['settings'] ) );
				$result = 'imported';
			}
		}

		wp_safe_redirect(
			Admin::url(
				'import-export',
				array(
					'view'     => 'settings',
					'settings' => $result,
				)
			)
		);
		exit;
	}

	private function require_capability(): void {
		if ( ! current_user_can( Plugin::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'cr-relocate-db' ), '', array( 'response' => 403 ) );
		}
	}
}

<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate;

use CraftRoq\Relocate\Jobs\Cleanup;

/**
 * Creates and upgrades the plugin's tables, and removes them on uninstall.
 *
 * Version 3 adds the transfers table for database exports and imports.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading and writing the database directly is what this plugin is for, and results must never come from a cache.
 */
final class Installer {

	/** Shared by every table this plugin creates, so they can be kept out of searches. */
	public const TABLE_PREFIX = 'crq_relocate_';

	public const JOBS_TABLE      = self::TABLE_PREFIX . 'jobs';
	public const LOG_TABLE       = self::TABLE_PREFIX . 'log';
	public const TRANSFERS_TABLE = self::TABLE_PREFIX . 'transfers';

	private const TABLES = array( self::JOBS_TABLE, self::LOG_TABLE, self::TRANSFERS_TABLE );

	private const DB_VERSION        = 3;
	private const DB_VERSION_OPTION = 'crq_relocate_db_version';

	public function __construct( private \wpdb $wpdb ) {}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	public function maybe_upgrade(): void {
		if ( (int) get_option( self::DB_VERSION_OPTION ) >= self::DB_VERSION ) {
			return;
		}

		$this->create_tables();

		foreach ( self::TABLES as $table ) {
			if ( ! $this->table_exists( $this->wpdb->prefix . $table ) ) {
				// Leave the stored version alone so the upgrade is retried on the next admin request.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'CR Relocate DB: could not create table %s: %s', $this->wpdb->prefix . $table, $this->wpdb->last_error ) );
				return;
			}
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	public function uninstall(): void {
		$wpdb = $this->wpdb;

		foreach ( self::TABLES as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}

		delete_option( self::DB_VERSION_OPTION );
		delete_option( Settings::OPTION );

		Storage::delete_all();
		Cleanup::unschedule();
	}

	private function create_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();
		$jobs_table      = $this->wpdb->prefix . self::JOBS_TABLE;
		$log_table       = $this->wpdb->prefix . self::LOG_TABLE;
		$transfers_table = $this->wpdb->prefix . self::TRANSFERS_TABLE;

		// dbDelta is picky: one column per line, two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$jobs_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				parent_id bigint(20) unsigned DEFAULT NULL,
				type varchar(32) NOT NULL DEFAULT 'search_replace',
				dry_run tinyint(1) unsigned NOT NULL DEFAULT 1,
				status varchar(20) NOT NULL DEFAULT 'pending',
				search longtext NOT NULL,
				replace_with longtext NOT NULL,
				settings longtext NOT NULL,
				state longtext NOT NULL,
				report longtext NOT NULL,
				rows_scanned bigint(20) unsigned NOT NULL DEFAULT 0,
				rows_changed bigint(20) unsigned NOT NULL DEFAULT 0,
				replacements bigint(20) unsigned NOT NULL DEFAULT 0,
				error_count int(10) unsigned NOT NULL DEFAULT 0,
				error_message text,
				before_image varchar(255) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				started_at datetime DEFAULT NULL,
				finished_at datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset_collate};

			CREATE TABLE {$log_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				job_id bigint(20) unsigned DEFAULT NULL,
				level varchar(10) NOT NULL,
				message text NOT NULL,
				context longtext,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY job_id (job_id),
				KEY created_at (created_at)
			) {$charset_collate};

			CREATE TABLE {$transfers_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(10) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				settings longtext NOT NULL,
				state longtext NOT NULL,
				file varchar(255) NOT NULL DEFAULT '',
				token_hash varchar(64) NOT NULL DEFAULT '',
				error_message text,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				finished_at datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset_collate};"
		);
	}

	private function table_exists( string $table ): bool {
		$wpdb = $this->wpdb;

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}

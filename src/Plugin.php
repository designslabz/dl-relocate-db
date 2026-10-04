<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate;

use CraftRoq\Relocate\Admin\Admin;
use CraftRoq\Relocate\Admin\ImportExport;
use CraftRoq\Relocate\Cli\Command;
use CraftRoq\Relocate\Database\Schema;
use CraftRoq\Relocate\Jobs\BeforeImage;
use CraftRoq\Relocate\Jobs\Cleanup;
use CraftRoq\Relocate\Jobs\JobRepository;
use CraftRoq\Relocate\Jobs\JobRunner;
use CraftRoq\Relocate\Jobs\JobStarter;
use CraftRoq\Relocate\Rest\JobFormatter;
use CraftRoq\Relocate\Rest\JobsController;
use CraftRoq\Relocate\Rest\TransfersController;
use CraftRoq\Relocate\Transfer\Exporter;
use CraftRoq\Relocate\Transfer\Importer;
use CraftRoq\Relocate\Transfer\TransferRepository;
use CraftRoq\Relocate\Transfer\TransferRunner;
use CraftRoq\Relocate\Transfer\TransferStarter;
use WP_CLI;

/**
 * Builds the plugin's objects and hooks them into WordPress.
 */
final class Plugin {

	public const VERSION = '0.1.0';

	/**
	 * Meta capability required for everything this plugin does.
	 *
	 * Mapped to manage_options + unfiltered_html: a search and replace can write
	 * arbitrary markup into the database, so it must not bypass a site's
	 * DISALLOW_UNFILTERED_HTML hardening.
	 */
	public const CAPABILITY = 'crq_relocate_manage';

	public function __construct(
		private string $file,
		private \wpdb $wpdb
	) {}

	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'map_capability' ), 10, 3 );

		$settings  = new Settings();
		$schema    = new Schema( $this->wpdb );
		$logger    = new Logger( $this->wpdb );
		$jobs      = new JobRepository( $this->wpdb );
		$images    = new BeforeImage( $this->wpdb );
		$runner    = new JobRunner( $this->wpdb, $schema, $jobs, $settings, $logger, $images );
		$formatter = new JobFormatter( $this->wpdb, $jobs, $schema, $images );
		$transfers = new TransferRepository( $this->wpdb );

		( new Installer( $this->wpdb ) )->register();
		( new Cleanup( $jobs, $images, $logger, $settings, $transfers ) )->register();
		$starter = new JobStarter( $jobs, $runner, $schema, $images, $logger );

		( new JobsController( $jobs, $runner, $starter, $formatter, $logger ) )->register();
		( new TransfersController(
			$transfers,
			new TransferRunner(
				$this->wpdb,
				$transfers,
				new Exporter( $this->wpdb, $schema, $transfers, $settings->batch_size() ),
				new Importer( $this->wpdb, $transfers ),
				$logger
			),
			new TransferStarter( $this->wpdb, $transfers, $schema, $logger )
		) )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'crq', new Command( new Installer( $this->wpdb ), $schema, $jobs, $runner, $starter, $formatter ) );
		}

		if ( is_admin() ) {
			$settings->register();

			$import_export = new ImportExport( $transfers, $schema, $settings );
			$import_export->register();

			( new Admin( $this->file, $schema, $jobs, $images, $formatter, $logger, $settings, $import_export ) )->register();
		}
	}

	/**
	 * @param string[] $caps    Primitive capabilities WordPress would check.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @return string[]
	 */
	public function map_capability( array $caps, string $cap, int $user_id ): array {
		if ( self::CAPABILITY !== $cap ) {
			return $caps;
		}

		return array_merge(
			map_meta_cap( 'manage_options', $user_id ),
			map_meta_cap( 'unfiltered_html', $user_id )
		);
	}
}

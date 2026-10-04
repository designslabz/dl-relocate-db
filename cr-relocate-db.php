<?php
/**
 * Plugin Name:       CR Relocate DB
 * Plugin URI:        https://github.com/designs-labz/dl-relocate-db
 * Description:       Safely search and replace URLs and text across your WordPress database, with serialized data support, dry runs and an operation history.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            CraftRoq
 * Author URI:        https://craftroq.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       cr-relocate-db
 * Domain Path:       /languages
 *
 * @package CraftRoq\Relocate
 */

defined( 'ABSPATH' ) || exit;

// WordPress checks "Requires PHP" on activation only. A later PHP downgrade would
// otherwise fatal the whole site on the first enum or readonly property.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			wp_admin_notice(
				esc_html__( 'CR Relocate DB requires PHP 8.1 or newer and is not running.', 'cr-relocate-db' ),
				array( 'type' => 'error' )
			);
		}
	);
	return;
}

spl_autoload_register(
	function ( string $class_name ): void {
		$prefix = 'CraftRoq\\Relocate\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/*
 * The free plugin runs on single sites only. Multisite support is planned for
 * the Pro add-on, which switches it on with this filter. Checked when needed,
 * not at load time, so an add-on that loads after this file can still hook in.
 */
$crq_relocate_runs_here = function (): bool {
	/**
	 * Filters whether the plugin may run on a Multisite network.
	 *
	 * @param bool $supported Default false.
	 */
	return ! is_multisite() || (bool) apply_filters( 'crq_relocate_supports_multisite', false );
};

register_activation_hook(
	__FILE__,
	function () use ( $crq_relocate_runs_here ): void {
		if ( $crq_relocate_runs_here() ) {
			global $wpdb;
			( new CraftRoq\Relocate\Installer( $wpdb ) )->maybe_upgrade();
		}
	}
);

register_deactivation_hook( __FILE__, array( CraftRoq\Relocate\Jobs\Cleanup::class, 'unschedule' ) );

add_action(
	'plugins_loaded',
	function () use ( $crq_relocate_runs_here ): void {
		if ( ! $crq_relocate_runs_here() ) {
			$notice = function (): void {
				// Only where plugins are managed: a notice nobody can act on must not follow people around the dashboard.
				$screen = get_current_screen();

				if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) ) {
					return;
				}

				wp_admin_notice(
					esc_html__( 'CR Relocate DB works on single sites. Multisite support is planned for CR Relocate DB Pro, so the plugin is not running on this network.', 'cr-relocate-db' ),
					array( 'type' => 'warning' )
				);
			};
			add_action( 'admin_notices', $notice );
			add_action( 'network_admin_notices', $notice );
			return;
		}

		global $wpdb;
		( new CraftRoq\Relocate\Plugin( __FILE__, $wpdb ) )->register();
	}
);

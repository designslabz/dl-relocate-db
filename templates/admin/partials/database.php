<?php
/**
 * Settings → Database: server details and a searchable, sortable list of tables.
 *
 * @package CraftRoq\Relocate
 *
 * @var array $args
 */

use CraftRoq\Relocate\Admin\Admin;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from a method, so these variables are local.

$server = $args['server'];
$tiles  = array(
	array( 'database', __( 'Tables', 'cr-relocate-db' ), number_format_i18n( $args['count'] ) ),
	array( 'chart-pie', __( 'Total size', 'cr-relocate-db' ), (string) size_format( $args['size'], 1 ) ),
	array( 'editor-table', __( 'Rows (approx.)', 'cr-relocate-db' ), number_format_i18n( $args['rows'] ) ),
	array( 'admin-site-alt3', __( 'Server', 'cr-relocate-db' ), $server['version'] ),
);
?>
<ul class="crq-tiles" aria-label="<?php esc_attr_e( 'At a glance', 'cr-relocate-db' ); ?>">
	<?php foreach ( $tiles as [ $icon, $label, $value ] ) : ?>
		<li class="crq-tile">
			<span class="crq-tile-icon dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<span class="crq-tile-value"><?php echo esc_html( $value ); ?></span>
			<span class="crq-tile-label"><?php echo esc_html( $label ); ?></span>
		</li>
	<?php endforeach; ?>
</ul>

<dl class="crq-inline-facts">
	<div><dt><?php esc_html_e( 'Database', 'cr-relocate-db' ); ?></dt><dd><code><?php echo esc_html( $server['name'] ); ?></code></dd></div>
	<div><dt><?php esc_html_e( 'Table prefix', 'cr-relocate-db' ); ?></dt><dd><code><?php echo esc_html( $server['prefix'] ); ?></code></dd></div>
	<div><dt><?php esc_html_e( 'Connection charset', 'cr-relocate-db' ); ?></dt><dd><?php echo esc_html( trim( $server['charset'] . ' / ' . $server['collate'], ' /' ) ); ?></dd></div>
</dl>

<div class="crq-card crq-card-flush">
	<?php $args['list']->views(); ?>
	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE . '-settings' ); ?>">
		<input type="hidden" name="view" value="database">
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Keeps the current filter when searching.
		if ( isset( $_GET['group'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( '<input type="hidden" name="group" value="%s">', esc_attr( sanitize_key( wp_unslash( $_GET['group'] ) ) ) );
		}

		$args['list']->search_box( __( 'Search tables', 'cr-relocate-db' ), 'crq-tables' );
		$args['list']->display();
		?>
	</form>
</div>
<p class="description crq-footnote"><?php esc_html_e( 'Row counts and sizes come from the database server’s statistics. For InnoDB tables they are estimates and can be out of date.', 'cr-relocate-db' ); ?></p>

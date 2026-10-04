<?php
/**
 * Settings, with the database overview and system status as further views.
 *
 * @package CraftRoq\Relocate
 *
 * @var array $args
 */

use CraftRoq\Relocate\Admin\Admin;
use CraftRoq\Relocate\Settings;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from a method, so these variables are local.

$views = array(
	'general'  => __( 'General', 'cr-relocate-db' ),
	'database' => __( 'Database', 'cr-relocate-db' ),
	'status'   => __( 'System status', 'cr-relocate-db' ),
);
$view  = $args['view'] ?? 'general';
?>
<h2 class="crq-title"><?php esc_html_e( 'Settings', 'cr-relocate-db' ); ?></h2>
<p class="crq-intro"><?php esc_html_e( 'The defaults work for most sites. Here you can also look over the database and check that this server is ready.', 'cr-relocate-db' ); ?></p>

<nav class="crq-subnav" aria-label="<?php esc_attr_e( 'Settings views', 'cr-relocate-db' ); ?>">
	<?php foreach ( $views as $key => $label ) : ?>
		<a href="<?php echo esc_url( Admin::url( 'settings', 'general' === $key ? array() : array( 'view' => $key ) ) ); ?>" class="<?php echo $key === $view ? 'is-active' : ''; ?>"<?php echo $key === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php
if ( isset( $args['error'] ) ) {
	wp_admin_notice( esc_html( $args['error'] ), array( 'type' => 'error' ) );
	return;
}

if ( 'database' === $view ) {
	require __DIR__ . '/partials/database.php';
	return;
}

if ( 'status' === $view ) {
	require __DIR__ . '/partials/status.php';
	return;
}

// Only options-general.php pages print these automatically.
settings_errors();
?>
<form action="options.php" method="post" class="crq-card crq-settings">
	<?php
	settings_fields( Settings::OPTION );
	do_settings_sections( Settings::OPTION );
	submit_button();
	?>
</form>

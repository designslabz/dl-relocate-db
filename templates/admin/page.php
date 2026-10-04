<?php
/**
 * Page shell: branded header with section navigation, then the section.
 *
 * @package CraftRoq\Relocate
 *
 * @var array $args
 */

use CraftRoq\Relocate\Admin\Admin;
use CraftRoq\Relocate\Plugin;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from a method, so these variables are local.

$icons = array(
	'dashboard'      => 'dashboard',
	'search-replace' => 'search',
	'import-export'  => 'migrate',
	'history'        => 'backup',
	'settings'       => 'admin-generic',
);
?>
<div class="wrap crq-relocate">
	<header class="crq-header">
		<div class="crq-brand">
			<img class="crq-brand-mark" src="<?php echo esc_url( $args['logo'] ); ?>" alt="" width="40" height="28">
			<h1 class="crq-brand-name">
				<?php esc_html_e( 'CR Relocate DB', 'cr-relocate-db' ); ?>
				<span class="crq-version"><?php echo esc_html( Plugin::VERSION ); ?></span>
			</h1>
		</div>
		<nav class="crq-nav" aria-label="<?php esc_attr_e( 'CR Relocate DB sections', 'cr-relocate-db' ); ?>">
			<?php foreach ( $args['sections'] as $section => $label ) : ?>
				<?php $active = $section === $args['current']; ?>
				<a href="<?php echo esc_url( Admin::url( $section ) ); ?>" class="crq-nav-link<?php echo $active ? ' is-active' : ''; ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons dashicons-<?php echo esc_attr( $icons[ $section ] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	</header>
	<hr class="wp-header-end">

	<main class="crq-main">
		<?php require __DIR__ . '/' . $args['current'] . '.php'; ?>
	</main>
</div>

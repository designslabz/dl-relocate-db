<?php
/**
 * Settings → System status: what can affect a replacement on this server.
 *
 * @package CraftRoq\Relocate
 *
 * @var array $args
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from a method, so these variables are local.

$warnings = count( array_filter( $args['checks'], fn( array $check ): bool => 'warning' === $check[0] ) );
$icons    = array(
	'ok'      => 'yes-alt',
	'warning' => 'warning',
	'info'    => 'info-outline',
);
?>
<section class="crq-card" aria-label="<?php esc_attr_e( 'System status', 'cr-relocate-db' ); ?>">
	<p class="crq-status-summary <?php echo $warnings ? 'is-warning' : 'is-ok'; ?>">
		<span class="dashicons dashicons-<?php echo $warnings ? 'warning' : 'yes-alt'; ?>" aria-hidden="true"></span>
		<?php
		echo esc_html(
			$warnings
				/* translators: %s: number of problems. */
				? sprintf( _n( '%s thing needs a look', '%s things need a look', $warnings, 'cr-relocate-db' ), number_format_i18n( $warnings ) )
				: __( 'Ready to run replacements', 'cr-relocate-db' )
		);
		?>
	</p>
	<ul class="crq-checks">
		<?php foreach ( $args['checks'] as [ $state, $label, $value ] ) : ?>
			<li class="is-<?php echo esc_attr( $state ); ?>">
				<span class="dashicons dashicons-<?php echo esc_attr( $icons[ $state ] ); ?>" aria-hidden="true"></span>
				<span class="crq-checks-text"><strong><?php echo esc_html( $label ); ?></strong> <span><?php echo esc_html( $value ); ?></span></span>
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="crq-env">
		<?php
		$environment = array();
		foreach ( $args['environment'] as $name => $version ) {
			$environment[] = $name . ' ' . $version;
		}
		echo esc_html( implode( ' · ', $environment ) );
		?>
	</p>
</section>

<?php
/**
 * History: the job list, the log, or a single job.
 *
 * @package CraftRoq\Relocate
 *
 * @var array $args
 */

use CraftRoq\Relocate\Admin\Admin;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from a method, so these variables are local.

if ( isset( $args['error'] ) ) {
	wp_admin_notice( esc_html( $args['error'] ), array( 'type' => 'error' ) );
	printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( Admin::url( 'history' ) ), esc_html__( 'Back to all jobs', 'cr-relocate-db' ) );
	return;
}

if ( 'job' === $args['view'] ) {
	require __DIR__ . '/job.php';
	return;
}

if ( null !== $args['deleted'] ) {
	wp_admin_notice(
		esc_html(
			/* translators: %s: number of jobs. */
			sprintf( _n( '%s job deleted.', '%s jobs deleted.', $args['deleted'], 'cr-relocate-db' ), number_format_i18n( $args['deleted'] ) )
		),
		array(
			'type'        => 'success',
			'dismissible' => true,
		)
	);
}

if ( $args['kept'] ) {
	wp_admin_notice(
		esc_html(
			/* translators: %s: number of jobs. */
			sprintf( _n( '%s job was not deleted because it is still running.', '%s jobs were not deleted because they are still running.', $args['kept'], 'cr-relocate-db' ), number_format_i18n( $args['kept'] ) )
		),
		array( 'type' => 'warning' )
	);
}

$is_log = 'log' === $args['view'];
?>
<h2 class="crq-title"><?php esc_html_e( 'History', 'cr-relocate-db' ); ?></h2>
<p class="crq-intro"><?php esc_html_e( 'Every dry run and replacement. Open one to see exactly what it changed, download its original values, or continue it if it stopped.', 'cr-relocate-db' ); ?></p>

<nav class="crq-subnav" aria-label="<?php esc_attr_e( 'History views', 'cr-relocate-db' ); ?>">
	<a href="<?php echo esc_url( Admin::url( 'history' ) ); ?>" class="<?php echo $is_log ? '' : 'is-active'; ?>"<?php echo $is_log ? '' : ' aria-current="page"'; ?>><?php esc_html_e( 'Jobs', 'cr-relocate-db' ); ?></a>
	<a href="<?php echo esc_url( Admin::url( 'history', array( 'view' => 'log' ) ) ); ?>" class="<?php echo $is_log ? 'is-active' : ''; ?>"<?php echo $is_log ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Log', 'cr-relocate-db' ); ?></a>
</nav>

<?php if ( $is_log && $args['table']->job_filter() ) : ?>
	<p class="crq-filter-note">
		<?php
		/* translators: %d: job number. */
		echo esc_html( sprintf( __( 'Showing entries for job #%d.', 'cr-relocate-db' ), $args['table']->job_filter() ) );
		?>
		<a href="<?php echo esc_url( Admin::url( 'history', array( 'view' => 'log' ) ) ); ?>"><?php esc_html_e( 'Show all entries', 'cr-relocate-db' ); ?></a>
	</p>
<?php endif; ?>

<div class="crq-card crq-card-flush">
	<?php $args['table']->views(); ?>
	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE . '-history' ); ?>">
		<?php
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Keeps the current filters when searching.
		foreach ( array( 'view', 'type', 'level', 'log_job' ) as $keep ) {
			if ( isset( $_GET[ $keep ] ) ) {
				printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $keep ), esc_attr( sanitize_key( wp_unslash( $_GET[ $keep ] ) ) ) );
			}
		}
		// phpcs:enable

		$args['table']->search_box( $is_log ? __( 'Search log', 'cr-relocate-db' ) : __( 'Search jobs', 'cr-relocate-db' ), 'crq-history' );
		$args['table']->display();
		?>
	</form>
</div>

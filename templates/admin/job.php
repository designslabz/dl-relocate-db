<?php
/**
 * A single job: its details, results and log. Results are rendered by the same
 * script as the Search & Replace tab, which can also continue or apply the job.
 *
 * @package CraftRoq\Relocate
 *
 * @var array $args
 */

use CraftRoq\Relocate\Admin\Admin;
use CraftRoq\Relocate\Admin\LogTable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from a method, so these variables are local.

/** @var \CraftRoq\Relocate\Jobs\Job $job */
$job  = $args['job'];
$user = get_userdata( $job->user_id );

$options = array_filter(
	array(
		! $job->settings['case_sensitive'] ? __( 'Ignore upper and lower case', 'cr-relocate-db' ) : '',
		$job->settings['whole_words'] ? __( 'Whole words only', 'cr-relocate-db' ) : '',
		$job->settings['url_variants'] ? __( 'Also http:// and // versions of the URL', 'cr-relocate-db' ) : '',
		$job->settings['skip_guids'] ? __( 'Post GUIDs left unchanged', 'cr-relocate-db' ) : '',
		! $job->dry_run && empty( $job->settings['before_image'] ) ? __( 'Original values not saved', 'cr-relocate-db' ) : '',
	)
);

$excluded = array();
foreach ( $job->settings['exclude_columns'] ?? array() as $table_name => $table_columns ) {
	foreach ( $table_columns as $column ) {
		$excluded[] = $table_name . '.' . $column;
	}
}
?>
<p class="crq-back"><a href="<?php echo esc_url( Admin::url( 'history' ) ); ?>">&larr; <?php esc_html_e( 'All jobs', 'cr-relocate-db' ); ?></a></p>

<div class="crq-title-row crq-job-head">
	<div>
		<p class="crq-job-meta">
			<span class="crq-type crq-type-<?php echo $job->dry_run ? 'dry' : 'live'; ?>"><?php echo esc_html( Admin::job_title( $job ) ); ?></span>
			<?php echo Admin::status_badge( $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML. ?>
		</p>
		<h2 class="crq-title crq-job-title"><?php echo Admin::job_change( $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML. ?></h2>
	</div>
	<?php if ( Admin::can_delete( $job ) ) : ?>
		<a href="<?php echo esc_url( Admin::delete_url( $job->id ) ); ?>" class="button button-link-delete crq-delete-job" data-job="<?php echo esc_attr( Admin::job_title( $job ) ); ?>">
			<span class="dashicons dashicons-trash" aria-hidden="true"></span> <?php esc_html_e( 'Delete job', 'cr-relocate-db' ); ?>
		</a>
	<?php endif; ?>
</div>

<section class="crq-card" aria-label="<?php esc_attr_e( 'Job details', 'cr-relocate-db' ); ?>">
	<?php if ( count( $job->pairs() ) > 1 ) : ?>
		<ul class="crq-pair-list">
			<?php foreach ( $job->pairs() as [ $pair_search, $pair_replace ] ) : ?>
				<li>
					<code><?php echo esc_html( $pair_search ); ?></code>
					<span aria-hidden="true">→</span><span class="screen-reader-text"><?php esc_html_e( 'replaced with', 'cr-relocate-db' ); ?></span>
					<?php echo '' === $pair_replace ? '<em>' . esc_html__( '(nothing: removed)', 'cr-relocate-db' ) . '</em>' : '<code>' . esc_html( $pair_replace ) . '</code>'; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<dl class="crq-facts">
		<div>
			<dt><?php esc_html_e( 'Options', 'cr-relocate-db' ); ?></dt>
			<dd><?php echo esc_html( $options ? implode( ', ', $options ) : __( 'Defaults', 'cr-relocate-db' ) ); ?></dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Tables', 'cr-relocate-db' ); ?></dt>
			<dd>
				<details>
					<summary>
						<?php
						$table_count = count( $job->settings['tables'] );
						/* translators: %s: number of tables. */
						echo esc_html( sprintf( _n( '%s table', '%s tables', $table_count, 'cr-relocate-db' ), number_format_i18n( $table_count ) ) );
						?>
					</summary>
					<code><?php echo esc_html( implode( ', ', $job->settings['tables'] ) ); ?></code>
				</details>
			</dd>
		</div>
		<?php if ( $excluded ) : ?>
			<div>
				<dt><?php esc_html_e( 'Columns left out', 'cr-relocate-db' ); ?></dt>
				<dd><code><?php echo esc_html( implode( ', ', $excluded ) ); ?></code></dd>
			</div>
		<?php endif; ?>
		<div>
			<dt><?php esc_html_e( 'Started by', 'cr-relocate-db' ); ?></dt>
			<dd>
				<?php
				// Jobs started from WP-CLI without --user have no user.
				echo esc_html( $user ? $user->display_name : ( 0 === $job->user_id ? __( 'WP-CLI', 'cr-relocate-db' ) : __( 'Unknown user', 'cr-relocate-db' ) ) );
				?>
			</dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Created', 'cr-relocate-db' ); ?></dt>
			<dd><?php echo esc_html( Admin::format_date( $job->created_at ) ); ?></dd>
		</div>
		<div>
			<dt><?php esc_html_e( 'Finished', 'cr-relocate-db' ); ?></dt>
			<dd><?php echo esc_html( Admin::format_date( $job->finished_at ) ); ?></dd>
		</div>
		<?php if ( $job->parent_id ) : ?>
			<div>
				<dt><?php esc_html_e( 'Previewed by', 'cr-relocate-db' ); ?></dt>
				<dd>
					<a href="<?php echo esc_url( Admin::job_url( $job->parent_id ) ); ?>">
						<?php
						/* translators: %d: job number. */
						echo esc_html( sprintf( __( 'Dry run #%d', 'cr-relocate-db' ), $job->parent_id ) );
						?>
					</a>
				</dd>
			</div>
		<?php endif; ?>
		<?php if ( $args['child_id'] ) : ?>
			<div>
				<dt><?php esc_html_e( 'Applied by', 'cr-relocate-db' ); ?></dt>
				<dd>
					<a href="<?php echo esc_url( Admin::job_url( $args['child_id'] ) ); ?>">
						<?php
						/* translators: %d: job number. */
						echo esc_html( sprintf( __( 'Replacement #%d', 'cr-relocate-db' ), $args['child_id'] ) );
						?>
					</a>
				</dd>
			</div>
		<?php endif; ?>
	</dl>
</section>

<?php require __DIR__ . '/partials/runner.php'; ?>

<details class="crq-card crq-disclosure">
<summary>
	<span class="crq-card-title"><?php esc_html_e( 'Log', 'cr-relocate-db' ); ?></span>
	<span class="crq-disclosure-count">
		<?php
		/* translators: %s: number of log entries. */
		echo esc_html( sprintf( _n( '%s entry', '%s entries', (int) $args['log_total'], 'cr-relocate-db' ), number_format_i18n( (int) $args['log_total'] ) ) );
		?>
	</span>
</summary>
<?php if ( $args['logs'] ) : ?>
	<div class="crq-table-scroll">
		<table class="widefat striped crq-tables">
			<caption class="screen-reader-text"><?php esc_html_e( 'Log entries for this job', 'cr-relocate-db' ); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'cr-relocate-db' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Level', 'cr-relocate-db' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'cr-relocate-db' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $args['logs'] as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( Admin::format_date( $entry->created_at ) ); ?></td>
						<td><?php echo LogTable::level_badge( (string) $entry->level ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML. ?></td>
						<td><?php echo LogTable::message( $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML. ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php if ( $args['log_total'] > count( $args['logs'] ) ) : ?>
		<p>
			<a href="
			<?php
			echo esc_url(
				Admin::url(
					'history',
					array(
						'view'    => 'log',
						'log_job' => $job->id,
					)
				)
			);
			?>
						">
				<?php
				/* translators: %s: number of log entries. */
				echo esc_html( sprintf( __( 'View all %s entries', 'cr-relocate-db' ), number_format_i18n( $args['log_total'] ) ) );
				?>
				<span aria-hidden="true">→</span>
			</a>
		</p>
	<?php endif; ?>
<?php else : ?>
	<p class="crq-empty"><?php esc_html_e( 'No log entries for this job.', 'cr-relocate-db' ); ?></p>
<?php endif; ?>
</details>

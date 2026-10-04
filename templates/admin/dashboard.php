<?php
/**
 * Dashboard: a start screen. Begin a search and replace, or pick up a recent job.
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
	return;
}
?>
<?php if ( $args['attention'] ) : ?>
	<section class="crq-card crq-card-attention" aria-labelledby="crq-attention-title">
		<h2 id="crq-attention-title" class="crq-card-title"><span class="dashicons dashicons-flag" aria-hidden="true"></span> <?php esc_html_e( 'Needs attention', 'cr-relocate-db' ); ?></h2>
		<ul class="crq-list">
			<?php foreach ( $args['attention'] as $job ) : ?>
				<li>
					<?php echo Admin::status_badge( $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML. ?>
					<a href="<?php echo esc_url( Admin::job_url( $job->id ) ); ?>"><?php echo esc_html( Admin::job_title( $job ) ); ?></a>
					<span class="description">
						<?php
						echo esc_html(
							$job->is_interrupted()
								? __( 'Stopped before it finished. Open it to continue from where it stopped.', 'cr-relocate-db' )
								: __( 'Stopped by an error. Open it to see what happened and resume.', 'cr-relocate-db' )
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

<section class="crq-card crq-hero" aria-labelledby="crq-start-title">
	<h2 id="crq-start-title" class="crq-hero-title"><?php esc_html_e( 'Search and replace across your database', 'cr-relocate-db' ); ?></h2>
	<p class="crq-hero-lead"><?php esc_html_e( 'Change a domain, move to HTTPS or update text everywhere it appears. You always see a preview first, and nothing changes until you confirm.', 'cr-relocate-db' ); ?></p>

	<form method="post" action="<?php echo esc_url( Admin::url( 'search-replace' ) ); ?>">
		<?php wp_nonce_field( $args['quick_action'] ); ?>
		<div class="crq-hero-fields">
			<p class="crq-field">
				<label for="crq-quick-search"><?php esc_html_e( 'Find', 'cr-relocate-db' ); ?></label>
				<input type="text" id="crq-quick-search" name="search" class="large-text code" required spellcheck="false" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other">
			</p>
			<span class="crq-hero-arrow dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
			<p class="crq-field">
				<label for="crq-quick-replace"><?php esc_html_e( 'Replace with', 'cr-relocate-db' ); ?></label>
				<input type="text" id="crq-quick-replace" name="replace" class="large-text code" spellcheck="false" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other">
			</p>
		</div>
		<div class="crq-hero-actions">
			<button type="submit" class="button button-primary crq-button-lg"><?php esc_html_e( 'Continue', 'cr-relocate-db' ); ?> <span aria-hidden="true">→</span></button>
		</div>
	</form>

	<?php if ( 0 === $args['jobs'] ) : ?>
		<div class="crq-welcome">
			<h3><?php esc_html_e( 'Move a site safely in three steps', 'cr-relocate-db' ); ?></h3>
			<ol class="crq-welcome-steps">
				<li>
					<span class="dashicons dashicons-edit" aria-hidden="true"></span>
					<span><strong><?php esc_html_e( 'Choose', 'cr-relocate-db' ); ?></strong> <?php esc_html_e( 'What to find and what to replace it with.', 'cr-relocate-db' ); ?></span>
				</li>
				<li>
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<span><strong><?php esc_html_e( 'Preview', 'cr-relocate-db' ); ?></strong> <?php esc_html_e( 'See every change before anything is written.', 'cr-relocate-db' ); ?></span>
				</li>
				<li>
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<span><strong><?php esc_html_e( 'Apply', 'cr-relocate-db' ); ?></strong> <?php esc_html_e( 'Confirm, and the original values are saved to a file first.', 'cr-relocate-db' ); ?></span>
				</li>
			</ol>
		</div>
	<?php endif; ?>
</section>

<?php if ( $args['recent'] ) : ?>
	<section class="crq-card" aria-labelledby="crq-recent-title">
		<div class="crq-card-head">
			<h2 id="crq-recent-title" class="crq-card-title"><?php esc_html_e( 'Recent jobs', 'cr-relocate-db' ); ?></h2>
			<a href="<?php echo esc_url( Admin::url( 'history' ) ); ?>"><?php esc_html_e( 'View all', 'cr-relocate-db' ); ?> <span aria-hidden="true">→</span></a>
		</div>
		<ul class="crq-job-list">
			<?php foreach ( $args['recent'] as $job ) : ?>
				<?php $replacements = $job->report->totals()['replacements']; ?>
				<li>
					<a href="<?php echo esc_url( Admin::job_url( $job->id ) ); ?>" class="crq-job-row">
						<span class="crq-job-row-main">
							<?php echo Admin::job_change( $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML. ?>
							<span class="crq-job-meta">
								<span class="crq-type crq-type-<?php echo $job->dry_run ? 'dry' : 'live'; ?>"><?php echo esc_html( Admin::job_title( $job ) ); ?></span>
								<?php echo esc_html( Admin::format_date( $job->created_at ) ); ?>
								·
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: number of replacements. */
										$job->dry_run ? _n( '%s replacement found', '%s replacements found', $replacements, 'cr-relocate-db' ) : _n( '%s replacement made', '%s replacements made', $replacements, 'cr-relocate-db' ),
										number_format_i18n( $replacements )
									)
								);
								?>
							</span>
						</span>
						<?php echo Admin::status_badge( $job ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped HTML. ?>
						<span class="crq-job-row-go dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

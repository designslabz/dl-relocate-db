<?php
/**
 * Import / Export: database exports and imports, and the plugin settings file.
 * Exports and imports are driven by assets/js/transfer.js.
 *
 * @package CraftRoq\Relocate
 *
 * @var array $args
 */

use CraftRoq\Relocate\Admin\Admin;
use CraftRoq\Relocate\Admin\ImportExport;
use CraftRoq\Relocate\Jobs\JobStatus;
use CraftRoq\Relocate\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from a method, so these variables are local.

if ( isset( $args['error'] ) ) {
	wp_admin_notice( esc_html( $args['error'] ), array( 'type' => 'error' ) );
	return;
}

$views = array(
	'export'   => __( 'Export database', 'cr-relocate-db' ),
	'import'   => __( 'Import database', 'cr-relocate-db' ),
	'settings' => __( 'Settings file', 'cr-relocate-db' ),
);
$view  = $args['view'];
?>
<h2 class="crq-title"><?php esc_html_e( 'Import / Export', 'cr-relocate-db' ); ?></h2>
<p class="crq-intro"><?php esc_html_e( 'Move a database between sites: export it on one, import it on the other. The site address can be changed on the way out or on the way in.', 'cr-relocate-db' ); ?></p>

<nav class="crq-subnav" aria-label="<?php esc_attr_e( 'Import / Export views', 'cr-relocate-db' ); ?>">
	<?php foreach ( $views as $key => $label ) : ?>
		<a href="<?php echo esc_url( Admin::url( 'import-export', 'export' === $key ? array() : array( 'view' => $key ) ) ); ?>" class="<?php echo $key === $view ? 'is-active' : ''; ?>"<?php echo $key === $view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
	<?php endforeach; ?>
</nav>

<div id="crq-transfer" data-rest-root="<?php echo esc_attr( $args['rest_root'] ); ?>">
	<div id="crq-transfer-notices"></div>

	<?php if ( 'export' === $view ) : ?>
		<form id="crq-export-form" class="crq-card crq-wizard-step">
			<h3 class="crq-wizard-title"><?php esc_html_e( 'Export the database', 'cr-relocate-db' ); ?></h3>
			<p class="crq-card-desc"><?php esc_html_e( 'Creates a compressed .sql.gz file to download. Your database is only read.', 'cr-relocate-db' ); ?></p>

			<fieldset class="crq-choices">
				<legend class="screen-reader-text"><?php esc_html_e( 'Tables to export', 'cr-relocate-db' ); ?></legend>
				<label class="crq-choice">
					<input type="radio" name="scope" value="core" checked>
					<span>
						<strong><?php esc_html_e( 'All WordPress tables', 'cr-relocate-db' ); ?></strong>
						<span class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of tables, 2: table prefix, e.g. wp_. */
									_n( 'Recommended. The %1$s table that starts with %2$s.', 'Recommended. The %1$s tables that start with %2$s.', $args['core_tables'], 'cr-relocate-db' ),
									number_format_i18n( $args['core_tables'] ),
									$args['prefix']
								)
							);
							?>
						</span>
					</span>
				</label>
				<?php if ( $args['all_tables'] > $args['core_tables'] ) : ?>
				<label class="crq-choice">
					<input type="radio" name="scope" value="all">
					<span>
						<strong><?php esc_html_e( 'Every table in the database', 'cr-relocate-db' ); ?></strong>
						<span class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of tables. */
									_n( '%s table, including any from other software that shares this database.', '%s tables, including any from other software that shares this database.', $args['all_tables'], 'cr-relocate-db' ),
									number_format_i18n( $args['all_tables'] )
								)
							);
							?>
						</span>
					</span>
				</label>
				<?php endif; ?>
			</fieldset>

			<?php
			$text_change = 'export';
			require __DIR__ . '/partials/text-change.php';
			?>

			<div class="crq-wizard-nav">
				<button type="submit" class="button button-primary crq-button-lg">
					<span class="dashicons dashicons-download" aria-hidden="true"></span>
					<?php esc_html_e( 'Export database', 'cr-relocate-db' ); ?>
				</button>
			</div>
		</form>
	<?php elseif ( 'import' === $view ) : ?>
		<form id="crq-import-form" class="crq-card crq-wizard-step">
			<h3 class="crq-wizard-title"><?php esc_html_e( 'Import a database file', 'cr-relocate-db' ); ?></h3>
			<p class="crq-card-desc"><?php esc_html_e( 'Runs an .sql or .sql.gz file into this database. Tables in the file replace the tables with the same names here.', 'cr-relocate-db' ); ?></p>

			<div class="crq-callout">
				<span class="dashicons dashicons-backup" aria-hidden="true"></span>
				<p>
					<?php esc_html_e( 'Export this site first, so you can go back if you need to.', 'cr-relocate-db' ); ?>
					<a href="<?php echo esc_url( Admin::url( 'import-export' ) ); ?>"><?php esc_html_e( 'Export now', 'cr-relocate-db' ); ?> <span aria-hidden="true">→</span></a>
				</p>
			</div>

			<p class="crq-field crq-file-field">
				<label for="crq-import-file"><?php esc_html_e( 'Database file', 'cr-relocate-db' ); ?></label>
				<input type="file" id="crq-import-file" name="file" accept=".sql,.gz" required aria-describedby="crq-import-file-help">
				<span class="description" id="crq-import-file-help">
					<?php
					/* translators: %s: maximum upload size, e.g. 64 MB. */
					echo esc_html( sprintf( __( 'Up to %s on this server. Files exported by CR Relocate DB on another site work best.', 'cr-relocate-db' ), $args['max_upload'] ) );
					?>
				</span>
			</p>

			<?php
			$text_change = 'import';
			require __DIR__ . '/partials/text-change.php';
			?>

			<label class="crq-option">
				<input type="checkbox" id="crq-import-confirm">
				<span><?php esc_html_e( 'I have a backup. I understand the tables in this file overwrite those in this database, which is not undone automatically.', 'cr-relocate-db' ); ?></span>
			</label>

			<div class="crq-wizard-nav">
				<button type="submit" class="button button-primary crq-button-lg" disabled>
					<span class="dashicons dashicons-upload" aria-hidden="true"></span>
					<?php esc_html_e( 'Import database', 'cr-relocate-db' ); ?>
				</button>
			</div>
		</form>
	<?php else : ?>
		<?php if ( 'imported' === $args['settings_notice'] ) : ?>
			<?php wp_admin_notice( esc_html__( 'Settings imported.', 'cr-relocate-db' ), array( 'type' => 'success' ) ); ?>
		<?php elseif ( 'invalid' === $args['settings_notice'] ) : ?>
			<?php wp_admin_notice( esc_html__( 'That is not a CR Relocate DB settings file. Nothing was changed.', 'cr-relocate-db' ), array( 'type' => 'error' ) ); ?>
		<?php endif; ?>

		<section class="crq-card crq-wizard-step" aria-labelledby="crq-settings-export-title">
			<h3 id="crq-settings-export-title" class="crq-wizard-title"><?php esc_html_e( 'Copy these settings to another site', 'cr-relocate-db' ); ?></h3>
			<p class="crq-card-desc"><?php esc_html_e( 'The plugin’s settings as a small JSON file: rows per batch, history clean-up and uninstall choice. Jobs and history are not included.', 'cr-relocate-db' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="crq-inline-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( $args['actions']['settings_export'] ); ?>">
				<?php wp_nonce_field( $args['actions']['settings_export'] ); ?>
				<button type="submit" class="button crq-button-lg"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Download settings file', 'cr-relocate-db' ); ?></button>
			</form>

			<hr class="crq-divider">

			<h3 class="crq-card-title"><?php esc_html_e( 'Import a settings file', 'cr-relocate-db' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="crq-inline-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( $args['actions']['settings_import'] ); ?>">
				<?php wp_nonce_field( $args['actions']['settings_import'] ); ?>
				<label class="screen-reader-text" for="crq-settings-file"><?php esc_html_e( 'Settings file', 'cr-relocate-db' ); ?></label>
				<input type="file" id="crq-settings-file" name="settings_file" accept=".json,application/json" required>
				<button type="submit" class="button crq-button-lg"><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Import settings', 'cr-relocate-db' ); ?></button>
			</form>
		</section>
	<?php endif; ?>

	<section id="crq-transfer-progress" class="crq-card crq-progress" hidden aria-labelledby="crq-transfer-heading">
		<div class="crq-progress-head">
			<div>
				<h3 id="crq-transfer-heading" class="crq-card-title"></h3>
				<p id="crq-transfer-text" class="crq-progress-text"></p>
			</div>
			<span id="crq-transfer-percent" class="crq-progress-percent" aria-hidden="true">0%</span>
		</div>
		<div id="crq-transfer-bar" class="crq-bar is-indeterminate" role="progressbar" aria-labelledby="crq-transfer-heading" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
			<div class="crq-bar-fill"></div>
		</div>
		<p class="crq-progress-actions">
			<button type="button" class="button" id="crq-transfer-cancel"><?php esc_html_e( 'Cancel', 'cr-relocate-db' ); ?></button>
		</p>
	</section>

	<div id="crq-transfer-result" hidden></div>

	<?php if ( 'export' === $view && $args['exports'] ) : ?>
		<section class="crq-card" aria-labelledby="crq-exports-title">
			<h3 id="crq-exports-title" class="crq-card-title"><?php esc_html_e( 'Recent exports', 'cr-relocate-db' ); ?></h3>
			<ul class="crq-job-list">
				<?php foreach ( $args['exports'] as $export ) : ?>
					<?php $export_path = Storage::path( $export->file ); ?>
					<li>
						<div class="crq-job-row">
							<span class="crq-job-row-main">
								<strong><?php echo esc_html( Admin::format_date( $export->created_at ) ); ?></strong>
								<span class="crq-job-meta">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: number of tables, 2: number of rows. */
											_n( '%1$s table, %2$s rows', '%1$s tables, %2$s rows', count( $export->settings['tables'] ), 'cr-relocate-db' ),
											number_format_i18n( count( $export->settings['tables'] ) ),
											number_format_i18n( (int) $export->state['rows'] )
										)
									);
									if ( $export->settings['pairs'] ) {
										echo ' · ' . esc_html__( 'text changed while exporting', 'cr-relocate-db' );
									}
									?>
								</span>
							</span>
							<?php if ( JobStatus::Completed === $export->status && $export_path ) : ?>
								<a class="button" href="<?php echo esc_url( ImportExport::export_url( $export->id ) ); ?>">
									<span class="dashicons dashicons-download" aria-hidden="true"></span>
									<?php
									/* translators: %s: file size. */
									echo esc_html( sprintf( __( 'Download (%s)', 'cr-relocate-db' ), size_format( (int) filesize( $export_path ), 1 ) ) );
									?>
								</a>
							<?php else : ?>
								<span class="crq-badge"><?php echo esc_html( JobStatus::Completed === $export->status ? __( 'File removed', 'cr-relocate-db' ) : ( $export->is_interrupted() ? __( 'Interrupted', 'cr-relocate-db' ) : $export->status->label() ) ); ?></span>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="description"><?php esc_html_e( 'Export files are kept as long as the history (see Settings), then deleted.', 'cr-relocate-db' ); ?></p>
		</section>
	<?php endif; ?>
</div>

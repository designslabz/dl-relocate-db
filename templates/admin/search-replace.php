<?php
/**
 * Search & Replace: the form, then progress and results (see partials/runner.php).
 *
 * @package CraftRoq\Relocate
 *
 * @var array $args
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from a method, so these variables are local.

if ( isset( $args['error'] ) ) {
	wp_admin_notice( esc_html( $args['error'] ), array( 'type' => 'error' ) );
	return;
}

$max_pairs = \CraftRoq\Relocate\Jobs\JobStarter::max_pairs();

/** @var array<string, \CraftRoq\Relocate\Database\Table> $tables */
$tables  = $args['tables'];
$columns = $args['columns'];

$groups = array(
	'core'  => array_filter( $tables, fn( $table ) => $table->prefixed ),
	'other' => array_filter( $tables, fn( $table ) => ! $table->prefixed ),
);

$options = array(
	'case_insensitive' => array( false, __( 'Ignore upper and lower case', 'cr-relocate-db' ), __( '“Example” also matches “EXAMPLE” and “example”.', 'cr-relocate-db' ), __( 'any case', 'cr-relocate-db' ) ),
	'whole_words'      => array( false, __( 'Match whole words only', 'cr-relocate-db' ), __( '“cat” matches “cat.” but not “concatenate”.', 'cr-relocate-db' ), __( 'whole words', 'cr-relocate-db' ) ),
	'url_variants'     => array( false, __( 'Include other versions of the URL', 'cr-relocate-db' ), __( 'For https://old.com, also replace http://old.com and //old.com.', 'cr-relocate-db' ), __( 'http and // versions', 'cr-relocate-db' ) ),
	'skip_guids'       => array( true, __( 'Leave post GUIDs unchanged', 'cr-relocate-db' ), __( 'Recommended. Feed readers use GUIDs to recognise posts they have already seen.', 'cr-relocate-db' ), __( 'GUIDs kept', 'cr-relocate-db' ) ),
);

// With no prefixed tables there is nothing for "All WordPress tables" to pick, so the list opens instead.
$has_core = (bool) $groups['core'];
?>
<div class="crq-wizard">
	<div class="crq-title-row">
		<h2 class="crq-title"><?php esc_html_e( 'Search & Replace', 'cr-relocate-db' ); ?></h2>
		<ol id="crq-steps" class="crq-steps" aria-label="<?php esc_attr_e( 'Progress', 'cr-relocate-db' ); ?>">
			<li class="is-current" aria-current="step"><span class="crq-steps-number">1</span> <span class="crq-steps-label"><?php esc_html_e( 'What to replace', 'cr-relocate-db' ); ?></span></li>
			<li><span class="crq-steps-number">2</span> <span class="crq-steps-label"><?php esc_html_e( 'Where', 'cr-relocate-db' ); ?></span></li>
			<li><span class="crq-steps-number">3</span> <span class="crq-steps-label"><?php esc_html_e( 'Preview & apply', 'cr-relocate-db' ); ?></span></li>
		</ol>
	</div>

	<form id="crq-search-replace" class="crq-form" data-start-step="<?php echo '' !== $args['prefill']['search'] ? '2' : '1'; ?>">
		<section class="crq-card crq-wizard-step" data-step="1" aria-labelledby="crq-step-find">
			<h3 id="crq-step-find" class="crq-wizard-title" tabindex="-1"><?php esc_html_e( 'What do you want to replace?', 'cr-relocate-db' ); ?></h3>
			<p class="crq-card-desc" id="crq-pairs-help"><?php esc_html_e( 'Matched exactly as typed, including spaces. Leave “Replace with” empty to remove the text.', 'cr-relocate-db' ); ?></p>

			<ol id="crq-pairs" class="crq-pairs" data-max="<?php echo esc_attr( (string) $max_pairs ); ?>">
				<li class="crq-pair">
					<label for="crq-search-1"><?php esc_html_e( 'Find', 'cr-relocate-db' ); ?></label>
					<input type="text" id="crq-search-1" name="search[]" value="<?php echo esc_attr( $args['prefill']['search'] ); ?>" class="large-text code" required spellcheck="false" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" aria-describedby="crq-pairs-help">
					<label for="crq-replace-1"><?php esc_html_e( 'Replace with', 'cr-relocate-db' ); ?></label>
					<input type="text" id="crq-replace-1" name="replace[]" value="<?php echo esc_attr( $args['prefill']['replace'] ); ?>" class="large-text code" spellcheck="false" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" aria-describedby="crq-pairs-help">
				</li>
			</ol>

			<template id="crq-pair-template">
				<li class="crq-pair">
					<label data-for="search"></label>
					<input type="text" name="search[]" class="large-text code" required spellcheck="false" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" aria-describedby="crq-pairs-help">
					<label data-for="replace"></label>
					<input type="text" name="replace[]" class="large-text code" spellcheck="false" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other" aria-describedby="crq-pairs-help">
					<button type="button" class="crq-link-button crq-pair-remove"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="crq-pair-remove-label"></span></button>
				</li>
			</template>

			<div class="crq-pairs-footer">
				<button type="button" id="crq-add-pair" class="crq-link-button">
					<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Add another', 'cr-relocate-db' ); ?>
				</button>
				<span id="crq-pairs-count" class="crq-pairs-count" aria-live="polite"></span>
			</div>

			<div class="crq-wizard-nav">
				<button type="button" class="button button-primary crq-button-lg" data-crq-next><?php esc_html_e( 'Continue', 'cr-relocate-db' ); ?> <span aria-hidden="true">→</span></button>
			</div>
		</section>

		<section class="crq-card crq-wizard-step" data-step="2" aria-labelledby="crq-step-where" hidden>
			<div id="crq-recap" class="crq-recap"></div>

			<h3 id="crq-step-where" class="crq-wizard-title" tabindex="-1"><?php esc_html_e( 'Where should it look?', 'cr-relocate-db' ); ?></h3>

			<fieldset class="crq-choices">
				<legend class="screen-reader-text"><?php esc_html_e( 'Tables to search', 'cr-relocate-db' ); ?></legend>
				<?php if ( $has_core ) : ?>
					<label class="crq-choice">
						<input type="radio" name="scope" value="core" checked>
						<span>
							<strong><?php esc_html_e( 'All WordPress tables', 'cr-relocate-db' ); ?></strong>
							<span class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: number of tables, 2: table prefix, e.g. wp_. */
										_n( 'Recommended. The %1$s table that starts with %2$s.', 'Recommended. The %1$s tables that start with %2$s.', count( $groups['core'] ), 'cr-relocate-db' ),
										number_format_i18n( count( $groups['core'] ) ),
										$args['prefix']
									)
								);
								?>
							</span>
						</span>
					</label>
				<?php endif; ?>
				<label class="crq-choice">
					<input type="radio" name="scope" value="custom" <?php checked( ! $has_core ); ?>>
					<span>
						<strong><?php esc_html_e( 'Let me choose', 'cr-relocate-db' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Pick the tables yourself, and leave single columns out.', 'cr-relocate-db' ); ?></span>
					</span>
				</label>
			</fieldset>

			<div id="crq-picker-panel" class="crq-picker-panel"<?php echo $has_core ? ' hidden' : ''; ?>>
				<div class="crq-toolbar">
					<label class="screen-reader-text" for="crq-table-filter"><?php esc_html_e( 'Filter tables', 'cr-relocate-db' ); ?></label>
					<input type="search" id="crq-table-filter" class="crq-filter" placeholder="<?php esc_attr_e( 'Filter tables…', 'cr-relocate-db' ); ?>" autocomplete="off">
					<span class="crq-toolbar-actions" role="group" aria-label="<?php esc_attr_e( 'Select tables', 'cr-relocate-db' ); ?>">
						<span class="crq-toolbar-label" aria-hidden="true"><?php esc_html_e( 'Select:', 'cr-relocate-db' ); ?></span>
						<button type="button" class="crq-link-button" data-crq-select="all"><?php esc_html_e( 'All', 'cr-relocate-db' ); ?></button>
						<button type="button" class="crq-link-button" data-crq-select="core"><?php esc_html_e( 'WordPress tables', 'cr-relocate-db' ); ?></button>
						<button type="button" class="crq-link-button" data-crq-select="none"><?php esc_html_e( 'None', 'cr-relocate-db' ); ?></button>
					</span>
					<span id="crq-table-count" class="crq-toolbar-count" aria-live="polite"></span>
				</div>

				<fieldset class="crq-picker">
					<legend class="screen-reader-text"><?php esc_html_e( 'Tables to search', 'cr-relocate-db' ); ?></legend>

					<?php foreach ( $groups as $group => $group_tables ) : ?>
						<?php
						if ( ! $group_tables ) {
							continue;
						}

						$heading = 'core' === $group
							/* translators: %s: database table prefix, e.g. wp_. */
							? sprintf( __( 'WordPress tables (prefix %s)', 'cr-relocate-db' ), $args['prefix'] )
							: __( 'Other tables in this database', 'cr-relocate-db' );
						?>
						<details class="crq-picker-group" <?php echo 'core' === $group ? 'open' : ''; ?>>
							<summary><?php echo esc_html( $heading ); ?> <span class="crq-picker-count">(<?php echo esc_html( number_format_i18n( count( $group_tables ) ) ); ?>)</span></summary>
							<div class="crq-picker-head" aria-hidden="true">
								<span><?php esc_html_e( 'Table', 'cr-relocate-db' ); ?></span>
								<span><?php esc_html_e( 'Rows', 'cr-relocate-db' ); ?></span>
								<span><?php esc_html_e( 'Size', 'cr-relocate-db' ); ?></span>
								<span><?php esc_html_e( 'Columns', 'cr-relocate-db' ); ?></span>
							</div>
							<ul class="crq-picker-list">
								<?php foreach ( $group_tables as $table ) : ?>
									<?php $table_columns = $columns[ $table->name ] ?? array(); ?>
									<li class="crq-picker-row" data-table="<?php echo esc_attr( $table->name ); ?>">
										<label class="crq-picker-table">
											<input type="checkbox" name="tables[]" value="<?php echo esc_attr( $table->name ); ?>" data-group="<?php echo esc_attr( $group ); ?>" <?php checked( 'core' === $group ); ?>>
											<code><?php echo esc_html( $table->name ); ?></code>
										</label>
										<span class="crq-picker-num">
											<?php
											/* translators: %s: approximate number of rows. */
											echo esc_html( sprintf( __( '%s rows', 'cr-relocate-db' ), number_format_i18n( $table->approx_rows ) ) );
											?>
										</span>
										<span class="crq-picker-num"><?php echo esc_html( (string) size_format( $table->size(), 1 ) ); ?></span>
										<?php if ( $table_columns ) : ?>
											<details class="crq-columns">
												<summary>
													<?php
													printf(
														/* translators: 1: columns selected, 2: text columns in the table. */
														esc_html__( '%1$s of %2$s columns', 'cr-relocate-db' ),
														'<span class="crq-columns-selected">' . esc_html( number_format_i18n( count( $table_columns ) ) ) . '</span>',
														esc_html( number_format_i18n( count( $table_columns ) ) )
													);
													?>
													<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
												</summary>
												<fieldset class="crq-columns-menu">
													<?php /* translators: %s: table name. */ ?>
													<legend class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Columns of %s to search', 'cr-relocate-db' ), $table->name ) ); ?></legend>
													<?php foreach ( $table_columns as $column ) : ?>
														<label><input type="checkbox" name="columns[<?php echo esc_attr( $table->name ); ?>][]" value="<?php echo esc_attr( $column ); ?>" checked> <code><?php echo esc_html( $column ); ?></code></label>
													<?php endforeach; ?>
												</fieldset>
											</details>
										<?php else : ?>
											<span class="crq-picker-none"><?php esc_html_e( 'No text columns', 'cr-relocate-db' ); ?></span>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</details>
					<?php endforeach; ?>
					<p id="crq-table-none" class="crq-empty" hidden><?php esc_html_e( 'No tables match the filter.', 'cr-relocate-db' ); ?></p>
				</fieldset>
			</div>

			<details class="crq-advanced">
				<summary>
					<?php esc_html_e( 'Advanced options', 'cr-relocate-db' ); ?>
					<span id="crq-advanced-summary" class="crq-advanced-summary"></span>
				</summary>
				<fieldset class="crq-options">
					<legend class="screen-reader-text"><?php esc_html_e( 'Advanced options', 'cr-relocate-db' ); ?></legend>
					<?php foreach ( $options as $name => [ $default, $label, $help, $short ] ) : ?>
						<label class="crq-option">
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" data-short="<?php echo esc_attr( $short ); ?>" <?php checked( $default ); ?>>
							<span>
								<strong><?php echo esc_html( $label ); ?></strong>
								<span class="description"><?php echo esc_html( $help ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			</details>

			<div class="crq-wizard-nav">
				<button type="button" class="button crq-button-lg" data-crq-back><span aria-hidden="true">←</span> <?php esc_html_e( 'Back', 'cr-relocate-db' ); ?></button>
				<button type="submit" class="button button-primary crq-button-lg">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<?php esc_html_e( 'Preview changes', 'cr-relocate-db' ); ?>
				</button>
			</div>
			<p class="crq-summary-note">
				<span class="dashicons dashicons-shield" aria-hidden="true"></span>
				<?php esc_html_e( 'The preview only reads the database. You confirm before anything is written.', 'cr-relocate-db' ); ?>
			</p>
		</section>
	</form>

	<?php require __DIR__ . '/partials/runner.php'; ?>
</div>

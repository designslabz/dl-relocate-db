<?php
/**
 * The folded "Change text while exporting / importing" section of the Import / Export forms.
 *
 * @package CraftRoq\Relocate
 *
 * @var string $text_change "export" or "import": which form it belongs to.
 */

use CraftRoq\Relocate\Jobs\JobStarter;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included from a method, so these variables are local.

$is_export = 'export' === $text_change;
?>
<details class="crq-advanced crq-text-change">
	<summary>
		<?php echo esc_html( $is_export ? __( 'Change text while exporting', 'cr-relocate-db' ) : __( 'Change text while importing', 'cr-relocate-db' ) ); ?>
		<span class="crq-advanced-summary"><?php esc_html_e( '· optional', 'cr-relocate-db' ); ?></span>
	</summary>
	<p class="description">
		<?php
		echo esc_html(
			$is_export
				? __( 'For example, replace the staging address with the live one. Serialized data and JSON stay valid, and your database here is not changed.', 'cr-relocate-db' )
				: __( 'For example, replace the old site’s address with this site’s, as the file goes in. Serialized data and JSON stay valid, and the file itself is not changed.', 'cr-relocate-db' )
		);
		?>
	</p>
	<ol id="crq-<?php echo esc_attr( $text_change ); ?>-pairs" class="crq-pairs" data-max="<?php echo esc_attr( (string) JobStarter::max_pairs() ); ?>">
		<li class="crq-pair">
			<label for="crq-<?php echo esc_attr( $text_change ); ?>-search-1"><?php esc_html_e( 'Find', 'cr-relocate-db' ); ?></label>
			<input type="text" id="crq-<?php echo esc_attr( $text_change ); ?>-search-1" name="search[]" class="large-text code" spellcheck="false" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other">
			<label for="crq-<?php echo esc_attr( $text_change ); ?>-replace-1"><?php esc_html_e( 'Replace with', 'cr-relocate-db' ); ?></label>
			<input type="text" id="crq-<?php echo esc_attr( $text_change ); ?>-replace-1" name="replace[]" class="large-text code" spellcheck="false" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other">
		</li>
	</ol>
	<div class="crq-pairs-footer">
		<button type="button" id="crq-<?php echo esc_attr( $text_change ); ?>-add-pair" class="crq-link-button" data-crq-add-pair="crq-<?php echo esc_attr( $text_change ); ?>-pairs">
			<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
			<?php esc_html_e( 'Add another', 'cr-relocate-db' ); ?>
		</button>
	</div>
</details>

<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate;

/**
 * Plugin settings, stored as a single option and edited through the Settings API.
 */
final class Settings {

	public const OPTION = 'crq_relocate_settings';

	private const DEFAULTS = array(
		'batch_size'     => 500,
		'retention_days' => 30,
		'delete_data'    => false,
	);

	private const MIN_BATCH_SIZE = 50;
	private const MAX_BATCH_SIZE = 5000;

	private const MAX_RETENTION_DAYS = 3650;

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter( 'option_page_capability_' . self::OPTION, fn() => Plugin::CAPABILITY );
	}

	public function register_setting(): void {
		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'array',
				'default'           => self::DEFAULTS,
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		add_settings_section( 'processing', __( 'Processing', 'cr-relocate-db' ), '__return_false', self::OPTION );

		add_settings_field(
			'batch_size',
			__( 'Rows per batch', 'cr-relocate-db' ),
			array( $this, 'render_batch_size_field' ),
			self::OPTION,
			'processing',
			array( 'label_for' => 'crq-relocate-batch-size' )
		);

		add_settings_section( 'data', __( 'Data', 'cr-relocate-db' ), '__return_false', self::OPTION );

		add_settings_field(
			'retention_days',
			__( 'Keep history for', 'cr-relocate-db' ),
			array( $this, 'render_retention_days_field' ),
			self::OPTION,
			'data',
			array( 'label_for' => 'crq-relocate-retention-days' )
		);

		add_settings_field(
			'delete_data',
			__( 'Uninstall', 'cr-relocate-db' ),
			array( $this, 'render_delete_data_field' ),
			self::OPTION,
			'data'
		);
	}

	/**
	 * @param mixed $input Raw submitted value.
	 * @return array{batch_size: int, retention_days: int, delete_data: bool}
	 */
	public function sanitize( mixed $input ): array {
		$input = is_array( $input ) ? $input : array();

		return array(
			'batch_size'     => min( self::MAX_BATCH_SIZE, max( self::MIN_BATCH_SIZE, absint( $input['batch_size'] ?? self::DEFAULTS['batch_size'] ) ) ),
			'retention_days' => min( self::MAX_RETENTION_DAYS, absint( $input['retention_days'] ?? self::DEFAULTS['retention_days'] ) ),
			'delete_data'    => ! empty( $input['delete_data'] ),
		);
	}

	public function render_retention_days_field(): void {
		printf(
			'<input type="number" id="crq-relocate-retention-days" name="%1$s[retention_days]" value="%2$d" min="0" max="%3$d" class="small-text" aria-describedby="crq-relocate-retention-days-description"> %4$s<p class="description" id="crq-relocate-retention-days-description">%5$s</p>',
			esc_attr( self::OPTION ),
			(int) $this->retention_days(),
			(int) self::MAX_RETENTION_DAYS,
			esc_html__( 'days', 'cr-relocate-db' ),
			esc_html__( 'Finished jobs, abandoned dry runs, their files of original values, and log entries older than this are deleted once a day. Enter 0 to keep everything.', 'cr-relocate-db' )
		);
	}

	public function render_batch_size_field(): void {
		printf(
			'<input type="number" id="crq-relocate-batch-size" name="%1$s[batch_size]" value="%2$d" min="%3$d" max="%4$d" step="50" class="small-text" aria-describedby="crq-relocate-batch-size-description"><p class="description" id="crq-relocate-batch-size-description">%5$s</p>',
			esc_attr( self::OPTION ),
			(int) $this->batch_size(),
			(int) self::MIN_BATCH_SIZE,
			(int) self::MAX_BATCH_SIZE,
			esc_html__( 'How many rows are read from a table at a time. Lower it if processing runs out of memory on tables with very large rows, such as page builder content.', 'cr-relocate-db' )
		);
	}

	public function render_delete_data_field(): void {
		printf(
			'<fieldset><legend class="screen-reader-text">%1$s</legend><label for="crq-relocate-delete-data"><input type="checkbox" id="crq-relocate-delete-data" name="%2$s[delete_data]" value="1" %3$s> %4$s</label><p class="description">%5$s</p></fieldset>',
			esc_html__( 'Uninstall', 'cr-relocate-db' ),
			esc_attr( self::OPTION ),
			checked( $this->delete_data_on_uninstall(), true, false ),
			esc_html__( 'Delete all plugin data when the plugin is deleted', 'cr-relocate-db' ),
			esc_html__( 'Removes the operation history, logs and settings. Your site content is not affected.', 'cr-relocate-db' )
		);
	}

	public function batch_size(): int {
		return (int) $this->all()['batch_size'];
	}

	public function retention_days(): int {
		return (int) $this->all()['retention_days'];
	}

	public function delete_data_on_uninstall(): bool {
		return (bool) $this->all()['delete_data'];
	}

	/**
	 * @return array{batch_size: int, retention_days: int, delete_data: bool}
	 */
	private function all(): array {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : array() );
	}
}

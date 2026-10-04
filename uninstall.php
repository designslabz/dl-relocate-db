<?php
/**
 * Removes plugin data on uninstall, if the site owner opted in.
 *
 * @package CraftRoq\Relocate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/Installer.php';
require_once __DIR__ . '/src/Storage.php';
require_once __DIR__ . '/src/Jobs/Cleanup.php';

if ( ( new CraftRoq\Relocate\Settings() )->delete_data_on_uninstall() ) {
	global $wpdb;
	( new CraftRoq\Relocate\Installer( $wpdb ) )->uninstall();
}

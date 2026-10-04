<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Database;

/**
 * Named database locks. MySQL releases one by itself if the request holding it
 * dies, so a crashed request never leaves anything stuck.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading and writing the database directly is what this plugin is for, and results must never come from a cache.
 */
final class Lock {

	public function __construct( private \wpdb $wpdb ) {}

	/**
	 * @param int $timeout Seconds to wait for the lock; 0 to give up straight away.
	 */
	public function acquire( string $name, int $timeout ): bool {
		$wpdb = $this->wpdb;

		return '1' === $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(SHA1(CONCAT(DATABASE(), %s)), %d)', $name, $timeout ) );
	}

	public function release( string $name ): void {
		$wpdb = $this->wpdb;

		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(SHA1(CONCAT(DATABASE(), %s)))', $name ) );
	}
}

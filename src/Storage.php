<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate;

use RuntimeException;

/**
 * The folder where the plugin keeps its files: before-images, exports and
 * uploaded imports, in uploads/crq-relocate/ under unguessable names.
 *
 * The folder is closed off for Apache, but that does not help on nginx, so
 * files are only ever handed out through authenticated download handlers.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 */
final class Storage {

	private const DIRECTORY = 'crq-relocate';

	public static function directory(): string {
		return wp_upload_dir( null, false )['basedir'] . '/' . self::DIRECTORY;
	}

	/**
	 * Creates the folder and closes it to the web.
	 *
	 * @throws RuntimeException When the folder cannot be created.
	 */
	public static function make_directory(): string {
		$directory = self::directory();

		if ( ! wp_mkdir_p( $directory ) ) {
			throw new RuntimeException( sprintf( 'Could not create the folder %s.', $directory ) );
		}

		$files = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
		);

		foreach ( $files as $name => $contents ) {
			if ( ! file_exists( $directory . '/' . $name ) ) {
				file_put_contents( $directory . '/' . $name, $contents );
			}
		}

		return $directory;
	}

	/**
	 * Moves an uploaded file, an entry of $_FILES, into the folder.
	 *
	 * @param array<string, mixed> $upload
	 * @param string               $name   Name to store it under, from self::name().
	 * @throws RuntimeException When the folder cannot be created.
	 */
	public static function receive( array $upload, string $name ): bool {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$directory = self::make_directory();
		$into      = fn( array $uploads ): array => array(
			'path'   => $directory,
			'url'    => $uploads['baseurl'] . '/' . self::DIRECTORY,
			'subdir' => '/' . self::DIRECTORY,
		) + $uploads;

		add_filter( 'upload_dir', $into );

		$moved = wp_handle_upload(
			$upload,
			array(
				'test_form'                => false,
				// The caller checks the extension and that the file is not empty. WordPress has no
				// type for .sql, and a dump holding binary data does not look like text to it.
				'test_type'                => false,
				'test_size'                => false,
				'unique_filename_callback' => fn(): string => $name,
			)
		);

		remove_filter( 'upload_dir', $into );

		return isset( $moved['file'] ) && empty( $moved['error'] );
	}

	/**
	 * A new, unguessable file name.
	 *
	 * @param string $prefix    e.g. "export-12".
	 * @param string $extension e.g. ".sql.gz".
	 */
	public static function name( string $prefix, string $extension ): string {
		return $prefix . '-' . wp_generate_password( 24, false ) . $extension;
	}

	/**
	 * Full path of an existing file in the folder, or null.
	 */
	public static function path( string $file ): ?string {
		if ( '' === $file ) {
			return null;
		}

		$path = self::directory() . '/' . basename( $file );

		return is_file( $path ) ? $path : null;
	}

	public static function delete( string $file ): void {
		$path = self::path( $file );

		if ( null !== $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Cuts a file back to a size saved earlier, dropping anything a request
	 * that died part-way appended after it.
	 */
	public static function truncate( string $path, int $size ): void {
		clearstatcache( true, $path );

		if ( is_file( $path ) && filesize( $path ) > $size ) {
			$handle = fopen( $path, 'r+b' );
			if ( false !== $handle ) {
				ftruncate( $handle, $size );
				fclose( $handle );
			}
		}

		clearstatcache( true, $path );
	}

	/**
	 * Removes every file and the folder. Used on uninstall.
	 */
	public static function delete_all(): void {
		$directory = self::directory();

		if ( ! is_dir( $directory ) ) {
			return;
		}

		$names = scandir( $directory );

		foreach ( false === $names ? array() : $names as $name ) {
			if ( is_file( $directory . '/' . $name ) ) {
				wp_delete_file( $directory . '/' . $name );
			}
		}

		rmdir( $directory );
	}

	/**
	 * Streams a file as a download and ends the request.
	 */
	public static function send( string $path, string $download_name, string $content_type ): void {
		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		readfile( $path ); // Streams a large file without loading it into memory.
		exit;
	}
}

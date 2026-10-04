<?php
declare( strict_types=1 );

namespace CraftRoq\Relocate\Transfer;

use CraftRoq\Relocate\Installer;
use CraftRoq\Relocate\Jobs\JobStatus;
use CraftRoq\Relocate\Replace\Replacement;
use CraftRoq\Relocate\Replace\Replacer;
use CraftRoq\Relocate\Storage;
use RuntimeException;

/**
 * Runs an uploaded SQL file into the database, a statement at a time.
 *
 * A gzipped file is first unpacked next to it. Then statements run in order,
 * the file position saved after each one, so an interrupted import carries on
 * from the statement after the last one that ran.
 *
 * Each step is a new database connection, which forgets session settings,
 * so SET statements from the file are remembered and repeated at the start
 * of every step. Statements that would break a step-by-step import, or touch
 * this plugin's own tables, are skipped: LOCK/UNLOCK TABLES (a lock does not
 * outlive the request, and would block saving progress), USE and CREATE or
 * DROP DATABASE (they would switch or remove the database).
 *
 * Text can be changed on the way in: the string values of INSERT and REPLACE
 * statements go through the same serialization-safe Replacer as Search &
 * Replace (see SqlValues).
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are data, not output: they are escaped where they are shown.
 * phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reading and writing the database directly is what this plugin is for, and results must never come from a cache.
 */
final class Importer {

	private const UNPACK_CHUNK = 4194304;

	// Enough to restore mysqldump's session settings and the variables it saves them in.
	private const MAX_SESSION_STATEMENTS = 50;

	private const TABLE_STATEMENT = '/^(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+(?:TEMPORARY\s+)?TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT(?:\s+(?:IGNORE|LOW_PRIORITY|DELAYED|HIGH_PRIORITY))*(?:\s+INTO)?|REPLACE(?:\s+(?:LOW_PRIORITY|DELAYED))*(?:\s+INTO)?|ALTER\s+TABLE|TRUNCATE(?:\s+TABLE)?|UPDATE|DELETE\s+FROM)\s+(?:`?[^`\s.]+`?\.)?`?([^`\s(,;.]+)`?/i';

	private ?Replacer $replacer = null;

	/**
	 * Every search value as it appears inside an SQL string, to skip statements that cannot match.
	 *
	 * @var list<string>
	 */
	private array $needles = array();

	public function __construct(
		private \wpdb $wpdb,
		private TransferRepository $transfers
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public static function initial_state( bool $compressed, int $size ): array {
		return array(
			'phase'        => $compressed ? 'unpack' : 'run',
			'compressed'   => $compressed,
			'unpacked'     => 0,
			'sql_file'     => '',
			'size'         => $compressed ? 0 : $size,
			'offset'       => 0,
			'delimiter'    => ';',
			'session'      => array(),
			'statements'   => 0,
			'skipped'      => 0,
			// Values changed on the way in, and matches left alone because changing them could corrupt them.
			'replacements' => 0,
			'unchanged'    => 0,
			'tables'       => array(),
		);
	}

	/**
	 * Reads the table prefix from the header of a file this plugin exported.
	 *
	 * @return string|null Null when the file does not say.
	 */
	public static function file_prefix( string $path ): ?string {
		$handle = gzopen( $path, 'rb' );

		if ( false === $handle ) {
			return null;
		}

		$head = (string) gzread( $handle, 2048 );
		gzclose( $handle );

		return 1 === preg_match( '/^-- Table prefix: (\S+)$/m', $head, $match ) ? $match[1] : null;
	}

	/**
	 * Works until the deadline.
	 *
	 * @throws RuntimeException When a statement fails or a file cannot be read.
	 */
	public function step( Transfer $import, float $deadline ): void {
		$import->state += array(
			'replacements' => 0,
			'unchanged'    => 0,
		);

		$pairs          = $import->settings['pairs'] ?? array();
		$this->replacer = null;
		$this->needles  = array();

		if ( $pairs ) {
			$replacement    = new Replacement( $pairs );
			$this->replacer = new Replacer( $replacement );
			$this->needles  = array_map( fn( array $pair ): string => SqlValues::escape( $pair['search'] ), $replacement->pairs );
		}

		if ( 'unpack' === $import->state['phase'] ) {
			$this->unpack( $import, $deadline );

			if ( 'unpack' === $import->state['phase'] || microtime( true ) >= $deadline ) {
				return;
			}
		}

		$path = Storage::path( $import->file );

		if ( null === $path ) {
			throw new RuntimeException( 'The uploaded file is no longer there. Upload it again.' );
		}

		$this->restore_session( $import );

		$reader = new SqlReader( $path, (int) $import->state['offset'], (string) $import->state['delimiter'] );

		for ( $statement = $reader->next(); null !== $statement; $statement = $reader->next() ) {
			$this->run( $import, $statement );

			$import->state['offset']    = $reader->offset();
			$import->state['delimiter'] = $reader->delimiter();
			++$import->state['statements'];
			$this->transfers->save( $import );

			if ( microtime( true ) >= $deadline ) {
				return;
			}
		}

		$import->state['offset'] = $reader->offset();
		unset( $reader );

		// The SQL has done its job; the file can be large, so it goes now.
		Storage::delete( $import->file );
		$import->file = '';
		$import->finish( JobStatus::Completed );
		$this->transfers->save( $import );

		// Rows changed behind the object cache's back.
		wp_cache_flush();
	}

	/**
	 * @throws RuntimeException When the statement fails.
	 */
	private function run( Transfer $import, string $statement ): void {
		// For recognising a statement, look inside MySQL's executable /*!40101 ... */ comments.
		$head = ltrim( (string) preg_replace( '#^/\*!\d*\s*#', '', $statement ) );

		if ( 1 === preg_match( '/^(?:(?:LOCK|UNLOCK)\s+TABLES?\b|USE\s|(?:CREATE|DROP)\s+(?:DATABASE|SCHEMA)\b)/i', $head ) ) {
			++$import->state['skipped'];
			return;
		}

		if ( 1 === preg_match( self::TABLE_STATEMENT, $head, $match ) ) {
			if ( str_starts_with( $match[1], $this->wpdb->prefix . Installer::TABLE_PREFIX ) ) {
				++$import->state['skipped'];
				return;
			}

			if ( ! in_array( $match[1], $import->state['tables'], true ) ) {
				$import->state['tables'][] = $match[1];
			}
		}

		if ( 1 === preg_match( '/^SET\s/i', $head ) ) {
			$this->remember( $import, $statement );
		}

		if ( $this->replacer && 1 === preg_match( '/^(?:INSERT|REPLACE)\b/i', $head ) ) {
			$statement = $this->replace_values( $import, $statement );
		}

		$this->query( $statement );
	}

	private function replace_values( Transfer $import, string $statement ): string {
		$found = false;
		foreach ( $this->needles as $needle ) {
			if ( str_contains( $statement, $needle ) ) {
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return $statement;
		}

		return SqlValues::rewrite(
			$statement,
			function ( string $value ) use ( $import ): ?string {
				try {
					$result = $this->replacer->replace( $value );
				} catch ( RuntimeException ) {
					++$import->state['unchanged'];
					return null;
				}

				if ( null !== $result->skipped ) {
					++$import->state['unchanged'];
					return null;
				}

				if ( ! $result->count ) {
					return null;
				}

				$import->state['replacements'] += $result->count;
				return $result->value;
			}
		);
	}

	/**
	 * @throws RuntimeException When a statement fails.
	 */
	private function restore_session( Transfer $import ): void {
		$this->query( 'SET FOREIGN_KEY_CHECKS = 0' );

		foreach ( $import->state['session'] as $statement ) {
			$this->query( $statement );
		}
	}

	private function remember( Transfer $import, string $statement ): void {
		$session   = array_values( array_diff( $import->state['session'], array( $statement ) ) );
		$session[] = $statement;

		$import->state['session'] = array_slice( $session, -self::MAX_SESSION_STATEMENTS );
	}

	/**
	 * @throws RuntimeException When the statement fails.
	 */
	private function query( string $sql ): void {
		$wpdb = $this->wpdb;

		// The file is SQL from an administrator. wpdb would otherwise check every
		// statement's text against the target table's character set, which can
		// reject valid dumps of non-UTF-8 tables and costs a query per table.
		// Only for this statement: wpdb caches nothing while the filter answers,
		// so left on it would break every other write of the request, such as
		// saving the import's own progress.
		$skip_charset_check = fn( $charset ) => $charset ?? 'binary';
		add_filter( 'pre_get_table_charset', $skip_charset_check );

		// A failure is reported with the job, not printed into the REST response.
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Running the statements of the SQL file an administrator uploaded is what an import is; there is nothing to prepare.
		$result = $wpdb->query( $sql );
		$wpdb->suppress_errors( $suppressed );

		remove_filter( 'pre_get_table_charset', $skip_charset_check );

		if ( false === $result ) {
			$excerpt = function_exists( 'mb_substr' ) ? mb_substr( $sql, 0, 120 ) : substr( $sql, 0, 120 );
			throw new RuntimeException( sprintf( '%1$s (in: %2$s…)', $wpdb->last_error, $excerpt ) );
		}
	}

	/**
	 * Unpacks a gzipped upload into a plain .sql file next to it.
	 *
	 * @throws RuntimeException When a file cannot be read or written.
	 */
	private function unpack( Transfer $import, float $deadline ): void {
		$source = Storage::path( $import->file );

		if ( null === $source ) {
			throw new RuntimeException( 'The uploaded file is no longer there. Upload it again.' );
		}

		if ( '' === $import->state['sql_file'] ) {
			$import->state['sql_file'] = Storage::name( 'import-' . $import->id, '.sql' );
			$this->transfers->save( $import );
		}

		$target = Storage::directory() . '/' . $import->state['sql_file'];
		Storage::truncate( $target, (int) $import->state['unpacked'] );

		$in  = gzopen( $source, 'rb' );
		$out = fopen( $target, 'ab' );

		if ( false === $in || false === $out ) {
			throw new RuntimeException( 'Could not open the uploaded file to unpack it.' );
		}

		gzseek( $in, (int) $import->state['unpacked'] );

		// At least one chunk per step, however short the step, so it always moves on.
		do {
			$chunk = gzread( $in, self::UNPACK_CHUNK );

			if ( false === $chunk ) {
				throw new RuntimeException( 'The uploaded file is damaged and cannot be unpacked.' );
			}

			if ( strlen( $chunk ) !== fwrite( $out, $chunk ) ) {
				throw new RuntimeException( 'Could not unpack the uploaded file. The disk may be full.' );
			}

			$import->state['unpacked'] += strlen( $chunk );
		} while ( ! gzeof( $in ) && microtime( true ) < $deadline );

		$finished = gzeof( $in );
		gzclose( $in );
		fclose( $out );

		if ( $finished ) {
			Storage::delete( $import->file );
			$import->file              = $import->state['sql_file'];
			$import->state['sql_file'] = '';
			$import->state['size']     = $import->state['unpacked'];
			$import->state['phase']    = 'run';
		}

		$this->transfers->save( $import );
	}
}

<?php
/**
 * WP-CLI commands for LearnDash bulk import.
 *
 * @package Extended_LearnDash_Bulk_Create
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ELDBC_CLI {

	/** @var Extended_LearnDash_Bulk_Create */
	private static $plugin;

	/**
	 * @param Extended_LearnDash_Bulk_Create $plugin Main plugin instance.
	 */
	public static function register( $plugin ) {
		self::$plugin = $plugin;
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'learndash-bulk', self::class );
		}
	}

	/**
	 * Import rows from a CSV file (title-based upsert).
	 *
	 * ## OPTIONS
	 *
	 * <csv>
	 * : Path to the CSV file.
	 *
	 * [--content-type=<type>]
	 * : Post type slug, e.g. sfwd-lessons, sfwd-topic. Required.
	 *
	 * [--media-dir=<path>]
	 * : Root folder of the export (contains `media/` or equivalent paths referenced in HTML).
	 *
	 * [--user=<login|id>]
	 * : User for attribution (attachments). Defaults to the first user with the administrator role.
	 *
	 * [--overwrite]
	 * : When set, updates posts that already exist (matched by optional ID or unique title).
	 *
	 * ## EXAMPLES
	 *
	 *     wp learndash-bulk import ./course.csv --content-type=sfwd-lessons --media-dir=./export --overwrite
	 *
	 * @when after_wp_load
	 *
	 * @param string[] $args       Positional args.
	 * @param array    $assoc_args Associative args.
	 */
	public function import( $args, $assoc_args ) {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please provide a path to a CSV file.' );
		}

		$csv_path = $args[0];
		if ( ! is_readable( $csv_path ) ) {
			\WP_CLI::error( sprintf( 'CSV file not readable: %s', $csv_path ) );
		}

		$content_type = isset( $assoc_args['content-type'] ) ? sanitize_key( $assoc_args['content-type'] ) : '';
		if ( $content_type === '' ) {
			\WP_CLI::error( 'The --content-type argument is required (e.g. sfwd-lessons).' );
		}

		$media_dir = isset( $assoc_args['media-dir'] ) ? wp_normalize_path( $assoc_args['media-dir'] ) : '';
		if ( $media_dir !== '' && ( ! is_dir( $media_dir ) || ! is_readable( $media_dir ) ) ) {
			\WP_CLI::error( sprintf( 'media-dir is not a readable directory: %s', $media_dir ) );
		}

		$overwrite = isset( $assoc_args['overwrite'] );

		if ( ! empty( $assoc_args['user'] ) ) {
			$u = is_numeric( $assoc_args['user'] )
				? get_user_by( 'id', (int) $assoc_args['user'] )
				: get_user_by( 'login', $assoc_args['user'] );
			if ( ! $u ) {
				\WP_CLI::error( 'User not found for --user.' );
			}
			wp_set_current_user( $u->ID );
		} else {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => array( 'ID' ),
				)
			);
			if ( ! empty( $admins[0]->ID ) ) {
				wp_set_current_user( (int) $admins[0]->ID );
			}
		}

		$result = self::$plugin->run_import_cli(
			$csv_path,
			array(
				'content_type' => $content_type,
				'media_dir'    => $media_dir,
				'overwrite'    => $overwrite,
			)
		);

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success(
			sprintf(
				'Import finished. Created: %d | Updated: %d | Skipped: %d | Errors: %d',
				(int) $result['created'],
				(int) $result['updated'],
				(int) $result['skipped'],
				count( $result['errors'] )
			)
		);

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $err ) {
				\WP_CLI::warning( $err );
			}
		}
	}
}

<?php
/**
 * Plugin Name: LearnDash Bulk Lessons Or Topics
 * Description: Adds functionality to bulk create Courses, Lessons, or Topics in LearnDash using a CSV file.
 * Version: 1.2.0
 * Author: Vlad Tudorie
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: learndash-bulk-lessons-or-topics
 * Domain Path: /languages
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-eldbc-media.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-eldbc-cli.php';

class Extended_LearnDash_Bulk_Create {

	/** @var string[] */
	private $supported_post_types = array( 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-question' );

	/** @var string */
	private $bulk_notice_html = '';

	/** @var string */
	private $bulk_notice_type = 'success';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'plugins_loaded', array( $this, 'register_cli' ), 20 );
	}

	public function register_cli() {
		ELDBC_CLI::register( $this );
	}

	public function add_admin_menu() {
		add_submenu_page(
			'learndash-lms',
			__( 'Bulk Import', 'extended-learndash-bulk-create' ),
			__( 'Bulk Import', 'extended-learndash-bulk-create' ),
			'manage_options',
			'extended-learndash-bulk-create',
			array( $this, 'admin_page' )
		);
	}

	public function enqueue_admin_scripts( $hook ) {
		if ( 'learndash-lms_page_extended-learndash-bulk-create' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'extended-learndash-bulk-create', plugin_dir_url( __FILE__ ) . 'js/admin.js', array( 'jquery' ), '1.3', true );
	}

	public function admin_page() {
		if ( ! empty( $_GET['eldbc_notice'] ) && current_user_can( 'manage_options' ) ) {
			$data = get_transient( 'eldbc_bulk_result_' . get_current_user_id() );
			if ( is_array( $data ) && isset( $data['stats'] ) ) {
				delete_transient( 'eldbc_bulk_result_' . get_current_user_id() );
				$this->display_import_notice_from_transient( $data['stats'] );
			}
		}
		include plugin_dir_path( __FILE__ ) . 'templates/admin-page.php';
	}

	/**
	 * Output completion notice after redirect (transient) or inline.
	 */
	public function render_bulk_admin_notice() {
		if ( $this->bulk_notice_html === '' ) {
			return;
		}

		$message = $this->bulk_notice_html;
		$type    = $this->bulk_notice_type;
		$this->bulk_notice_html   = '';
		$this->bulk_notice_type = 'success';

		$args = array(
			'type'           => $type,
			'dismissible'    => true,
			'id'             => 'eldbc-bulk-result',
			'paragraph_wrap' => false,
		);

		if ( function_exists( 'wp_admin_notice' ) ) {
			wp_admin_notice( $message, $args );
			return;
		}

		$classes = 'notice';
		if ( '' !== $type ) {
			$classes .= ' notice-' . sanitize_html_class( $type );
		}
		if ( ! empty( $args['dismissible'] ) ) {
			$classes .= ' is-dismissible';
		}
		printf(
			'<div id="%1$s" class="%2$s">%3$s</div>',
			esc_attr( $args['id'] ),
			esc_attr( $classes ),
			wp_kses_post( $message )
		);
	}

	public function handle_form_submission() {
		if ( ! isset( $_POST['submit'] ) || ! check_admin_referer( 'extended_learndash_bulk_create', 'extended_learndash_bulk_create_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'extended-learndash-bulk-create' ) );
		}

		$content_type = isset( $_POST['content_type'] ) ? sanitize_key( wp_unslash( $_POST['content_type'] ) ) : '';

		if ( ! in_array( $content_type, $this->supported_post_types, true ) ) {
			wp_die( esc_html__( 'Invalid content type.', 'extended-learndash-bulk-create' ) );
		}

		if ( ! isset( $_FILES['csv_file'] ) || UPLOAD_ERR_OK !== (int) $_FILES['csv_file']['error'] ) {
			wp_die( esc_html__( 'CSV file upload failed. Please try again.', 'extended-learndash-bulk-create' ) );
		}

		$csv_file = isset( $_FILES['csv_file']['tmp_name'] ) ? $_FILES['csv_file']['tmp_name'] : '';
		$parsed   = $this->parse_csv_file( $csv_file );
		if ( is_wp_error( $parsed ) ) {
			wp_die( esc_html( $parsed->get_error_message() ) );
		}

		$overwrite = ! empty( $_POST['eldbc_overwrite'] );

		$media_dir = '';
		if ( ! empty( $_FILES['media_files']['name'] ) && is_array( $_FILES['media_files']['name'] ) ) {
			$staged = $this->stage_uploaded_media_directory( $_FILES['media_files'] );
			if ( is_wp_error( $staged ) ) {
				wp_die( esc_html( $staged->get_error_message() ) );
			}
			$media_dir = $staged;
		}

		$stats = $this->run_import(
			$content_type,
			$parsed['headers'],
			$parsed['rows'],
			array(
				'overwrite' => $overwrite,
				'media_dir' => $media_dir,
			)
		);

		if ( $media_dir !== '' ) {
			$this->delete_directory_recursive( $media_dir );
		}

		set_transient(
			'eldbc_bulk_result_' . get_current_user_id(),
			array( 'stats' => $stats ),
			120
		);
		wp_safe_redirect( admin_url( 'admin.php?page=extended-learndash-bulk-create&eldbc_notice=1' ) );
		exit;
	}

	/**
	 * @param string $path Absolute path to CSV.
	 * @return array{headers:string[], rows:array<int,array<int,string>>}|WP_Error
	 */
	public function parse_csv_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'eldbc_csv', __( 'CSV file is not readable.', 'extended-learndash-bulk-create' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			return new WP_Error( 'eldbc_csv', __( 'Could not read CSV file.', 'extended-learndash-bulk-create' ) );
		}
		$lines = preg_split( '/\r\n|\r|\n/', $raw );
		if ( ! is_array( $lines ) || empty( $lines ) ) {
			return new WP_Error( 'eldbc_csv', __( 'CSV is empty.', 'extended-learndash-bulk-create' ) );
		}
		$csv_data = array_map( 'str_getcsv', $lines );
		if ( empty( $csv_data ) ) {
			return new WP_Error( 'eldbc_csv', __( 'CSV is empty.', 'extended-learndash-bulk-create' ) );
		}
		$headers = array_shift( $csv_data );
		$headers = array_map( 'trim', $headers );
		return array(
			'headers' => $headers,
			'rows'    => $csv_data,
		);
	}

	/**
	 * @param string $csv_path Absolute path.
	 * @param array{content_type:string,media_dir:string,overwrite:bool} $options Options.
	 * @return array|WP_Error
	 */
	public function run_import_cli( $csv_path, $options ) {
		$parsed = $this->parse_csv_file( $csv_path );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		return $this->run_import(
			$options['content_type'],
			$parsed['headers'],
			$parsed['rows'],
			array(
				'overwrite' => ! empty( $options['overwrite'] ),
				'media_dir' => isset( $options['media_dir'] ) ? $options['media_dir'] : '',
			)
		);
	}

	/**
	 * @param string $content_type Post type.
	 * @param string[] $headers CSV headers.
	 * @param array<int,array<int,string>> $rows CSV rows.
	 * @param array{overwrite?:bool,media_dir?:string} $options Options.
	 * @return array{created:int,updated:int,skipped:int,errors:string[],created_entries:array,updated_entries:array,skipped_entries:array}
	 */
	public function run_import( $content_type, $headers, $rows, $options ) {
		$overwrite = ! empty( $options['overwrite'] );
		$media_dir = isset( $options['media_dir'] ) ? (string) $options['media_dir'] : '';
		$media_dir = $media_dir !== '' ? wp_normalize_path( $media_dir ) : '';

		$stats = array(
			'created'           => 0,
			'updated'           => 0,
			'skipped'           => 0,
			'errors'            => array(),
			'created_entries'   => array(),
			'updated_entries'   => array(),
			'skipped_entries'   => array(),
		);

		foreach ( $rows as $row_index => $row ) {
			if ( $this->is_csv_row_empty( $row ) ) {
				continue;
			}
			if ( count( $row ) !== count( $headers ) ) {
				$stats['errors'][] = sprintf(
					/* translators: %d: row number (1-based data row) */
					__( 'Row %d: column count does not match header.', 'extended-learndash-bulk-create' ),
					$row_index + 2
				);
				continue;
			}

			$post_data = array_combine( $headers, $row );
			if ( false === $post_data ) {
				$stats['errors'][] = sprintf(
					__( 'Row %d: could not parse columns.', 'extended-learndash-bulk-create' ),
					$row_index + 2
				);
				continue;
			}

			if ( ! empty( $post_data['post_type'] ) && trim( (string) $post_data['post_type'] ) !== '' ) {
				if ( sanitize_key( $post_data['post_type'] ) !== sanitize_key( $content_type ) ) {
					$stats['errors'][] = sprintf(
						/* translators: 1: row number, 2: CSV post_type, 3: selected type */
						__( 'Row %1$d: CSV post_type (%2$s) does not match selected content type (%3$s).', 'extended-learndash-bulk-create' ),
						$row_index + 2,
						$post_data['post_type'],
						$content_type
					);
					continue;
				}
			}

			$row_media_errors = array();
			$post_data        = $this->apply_media_rewrite_to_post_data( $post_data, $media_dir, $row_media_errors );
			foreach ( $row_media_errors as $me ) {
				$stats['errors'][] = sprintf(
					/* translators: 1: row number, 2: message */
					__( 'Row %1$d: %2$s', 'extended-learndash-bulk-create' ),
					$row_index + 2,
					$me
				);
			}

			$one = $this->import_single_row( $content_type, $post_data, $overwrite, $row_index );
			if ( 'error' === $one['status'] ) {
				$stats['errors'][] = sprintf(
					__( 'Row %1$d: %2$s', 'extended-learndash-bulk-create' ),
					$one['row'],
					$one['message']
				);
				continue;
			}
			if ( 'created' === $one['status'] ) {
				++$stats['created'];
				$stats['created_entries'][] = $one['entry'];
				continue;
			}
			if ( 'updated' === $one['status'] ) {
				++$stats['updated'];
				$stats['updated_entries'][] = $one['entry'];
				continue;
			}
			if ( 'skipped' === $one['status'] ) {
				++$stats['skipped'];
				$stats['skipped_entries'][] = $one['entry'];
			}
		}

		return $stats;
	}

	/**
	 * @param array<int,string> $row CSV row.
	 */
	private function is_csv_row_empty( $row ) {
		foreach ( $row as $cell ) {
			if ( trim( (string) $cell ) !== '' ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string,string> $post_data Row data.
	 * @param string                 $media_dir Absolute path or empty.
	 * @param string[]               $errors Collected errors.
	 * @return array<string,string>
	 */
	private function apply_media_rewrite_to_post_data( $post_data, $media_dir, array &$errors ) {
		if ( $media_dir === '' ) {
			return $post_data;
		}
		$content = isset( $post_data['post_content'] ) ? $post_data['post_content'] : '';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName -- class from plugin
		$post_data['post_content'] = ELDBC_Media::rewrite_paths( $content, $media_dir, $errors );
		return $post_data;
	}

	/**
	 * @param string               $content_type Post type.
	 * @param array<string,string> $post_data Row.
	 * @param bool                 $overwrite Overwrite flag.
	 * @param int                  $row_index Zero-based row index.
	 * @return array{status:string,row?:int,message?:string,entry?:array{id:int,title:string}}
	 */
	private function import_single_row( $content_type, $post_data, $overwrite, $row_index ) {
		$row_num = $row_index + 2;
		$target  = $this->find_post_for_row( $content_type, $post_data );
		if ( is_wp_error( $target ) ) {
			return array(
				'status'  => 'error',
				'row'     => $row_num,
				'message' => $target->get_error_message(),
			);
		}

		$post_id = (int) $target;
		if ( 0 === $post_id ) {
			$result = $this->create_content( $content_type, $post_data );
			if ( is_int( $result ) && $result > 0 ) {
				return array(
					'status' => 'created',
					'entry'  => array(
						'id'    => $result,
						'title' => get_the_title( $result ),
					),
				);
			}
			return array(
				'status'  => 'error',
				'row'     => $row_num,
				'message' => is_string( $result ) ? $result : __( 'Create failed.', 'extended-learndash-bulk-create' ),
			);
		}

		if ( ! $overwrite ) {
			return array(
				'status' => 'skipped',
				'entry'  => array(
					'id'    => $post_id,
					'title' => get_the_title( $post_id ),
				),
			);
		}

		$result = $this->update_content( $post_id, $content_type, $post_data );
		if ( is_int( $result ) && $result > 0 ) {
			return array(
				'status' => 'updated',
				'entry'  => array(
					'id'    => $result,
					'title' => get_the_title( $result ),
				),
			);
		}
		return array(
			'status'  => 'error',
			'row'     => $row_num,
			'message' => is_string( $result ) ? $result : __( 'Update failed.', 'extended-learndash-bulk-create' ),
		);
	}

	/**
	 * @param string               $content_type Selected post type.
	 * @param array<string,string> $post_data Row.
	 * @return int|WP_Error Zero if no match; positive ID; WP_Error on ambiguity or bad ID.
	 */
	private function find_post_for_row( $content_type, $post_data ) {
		if ( ! in_array( $content_type, $this->supported_post_types, true ) ) {
			return new WP_Error(
				'eldbc_type',
				sprintf(
					/* translators: %s: content type */
					__( 'Invalid content type: %s', 'extended-learndash-bulk-create' ),
					$content_type
				)
			);
		}

		$id_col = isset( $post_data['ID'] ) ? trim( (string) $post_data['ID'] ) : '';
		if ( $id_col !== '' && ctype_digit( $id_col ) ) {
			$pid = (int) $id_col;
			$post = get_post( $pid );
			if ( ! $post || $post->post_type !== $content_type ) {
				return new WP_Error(
					'eldbc_id',
					sprintf(
						/* translators: %d: post ID */
						__( 'ID %d is missing or does not match the selected content type.', 'extended-learndash-bulk-create' ),
						$pid
					)
				);
			}
			return $pid;
		}

		$title = isset( $post_data['post_title'] ) ? trim( wp_unslash( $post_data['post_title'] ) ) : '';
		if ( $title === '' ) {
			return new WP_Error( 'eldbc_title', __( 'post_title is required when ID is empty.', 'extended-learndash-bulk-create' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC LIMIT 3",
				$title,
				$content_type
			)
		);
		$ids = array_map( 'intval', $ids );
		$n   = count( $ids );
		if ( 0 === $n ) {
			return 0;
		}
		if ( 1 === $n ) {
			return $ids[0];
		}
		return new WP_Error(
			'eldbc_duplicate',
			sprintf(
				/* translators: %s: duplicate title */
				__( 'Multiple posts share the title "%s" for this content type.', 'extended-learndash-bulk-create' ),
				$title
			)
		);
	}

	/**
	 * Write media staging diagnostics to the PHP error log when WP_DEBUG_LOG is enabled.
	 * Use this to verify max_file_uploads / per-file upload errors (Suggestion A2).
	 *
	 * @param string $message Message (no trailing newline).
	 */
	private function eldbc_log_media_staging( $message ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		if ( ! function_exists( 'error_log' ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[ELDBC media staging] ' . $message );
	}

	/**
	 * Human-readable label for PHP upload error codes.
	 *
	 * @param int $code Value from $_FILES[*]['error'].
	 * @return string
	 */
	private function eldbc_upload_err_label( $code ) {
		$code = (int) $code;
		$map  = array(
			UPLOAD_ERR_OK         => 'UPLOAD_ERR_OK',
			UPLOAD_ERR_INI_SIZE   => 'UPLOAD_ERR_INI_SIZE (exceeds upload_max_filesize)',
			UPLOAD_ERR_FORM_SIZE  => 'UPLOAD_ERR_FORM_SIZE (exceeds HTML MAX_FILE_SIZE)',
			UPLOAD_ERR_PARTIAL    => 'UPLOAD_ERR_PARTIAL',
			UPLOAD_ERR_NO_FILE    => 'UPLOAD_ERR_NO_FILE',
			UPLOAD_ERR_NO_TMP_DIR => 'UPLOAD_ERR_NO_TMP_DIR',
			UPLOAD_ERR_CANT_WRITE => 'UPLOAD_ERR_CANT_WRITE',
			UPLOAD_ERR_EXTENSION  => 'UPLOAD_ERR_EXTENSION',
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : 'UNKNOWN(' . $code . ')';
	}

	/**
	 * @param array $files The $_FILES['media_files'] array shape.
	 * @return string|WP_Error Staging directory path.
	 */
	private function stage_uploaded_media_directory( $files ) {
		if ( empty( $files['name'] ) || ! is_array( $files['name'] ) ) {
			$this->eldbc_log_media_staging( 'No media_files[name] array; nothing to stage.' );
			return '';
		}

		$n_incoming = count( $files['name'] );
		$this->eldbc_log_media_staging(
			sprintf(
				'Start: incoming_files=%d | PHP max_file_uploads=%s | post_max_size=%s | upload_max_filesize=%s',
				$n_incoming,
				ini_get( 'max_file_uploads' ),
				ini_get( 'post_max_size' ),
				ini_get( 'upload_max_filesize' )
			)
		);

		$tmp = trailingslashit( get_temp_dir() ) . 'eldbc_' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $tmp ) ) {
			return new WP_Error( 'eldbc_stage', __( 'Could not create temporary directory for media.', 'extended-learndash-bulk-create' ) );
		}

		$this->eldbc_log_media_staging( 'Staging directory: ' . $tmp );

		$staged_ok           = 0;
		$skipped_empty_name  = 0;
		$skipped_upload_err  = 0;
		$skipped_empty_tmp   = 0;
		$copy_fallback_used  = 0;

		$n = $n_incoming;
		for ( $i = 0; $i < $n; $i++ ) {
			if ( empty( $files['name'][ $i ] ) ) {
				++$skipped_empty_name;
				$this->eldbc_log_media_staging( sprintf( 'index=%d skip=empty_name', $i ) );
				continue;
			}

			$err = isset( $files['error'][ $i ] ) ? (int) $files['error'][ $i ] : -1;
			if ( UPLOAD_ERR_OK !== $err ) {
				++$skipped_upload_err;
				$this->eldbc_log_media_staging(
					sprintf(
						'index=%d skip=upload_error name=%s code=%d (%s)',
						$i,
						$files['name'][ $i ],
						$err,
						$this->eldbc_upload_err_label( $err )
					)
				);
				continue;
			}

			$relative = str_replace( '\\', '/', $files['name'][ $i ] );
			$relative = ltrim( $relative, '/' );
			$dest     = $tmp . '/' . $relative;
			$dir      = dirname( $dest );
			if ( ! wp_mkdir_p( $dir ) ) {
				$this->delete_directory_recursive( $tmp );
				return new WP_Error( 'eldbc_stage', __( 'Could not create folder for uploaded media.', 'extended-learndash-bulk-create' ) );
			}
			if ( empty( $files['tmp_name'][ $i ] ) ) {
				++$skipped_empty_tmp;
				$this->eldbc_log_media_staging(
					sprintf( 'index=%d skip=empty_tmp_name relative=%s (error was OK)', $i, $relative )
				);
				continue;
			}

			$tmp_src = $files['tmp_name'][ $i ];
			$via_copy = false;
			// Do not require is_uploaded_file(): some browsers / PHP builds report false for valid
			// webkitdirectory multipart files even though move_uploaded_file succeeds.
			if ( ! move_uploaded_file( $tmp_src, $dest ) ) {
				if ( is_readable( $tmp_src ) && @copy( $tmp_src, $dest ) ) {
					++$copy_fallback_used;
					$via_copy = true;
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $tmp_src );
				} else {
					$this->eldbc_log_media_staging(
						sprintf( 'index=%d FAIL move+copy relative=%s tmp=%s', $i, $relative, $tmp_src )
					);
					$this->delete_directory_recursive( $tmp );
					return new WP_Error( 'eldbc_stage', __( 'Could not move uploaded media file.', 'extended-learndash-bulk-create' ) );
				}
			}
			++$staged_ok;
			$this->eldbc_log_media_staging(
				sprintf(
					'staged index=%d relative=%s dest=%s%s',
					$i,
					$relative,
					wp_normalize_path( $dest ),
					$via_copy ? ' via=copy_fallback' : ''
				)
			);
		}

		$this->eldbc_log_media_staging(
			sprintf(
				'Summary: staged_ok=%d skipped_empty_name=%d skipped_upload_error=%d skipped_empty_tmp=%d copy_fallback_used=%d (if skipped_upload_error > 0 and count near max_file_uploads, suspect PHP max_file_uploads)',
				$staged_ok,
				$skipped_empty_name,
				$skipped_upload_err,
				$skipped_empty_tmp,
				$copy_fallback_used
			)
		);

		return $tmp;
	}

	/**
	 * @param string $dir Absolute path.
	 */
	private function delete_directory_recursive( $dir ) {
		$dir = trailingslashit( wp_normalize_path( $dir ) );
		$td  = trailingslashit( wp_normalize_path( get_temp_dir() ) );
		if ( strpos( $dir, $td ) !== 0 ) {
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . $item;
			if ( is_dir( $path ) ) {
				$this->delete_directory_recursive( trailingslashit( $path ) );
			} elseif ( is_file( $path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $path );
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( rtrim( $dir, '/' ) );
	}

	/**
	 * @param string               $content_type Post type.
	 * @param array<string,string> $post_data Row.
	 * @return int|string Post ID or error message string.
	 */
	private function create_content( $content_type, $post_data ) {
		if ( ! in_array( $content_type, $this->supported_post_types, true ) ) {
			return sprintf(
				/* translators: %s: content type */
				__( 'Invalid content type: %s', 'extended-learndash-bulk-create' ),
				esc_html( $content_type )
			);
		}

		$post_args = array(
			'post_title'   => sanitize_text_field( $post_data['post_title'] ),
			'post_content' => wp_kses_post( isset( $post_data['post_content'] ) ? $post_data['post_content'] : '' ),
			'post_type'    => $content_type,
			'post_status'  => 'publish',
		);

		$post_id = wp_insert_post( $post_args, true );

		if ( is_wp_error( $post_id ) ) {
			return sprintf(
				/* translators: 1: content type, 2: error message */
				__( 'Error creating %1$s: %2$s', 'extended-learndash-bulk-create' ),
				$content_type,
				$post_id->get_error_message()
			);
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return sprintf(
				/* translators: %s: content type */
				__( 'Error creating %s: invalid post ID.', 'extended-learndash-bulk-create' ),
				esc_html( $content_type )
			);
		}

		$this->update_associations( $post_id, $content_type, $post_data );
		$this->update_custom_fields( $post_id, $post_data );

		return $post_id;
	}

	/**
	 * @param int                  $post_id Post ID.
	 * @param string               $content_type Post type.
	 * @param array<string,string> $post_data Row.
	 * @return int|string Post ID or error message.
	 */
	private function update_content( $post_id, $content_type, $post_data ) {
		$post_args = array(
			'ID'           => $post_id,
			'post_title'   => sanitize_text_field( $post_data['post_title'] ),
			'post_content' => wp_kses_post( isset( $post_data['post_content'] ) ? $post_data['post_content'] : '' ),
		);
		$result    = wp_update_post( $post_args, true );
		if ( is_wp_error( $result ) ) {
			return sprintf(
				/* translators: %s: error */
				__( 'Error updating post: %s', 'extended-learndash-bulk-create' ),
				$result->get_error_message()
			);
		}
		$this->update_associations( $post_id, $content_type, $post_data );
		$this->update_custom_fields( $post_id, $post_data );
		$this->sync_learndash_associations_from_meta( $post_id );
		return (int) $result;
	}

	/**
	 * Infer course ID from a lesson/topic step when CSV omits course_id.
	 *
	 * @param int $parent_step_id Lesson or topic post ID.
	 * @return int Course ID or 0.
	 */
	private function infer_course_id_from_parent_step( $parent_step_id ) {
		$parent_step_id = absint( $parent_step_id );
		if ( ! $parent_step_id || ! function_exists( 'learndash_get_course_id' ) ) {
			return 0;
		}
		$cid = learndash_get_course_id( $parent_step_id );
		return $cid ? absint( $cid ) : 0;
	}

	/**
	 * Attach a lesson, topic, or quiz to the course outline (ld_course_steps) after settings are stored.
	 *
	 * @param int    $post_id    Step post ID.
	 * @param string $post_type  Post type slug.
	 * @param int    $course_id  Course ID.
	 * @param int    $lesson_ref Optional. Parent lesson ID, topic ID (for quizzes), or lesson ID for topics.
	 */
	private function link_step_to_course_outline( $post_id, $post_type, $course_id, $lesson_ref = 0 ) {
		if ( ! function_exists( 'learndash_course_add_child_to_parent' ) ) {
			return;
		}
		$post_id    = absint( $post_id );
		$course_id  = absint( $course_id );
		$lesson_ref = absint( $lesson_ref );
		if ( ! $post_id || ! $course_id ) {
			return;
		}

		if ( 'sfwd-lessons' === $post_type ) {
			learndash_course_add_child_to_parent( $course_id, $post_id, $course_id );
			return;
		}

		if ( 'sfwd-topic' === $post_type ) {
			if ( $lesson_ref ) {
				learndash_course_add_child_to_parent( $course_id, $post_id, $lesson_ref );
			}
			return;
		}

		if ( 'sfwd-quiz' === $post_type ) {
			if ( ! $lesson_ref ) {
				learndash_course_add_child_to_parent( $course_id, $post_id, $course_id );
				return;
			}
			$parent_type = get_post_type( $lesson_ref );
			if ( in_array( $parent_type, array( 'sfwd-lessons', 'sfwd-topic' ), true ) ) {
				learndash_course_add_child_to_parent( $course_id, $post_id, $lesson_ref );
			}
		}
	}

	/**
	 * After course_id / lesson_id meta changes, re-sync LearnDash settings and course outline.
	 *
	 * @param int $post_id Step post ID.
	 */
	private function sync_learndash_associations_from_meta( $post_id ) {
		if ( ! function_exists( 'learndash_update_setting' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$course_id  = absint( get_post_meta( $post_id, 'course_id', true ) );
		$lesson_ref = absint( get_post_meta( $post_id, 'lesson_id', true ) );

		if ( in_array( $post->post_type, array( 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz' ), true ) ) {
			if ( $course_id ) {
				learndash_update_setting( $post_id, 'course', $course_id );
			} else {
				learndash_update_setting( $post_id, 'course', 0 );
			}
		}

		if ( in_array( $post->post_type, array( 'sfwd-topic', 'sfwd-quiz' ), true ) ) {
			if ( $lesson_ref ) {
				learndash_update_setting( $post_id, 'lesson', $lesson_ref );
			} else {
				learndash_update_setting( $post_id, 'lesson', 0 );
			}
		}

		if ( $course_id ) {
			$this->link_step_to_course_outline( $post_id, $post->post_type, $course_id, $lesson_ref );
		}
	}

	/**
	 * @param int                  $post_id Post ID.
	 * @param string               $content_type Post type.
	 * @param array<string,string> $post_data Row.
	 */
	private function update_associations( $post_id, $content_type, $post_data ) {
		if ( ! function_exists( 'learndash_update_setting' ) ) {
			$this->update_associations_legacy_meta_only( $post_id, $content_type, $post_data );
			return;
		}

		switch ( $content_type ) {
			case 'sfwd-lessons':
				if ( ! empty( $post_data['course_id'] ) ) {
					$course_id = absint( $post_data['course_id'] );
					learndash_update_setting( $post_id, 'course', $course_id );
					$this->link_step_to_course_outline( $post_id, 'sfwd-lessons', $course_id, 0 );
				}
				break;

			case 'sfwd-topic':
				$lesson_id = ! empty( $post_data['lesson_id'] ) ? absint( $post_data['lesson_id'] ) : 0;
				$course_id = ! empty( $post_data['course_id'] ) ? absint( $post_data['course_id'] ) : 0;
				if ( ! $course_id && $lesson_id ) {
					$course_id = $this->infer_course_id_from_parent_step( $lesson_id );
				}
				if ( $course_id ) {
					learndash_update_setting( $post_id, 'course', $course_id );
				}
				if ( $lesson_id ) {
					learndash_update_setting( $post_id, 'lesson', $lesson_id );
				}
				if ( $course_id && $lesson_id ) {
					$this->link_step_to_course_outline( $post_id, 'sfwd-topic', $course_id, $lesson_id );
				}
				break;

			case 'sfwd-quiz':
				$lesson_ref = ! empty( $post_data['lesson_id'] ) ? absint( $post_data['lesson_id'] ) : 0;
				$course_id  = ! empty( $post_data['course_id'] ) ? absint( $post_data['course_id'] ) : 0;
				if ( ! $course_id && $lesson_ref ) {
					$course_id = $this->infer_course_id_from_parent_step( $lesson_ref );
				}
				if ( $course_id ) {
					learndash_update_setting( $post_id, 'course', $course_id );
				}
				if ( $lesson_ref ) {
					learndash_update_setting( $post_id, 'lesson', $lesson_ref );
				}
				if ( $course_id ) {
					$this->link_step_to_course_outline( $post_id, 'sfwd-quiz', $course_id, $lesson_ref );
				}
				break;

			case 'sfwd-question':
				if ( ! empty( $post_data['quiz_id'] ) ) {
					update_post_meta( $post_id, 'quiz_id', absint( $post_data['quiz_id'] ) );
				}
				break;
		}
	}

	/**
	 * Fallback when LearnDash APIs are unavailable (should not occur if sfwd-lms is active).
	 *
	 * @param int                  $post_id Post ID.
	 * @param string               $content_type Post type.
	 * @param array<string,string> $post_data Row.
	 */
	private function update_associations_legacy_meta_only( $post_id, $content_type, $post_data ) {
		switch ( $content_type ) {
			case 'sfwd-lessons':
				if ( ! empty( $post_data['course_id'] ) ) {
					update_post_meta( $post_id, 'course_id', absint( $post_data['course_id'] ) );
				}
				break;
			case 'sfwd-topic':
				if ( ! empty( $post_data['course_id'] ) ) {
					update_post_meta( $post_id, 'course_id', absint( $post_data['course_id'] ) );
				}
				if ( ! empty( $post_data['lesson_id'] ) ) {
					update_post_meta( $post_id, 'lesson_id', absint( $post_data['lesson_id'] ) );
				}
				break;
			case 'sfwd-quiz':
				if ( ! empty( $post_data['course_id'] ) ) {
					update_post_meta( $post_id, 'course_id', absint( $post_data['course_id'] ) );
				}
				if ( ! empty( $post_data['lesson_id'] ) ) {
					update_post_meta( $post_id, 'lesson_id', absint( $post_data['lesson_id'] ) );
				}
				break;
			case 'sfwd-question':
				if ( ! empty( $post_data['quiz_id'] ) ) {
					update_post_meta( $post_id, 'quiz_id', absint( $post_data['quiz_id'] ) );
				}
				break;
		}
	}

	/**
	 * @param int                  $post_id Post ID.
	 * @param array<string,string> $post_data Row.
	 */
	private function update_custom_fields( $post_id, $post_data ) {
		foreach ( $post_data as $key => $value ) {
			if ( ! in_array( $key, array( 'post_title', 'post_content', 'course_id', 'lesson_id', 'quiz_id' ), true ) && ! empty( $value ) ) {
				update_post_meta( $post_id, sanitize_key( $key ), sanitize_text_field( $value ) );
			}
		}
	}

	/**
	 * @param array $stats Import statistics.
	 */
	private function display_import_notice_from_transient( $stats ) {
		$this->bulk_notice_html   = $this->build_import_notice_message( $stats );
		$this->bulk_notice_type = ! empty( $stats['errors'] ) ? 'error' : 'success';
	}

	/**
	 * @param array $stats Import statistics.
	 * @return string
	 */
	private function build_import_notice_message( $stats ) {
		$created = isset( $stats['created'] ) ? (int) $stats['created'] : 0;
		$updated = isset( $stats['updated'] ) ? (int) $stats['updated'] : 0;
		$skipped = isset( $stats['skipped'] ) ? (int) $stats['skipped'] : 0;
		$errors  = isset( $stats['errors'] ) && is_array( $stats['errors'] ) ? $stats['errors'] : array();

		$summary = sprintf(
			/* translators: 1: created count, 2: updated, 3: skipped */
			__( 'Import finished: %1$d created, %2$d updated, %3$d skipped (already exist).', 'extended-learndash-bulk-create' ),
			$created,
			$updated,
			$skipped
		);
		$message = '<p>' . esc_html( $summary ) . '</p>';

		$lists = array(
			'created_entries' => __( 'Created:', 'extended-learndash-bulk-create' ),
			'updated_entries' => __( 'Updated:', 'extended-learndash-bulk-create' ),
			'skipped_entries' => __( 'Skipped:', 'extended-learndash-bulk-create' ),
		);
		foreach ( $lists as $key => $heading ) {
			if ( empty( $stats[ $key ] ) || ! is_array( $stats[ $key ] ) ) {
				continue;
			}
			$message .= '<p><strong>' . esc_html( $heading ) . '</strong></p>';
			$message .= '<ul style="list-style:disc;margin-left:1.5em;">';
			foreach ( $stats[ $key ] as $entry ) {
				if ( empty( $entry['id'] ) ) {
					continue;
				}
				$pid   = (int) $entry['id'];
				$title = isset( $entry['title'] ) ? $entry['title'] : '';
				if ( $title === '' ) {
					$title = sprintf(
						/* translators: %d: post ID */
						__( 'Post %d', 'extended-learndash-bulk-create' ),
						$pid
					);
				}
				$edit_link = get_edit_post_link( $pid, 'raw' );
				if ( $edit_link ) {
					$message .= '<li><a href="' . esc_url( $edit_link ) . '">' . esc_html( $title ) . '</a></li>';
				} else {
					$message .= '<li>' . esc_html( $title ) . '</li>';
				}
			}
			$message .= '</ul>';
		}

		if ( ! empty( $errors ) ) {
			$message .= '<p><strong>' . esc_html( sprintf(
				/* translators: %d: error count */
				_n( '%d error:', '%d errors:', count( $errors ), 'extended-learndash-bulk-create' ),
				count( $errors )
			) ) . '</strong></p>';
			$message .= '<ul style="list-style:disc;margin-left:1.5em;">';
			foreach ( $errors as $error ) {
				$message .= '<li>' . esc_html( $error ) . '</li>';
			}
			$message .= '</ul>';
		}

		return $message;
	}
}

// Activation hook
function extended_learndash_bulk_create_activate() {
	// Check if LearnDash is active
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( 'sfwd-lms/sfwd_lms.php' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'Please install and activate LearnDash before activating this plugin.', 'extended-learndash-bulk-create' ), 'Plugin dependency check', array( 'back_link' => true ) );
	}

	// Create backup directory (legacy; kept for existing installs)
	$backup_dir = plugin_dir_path( __FILE__ ) . 'backups';
	if ( ! file_exists( $backup_dir ) ) {
		mkdir( $backup_dir, 0755, true );
	}
}
register_activation_hook( __FILE__, 'extended_learndash_bulk_create_activate' );

// Initialize the plugin
$extended_learndash_bulk_create = new Extended_LearnDash_Bulk_Create();

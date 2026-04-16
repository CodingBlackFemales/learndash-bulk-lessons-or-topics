<?php
/**
 * Media upload with SHA-256 deduplication for CSV imports.
 *
 * @package Extended_LearnDash_Bulk_Create
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ELDBC_Media {

	const HASH_META = '_eldbc_file_hash';

	/**
	 * Replace paths like media/foo.png in HTML with attachment URLs.
	 *
	 * @param string   $html     post_content HTML.
	 * @param string   $media_dir Absolute path to folder containing a `media` subdirectory (or files rooted as in the export).
	 * @param string[] $errors    Collect non-fatal issues (missing files, upload failures).
	 * @return string
	 */
	public static function rewrite_paths( $html, $media_dir, array &$errors ) {
		if ( $html === '' || $media_dir === '' || ! is_dir( $media_dir ) ) {
			return $html;
		}

		$paths = self::extract_media_paths( $html );
		if ( empty( $paths ) ) {
			return $html;
		}

		$map = array();
		foreach ( $paths as $rel ) {
			$rel_norm = str_replace( '\\', '/', $rel );
			$full     = self::resolve_under_media_dir( $media_dir, $rel_norm );
			if ( ! $full || ! is_readable( $full ) ) {
				$errors[] = sprintf(
					/* translators: %s: relative path */
					__( 'Missing media file: %s', 'extended-learndash-bulk-create' ),
					$rel_norm
				);
				continue;
			}

			$url = self::get_or_upload_url( $full, basename( $rel_norm ) );
			if ( is_wp_error( $url ) ) {
				$errors[] = $url->get_error_message();
				continue;
			}
			$map[ $rel_norm ] = $url;
		}

		return self::apply_map( $html, $map );
	}

	/**
	 * @return string[]
	 */
	private static function extract_media_paths( $html ) {
		$found = array();
		if ( preg_match_all( '#\bmedia/([a-zA-Z0-9_./\-]+)#i', $html, $matches ) ) {
			foreach ( $matches[0] as $raw ) {
				$found[ $raw ] = true;
			}
		}
		return array_keys( $found );
	}

	/**
	 * Resolve media/foo.bar relative to upload root or nested folders.
	 *
	 * @param string $media_dir Root directory user provided (export folder).
	 * @param string $rel       e.g. media/slide_1.png.
	 * @return string|null Absolute path or null.
	 */
	private static function resolve_under_media_dir( $media_dir, $rel ) {
		$base = rtrim( wp_normalize_path( $media_dir ), '/' );
		$rel  = ltrim( str_replace( '\\', '/', $rel ), '/' );
		if ( $base === '' ) {
			return null;
		}

		$without_prefix = preg_replace( '#^media/#i', '', $rel );
		$basename       = basename( $rel );

		$candidates = array(
			$base . '/' . $rel,
			$base . '/' . $without_prefix,
			$base . '/media/' . $without_prefix,
		);

		foreach ( $candidates as $path ) {
			$path = wp_normalize_path( $path );
			if ( file_exists( $path ) && is_file( $path ) ) {
				return $path;
			}
		}

		// Case-only mismatch on disk vs HTML (e.g. .PNG vs .png) or mixed-case folder names.
		foreach ( array( $base . '/media', $base ) as $dir ) {
			$found = self::find_file_in_dir_case_insensitive( $dir, $basename );
			if ( $found ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Find a file in a directory matching basename case-insensitively.
	 *
	 * @param string $dir      Absolute directory path.
	 * @param string $basename Expected filename (e.g. slide_003_img_01.png).
	 * @return string|null Normalized file path.
	 */
	private static function find_file_in_dir_case_insensitive( $dir, $basename ) {
		$dir = wp_normalize_path( $dir );
		if ( $dir === '' || ! is_dir( $dir ) || $basename === '' ) {
			return null;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return null;
		}
		foreach ( $items as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			if ( strcasecmp( $f, $basename ) === 0 ) {
				$candidate = wp_normalize_path( trailingslashit( $dir ) . $f );
				if ( is_file( $candidate ) && is_readable( $candidate ) ) {
					return $candidate;
				}
			}
		}
		return null;
	}

	/**
	 * @param string $abs_path Absolute path to file.
	 * @param string $file_name Preferred filename for the attachment.
	 * @return string|WP_Error Attachment URL or error.
	 */
	private static function get_or_upload_url( $abs_path, $file_name ) {
		if ( ! is_readable( $abs_path ) ) {
			return new WP_Error( 'eldbc_unreadable', __( 'Media file is not readable.', 'extended-learndash-bulk-create' ) );
		}

		$hash = hash_file( 'sha256', $abs_path );
		if ( ! $hash ) {
			return new WP_Error( 'eldbc_hash', __( 'Could not hash media file.', 'extended-learndash-bulk-create' ) );
		}

		$existing_id = self::find_attachment_by_hash( $hash );
		if ( $existing_id ) {
			$url = wp_get_attachment_url( $existing_id );
			$url = $url ? self::url_for_current_site_home( $url ) : '';
			return $url ? $url : new WP_Error( 'eldbc_url', __( 'Could not resolve attachment URL.', 'extended-learndash-bulk-create' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filetype = wp_check_filetype( $file_name, null );
		if ( empty( $filetype['type'] ) ) {
			$filetype['type'] = 'application/octet-stream';
		}

		$bits = file_get_contents( $abs_path );
		if ( false === $bits ) {
			return new WP_Error( 'eldbc_read', __( 'Could not read media file for upload.', 'extended-learndash-bulk-create' ) );
		}

		$upload = wp_upload_bits( $file_name, null, $bits );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'eldbc_upload_bits', $upload['error'] );
		}

		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( pathinfo( $file_name, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		update_post_meta( (int) $attach_id, self::HASH_META, $hash );

		$meta = wp_generate_attachment_metadata( (int) $attach_id, $upload['file'] );
		wp_update_attachment_metadata( (int) $attach_id, $meta );

		$url = wp_get_attachment_url( (int) $attach_id );
		$url = $url ? self::url_for_current_site_home( $url ) : '';
		return $url ? $url : new WP_Error( 'eldbc_url', __( 'Could not resolve new attachment URL.', 'extended-learndash-bulk-create' ) );
	}

	/**
	 * Rebuild an absolute URL using home_url() so the host matches this site (subsite) in multisite.
	 *
	 * wp_get_attachment_url() can return the network/main domain when upload baseurl and WP_HOME diverge
	 * (e.g. cms.* vs academy.* on Lando).
	 *
	 * @param string $url Full URL, typically from wp_get_attachment_url().
	 * @return string
	 */
	private static function url_for_current_site_home( $url ) {
		if ( $url === '' || ! is_string( $url ) ) {
			return $url;
		}
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['path'] ) ) {
			return $url;
		}
		$out = home_url( $parsed['path'] );
		if ( ! empty( $parsed['query'] ) ) {
			$out .= '?' . $parsed['query'];
		}
		if ( ! empty( $parsed['fragment'] ) ) {
			$out .= '#' . $parsed['fragment'];
		}
		return $out;
	}

	/**
	 * @param string $hash SHA-256 hex.
	 * @return int Attachment ID or 0.
	 */
	private static function find_attachment_by_hash( $hash ) {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => self::HASH_META,
						'value' => $hash,
					),
				),
			)
		);
		if ( ! empty( $q->posts[0] ) ) {
			return (int) $q->posts[0];
		}
		return 0;
	}

	/**
	 * @param string          $html Original HTML.
	 * @param array<string,string> $map Relative path => URL.
	 * @return string
	 */
	private static function apply_map( $html, array $map ) {
		if ( empty( $map ) ) {
			return $html;
		}
		foreach ( $map as $rel => $url ) {
			$html = str_replace( $rel, $url, $html );
		}
		return $html;
	}
}

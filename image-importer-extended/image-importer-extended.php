<?php
/**
 * Plugin Name: Image Importer Extended
 * Description: Imports the first image in a post received through the REST API and assigns it as the featured image.
 * Version: 9.3.0
 * Update URI: https://github.com/boxa007/georgian_30lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

class Image_Importer_Extended {
	const VERSION      = '9.3.0';
	const MAX_WIDTH    = 500;   // featured images are stored on the site at most 500 px wide
	const JPEG_QUALITY = 60;
	const REPO         = 'boxa007/georgian_30lang';
	const TAG_PREFIX   = 'iie-v';
	const BATCH_SECONDS = 30;

	private $running = array();

	public function __construct() {
		/* Self-update from GitHub releases (tags iie-vX.Y.Z) and opt into automatic updates. */
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'auto_update_plugin', array( $this, 'auto_update' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'keep_folder_name' ), 10, 4 );
		/* One-time background job: shrink featured images that were imported before 9.3.0. */
		add_filter( 'cron_schedules', array( $this, 'cron_interval' ) );
		add_action( 'iie_shrink_batch', array( $this, 'shrink_batch' ) );
		add_action( 'init', array( $this, 'schedule_shrink' ) );

		/* n8n creates articles through POST /wp-json/wp/v2/posts. */
		add_action( 'rest_after_insert_post', array( $this, 'import_for_rest_post' ), 999, 3 );

		/* Some themes render only excerpts on archive/grid pages. */
		add_filter( 'the_excerpt', array( $this, 'prepend_featured_thumbnail' ), 9 );
		/*
		 * The active theme already prints the featured image at the top of a post.
		 * n8n also sends that image as the first content block, so remove only this
		 * redundant leading image on the front end.
		 */
		add_filter( 'the_content', array( $this, 'remove_redundant_lead_image' ), 5 );
		add_action( 'wp_head', array( $this, 'thumbnail_styles' ) );
	}

	public function prepend_featured_thumbnail( $excerpt ) {
		if ( is_admin() || is_singular() ) {
			return $excerpt;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || ! has_post_thumbnail( $post_id ) ) {
			return $excerpt;
		}

		$image = get_the_post_thumbnail(
			$post_id,
			'large',
			array(
				'class'   => 'image-importer-card-thumbnail',
				'loading' => 'lazy',
			)
		);

		return $image ? '<div class="image-importer-card-thumbnail-wrap">' . $image . '</div>' . $excerpt : $excerpt;
	}

	public function thumbnail_styles() {
		if ( is_admin() ) {
			return;
		}

		echo '<style>.image-importer-card-thumbnail-wrap{margin:0 0 18px}.image-importer-card-thumbnail{display:block;width:100%;height:auto;max-height:420px;object-fit:cover}</style>';
	}

	public function remove_redundant_lead_image( $content ) {
		if ( is_admin() || ! is_singular( 'post' ) || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || ! has_post_thumbnail( $post_id ) ) {
			return $content;
		}

		/* Remove just one image at the beginning; later article illustrations stay intact. */
		return preg_replace(
			'~^\s*(?:<p[^>]*>\s*)?<img\b[^>]*>\s*(?:</p>)?~i',
			'',
			$content,
			1
		);
	}

	public function import_for_rest_post( $post, $request, $creating ) {
		if ( ! $post || 'post' !== $post->post_type ) {
			return;
		}

		$this->import_first_image( $post->ID );
	}

	private function import_first_image( $post_id ) {
		if ( isset( $this->running[ $post_id ] ) || has_post_thumbnail( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( ! preg_match( '~<img[^>]+src=["\']([^"\']+)["\']~i', $post->post_content, $match ) ) {
			error_log( 'ImageImporter: no img src found for post ' . $post_id );
			return;
		}

		$url = html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			error_log( 'ImageImporter: invalid image URL for post ' . $post_id . ': ' . $url );
			return;
		}

		$this->running[ $post_id ] = true;

		try {
			$attachment_id = attachment_url_to_postid( strtok( $url, '?' ) );

			/* The same cover is sent for both posts of a story: reuse the copy already imported. */
			if ( ! $attachment_id ) {
				$attachment_id = $this->find_attachment_by_basename( $url );
			}

			if ( ! $attachment_id ) {
				$attachment_id = $this->download_as_attachment( $url, $post_id );
			}

			if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
				$message = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'No attachment ID returned';
				error_log( 'ImageImporter: download failed for post ' . $post_id . ': ' . $message );
				return;
			}

			$attachment_id = (int) $attachment_id;
			set_post_thumbnail( $post_id, $attachment_id );

			/* _thumbnail_id is the actual WordPress featured-image field. */
			if ( (int) get_post_thumbnail_id( $post_id ) !== $attachment_id ) {
				update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
			}

			if ( (int) get_post_thumbnail_id( $post_id ) === $attachment_id ) {
				/* The theme outputs the featured image itself. Remove n8n's duplicate lead tag. */
				$clean_content = preg_replace(
					'~^\s*(?:<p[^>]*>\s*)?<img\b[^>]*src=["\']' . preg_quote( $match[1], '~' ) . '["\'][^>]*>\s*(?:</p>)?~i',
					'',
					$post->post_content,
					1
				);

				if ( null !== $clean_content && $clean_content !== $post->post_content ) {
					wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => $clean_content,
						)
					);
				}

				error_log( 'ImageImporter: featured image set for post ' . $post_id . ', attachment ' . $attachment_id );
			} else {
				error_log( 'ImageImporter: failed to store _thumbnail_id for post ' . $post_id );
			}
		} finally {
			unset( $this->running[ $post_id ] );
		}
	}

	private function download_as_attachment( $url, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp_file = download_url( $url, 90 );
		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}
		$this->shrink_file( $tmp_file );

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$name = sanitize_file_name( $path ? wp_basename( $path ) : 'featured-image.jpg' );
		if ( ! pathinfo( $name, PATHINFO_EXTENSION ) ) {
			$name .= '.jpg';
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp_file,
			),
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp_file );
		}

		return $attachment_id;
	}

	/** Resize to MAX_WIDTH (keeps aspect) and recompress JPEGs at JPEG_QUALITY, in place. */
	private function shrink_file( $path ) {
		$editor = wp_get_image_editor( $path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}
		$size = $editor->get_size();
		if ( empty( $size['width'] ) ) {
			return false;
		}
		if ( $size['width'] > self::MAX_WIDTH ) {
			$editor->resize( self::MAX_WIDTH, null, false );
		}
		$editor->set_quality( self::JPEG_QUALITY );
		/* Keep the real format (the downloaded temp file has no meaningful extension). */
		$real   = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $path ) : '';
		$exts   = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' );
		$ext    = isset( $exts[ $real ] ) ? $exts[ $real ] : 'jpg';
		$before = (int) @filesize( $path );
		$saved  = $editor->save( $path . '.iie.' . $ext, $real ? $real : null );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return false;
		}
		if ( (int) filesize( $saved['path'] ) < $before || $size['width'] > self::MAX_WIDTH ) {
			@rename( $saved['path'], $path );
			return true;
		}
		@unlink( $saved['path'] );
		return false;
	}

	private function find_attachment_by_basename( $url ) {
		global $wpdb;
		$base = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( '' === $base ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY meta_id LIMIT 1",
			'%/' . $wpdb->esc_like( $base )
		) );
	}

	/* ---------- one-time shrink of older featured images ---------- */

	public function cron_interval( $s ) {
		$s['iie_every_minute'] = array( 'interval' => 60, 'display' => 'Every minute (Image Importer shrink)' );
		return $s;
	}

	public function schedule_shrink() {
		$done = get_option( 'iie_shrink_done' );
		if ( $done ) {
			if ( wp_next_scheduled( 'iie_shrink_batch' ) ) {
				wp_clear_scheduled_hook( 'iie_shrink_batch' );
			}
			return;
		}
		if ( ! wp_next_scheduled( 'iie_shrink_batch' ) ) {
			wp_schedule_event( time() + 60, 'iie_every_minute', 'iie_shrink_batch' );
		}
	}

	public function shrink_batch() {
		global $wpdb;
		if ( get_transient( 'iie_shrink_lock' ) ) {
			return;
		}
		set_transient( 'iie_shrink_lock', 1, 90 );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$start = microtime( true );
		$nc    = (int) wp_rand( 1, 2000000000 ); // unique query text: bypass database query caches
		$ids   = $wpdb->get_col(
			"SELECT DISTINCT t.meta_value FROM {$wpdb->postmeta} t
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = t.meta_value AND m.meta_key = '_iie_small'
			 WHERE t.meta_key = '_thumbnail_id' AND t.meta_value > 0 AND m.meta_id IS NULL AND {$nc} = {$nc}
			 LIMIT 200"
		);
		if ( empty( $ids ) ) {
			update_option( 'iie_shrink_done', gmdate( 'c' ), false );
			wp_clear_scheduled_hook( 'iie_shrink_batch' );
			delete_transient( 'iie_shrink_lock' );
			return;
		}
		$stats = get_option( 'iie_shrink_stats', array( 'files' => 0, 'before' => 0, 'after' => 0 ) );
		foreach ( $ids as $id ) {
			if ( microtime( true ) - $start > self::BATCH_SECONDS ) {
				break;
			}
			$id = (int) $id;
			update_post_meta( $id, '_iie_small', 'try' ); // a crash on one image never blocks the queue
			$file = get_attached_file( $id );
			$mime = get_post_mime_type( $id );
			if ( $file && file_exists( $file ) && in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				$before = (int) filesize( $file );
				if ( $this->shrink_file( $file ) ) {
					clearstatcache( true, $file );
					$stats['files']++;
					$stats['before'] += $before;
					$stats['after']  += (int) filesize( $file );
					$meta = wp_get_attachment_metadata( $id );
					$dim  = @getimagesize( $file );
					if ( is_array( $meta ) && $dim ) {
						$meta['width']    = (int) $dim[0];
						$meta['height']   = (int) $dim[1];
						$meta['filesize'] = (int) filesize( $file );
						/* Drop resized copies that are now larger than the original. */
						if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
							foreach ( $meta['sizes'] as $k => $s ) {
								if ( isset( $s['width'] ) && (int) $s['width'] > (int) $dim[0] ) {
									@unlink( dirname( $file ) . '/' . basename( $s['file'] ) );
									unset( $meta['sizes'][ $k ] );
								}
							}
						}
						wp_update_attachment_metadata( $id, $meta );
					}
				}
			}
			update_post_meta( $id, '_iie_small', 1 );
		}
		update_option( 'iie_shrink_stats', $stats, false );
		delete_transient( 'iie_shrink_lock' );
	}

	/* ---------- GitHub updater ---------- */

	private function latest_release() {
		$cached = get_site_transient( 'iie_github_release' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases?per_page=30', array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'Image-Importer-Extended/' . self::VERSION ),
		) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}
		$best = false;
		foreach ( (array) json_decode( wp_remote_retrieve_body( $res ), true ) as $rel ) {
			if ( empty( $rel['tag_name'] ) || 0 !== strpos( $rel['tag_name'], self::TAG_PREFIX ) || ! empty( $rel['draft'] ) ) {
				continue;
			}
			$version = substr( $rel['tag_name'], strlen( self::TAG_PREFIX ) );
			foreach ( (array) $rel['assets'] as $asset ) {
				if ( ! empty( $asset['name'] ) && preg_match( '/^image-importer-extended-.*\.zip$/', $asset['name'] ) && ( ! $best || version_compare( $version, $best['version'], '>' ) ) ) {
					$best = array( 'version' => $version, 'url' => $rel['html_url'], 'package' => $asset['browser_download_url'] );
				}
			}
		}
		set_site_transient( 'iie_github_release', $best ? $best : array( 'version' => '0' ), 6 * HOUR_IN_SECONDS );
		return $best;
	}

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}
		$file = plugin_basename( __FILE__ );
		if ( ! isset( $transient->checked[ $file ] ) ) {
			return $transient;
		}
		$rel = $this->latest_release();
		if ( ! $rel || empty( $rel['package'] ) ) {
			return $transient;
		}
		$item = (object) array(
			'slug'        => dirname( $file ),
			'plugin'      => $file,
			'new_version' => $rel['version'],
			'url'         => $rel['url'],
			'package'     => $rel['package'],
		);
		if ( version_compare( $rel['version'], $transient->checked[ $file ], '>' ) ) {
			$transient->response[ $file ] = $item;
		} else {
			$item->{'update-supported'} = true;
			$transient->no_update[ $file ] = $item;
		}
		return $transient;
	}

	public function auto_update( $update, $item ) {
		return ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) ? true : $update;
	}

	/** Install the update into the folder this site already uses (e.g. image-importer-extended-v9.2.0). */
	public function keep_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( __FILE__ ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		global $wp_filesystem;
		$wanted = trailingslashit( $remote_source ) . dirname( plugin_basename( __FILE__ ) ) . '/';
		if ( trailingslashit( $source ) === $wanted || ! $wp_filesystem ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
	}
}

new Image_Importer_Extended();

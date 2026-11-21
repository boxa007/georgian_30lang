<?php
/**
 * Plugin Name: Image Importer Extended
 * Description: Imports the first image in a post received through the REST API and assigns it as the featured image.
 * Version: 9.3.4
 * Update URI: https://github.com/boxa007/georgian_30lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

class Image_Importer_Extended {
	const VERSION      = '9.3.4';
	const REPORT_URL   = 'https://kuts.air2.top/webhook/plugin-report';
	const PER_BATCH    = 50;    // old covers taken per run, in order; one run every 2 minutes
	const MAX_WIDTH    = 500;   // featured images are stored on the site at most 500 px wide
	const JPEG_QUALITY = 60;
	const REPO         = 'boxa007/georgian_30lang';
	const TAG_PREFIX   = 'iie-v';
	const BATCH_SECONDS = 20;

	private $running = array();

	public function __construct() {
		/* Self-update from GitHub releases (tags iie-vX.Y.Z) and opt into automatic updates. */
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'auto_update_plugin', array( $this, 'auto_update' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'keep_folder_name' ), 10, 4 );
		/* "Update from GitHub" link in the plugin list: install the newest release right now. */
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_update_link' ) );
		add_action( 'admin_post_iie_update_from_github', array( $this, 'update_from_github' ) );
		add_action( 'admin_notices', array( $this, 'render_update_notice' ) );
		/* One-time background job: shrink featured images that were imported before 9.3.0. */
		add_filter( 'cron_schedules', array( $this, 'cron_interval' ) );
		add_action( 'iie_shrink_batch', array( $this, 'shrink_batch' ) );
		add_action( 'init', array( $this, 'schedule_shrink' ) );
		add_action( 'admin_menu', array( $this, 'add_status_page' ) );
		/* Reports to the central table: once per installed version, daily while shrinking runs, once when done. */
		add_action( 'init', array( $this, 'maybe_report' ), 20 );
		add_action( 'iie_daily_report', array( $this, 'daily_report' ) );

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

	/**
	 * Server load guard: skip a batch when the 1-minute load average is above
	 * 70% of CPU cores. Unknown load or core count never blocks the job.
	 */
	private function server_busy() {
		$info = $this->load_info();
		return $info['busy'];
	}

	/**
	 * Load average vs. CPU cores. Hosting often hides /proc (open_basedir); then the
	 * core count is unknown and only a clearly overloaded server (load > 8) pauses work.
	 */
	private function load_info() {
		$load = function_exists( 'sys_getloadavg' ) ? @sys_getloadavg() : false;
		$load = ( is_array( $load ) && isset( $load[0] ) ) ? (float) $load[0] : null;
		$cores = get_transient( 'img_guard_cores2' );
		if ( false === $cores ) {
			$cores = 0;
			$info  = @file_get_contents( '/proc/cpuinfo' );
			if ( is_string( $info ) && '' !== $info ) {
				$cores = substr_count( $info, "\nprocessor" ) + ( 0 === strpos( $info, 'processor' ) ? 1 : 0 );
			}
			set_transient( 'img_guard_cores2', (int) $cores, DAY_IN_SECONDS );
		}
		$cores     = (int) $cores;
		$threshold = $cores > 0 ? 0.7 * $cores : 8.0;
		return array(
			'load'      => $load,
			'cores'     => $cores,
			'threshold' => $threshold,
			'busy'      => null !== $load && $load > $threshold,
		);
	}

	private function shrink_counts() {
		global $wpdb;
		$nc    = (int) wp_rand( 1, 2000000000 );
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value > 0 AND {$nc} = {$nc}" );
		$done  = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT t.meta_value) FROM {$wpdb->postmeta} t JOIN {$wpdb->postmeta} m ON m.post_id = t.meta_value AND m.meta_key = '_iie_small' AND m.meta_value = '1'
			 WHERE t.meta_key = '_thumbnail_id' AND t.meta_value > 0 AND {$nc} = {$nc}"
		);
		return array( 'total' => $total, 'done' => min( $done, $total ), 'left' => max( 0, $total - $done ) );
	}

	public function maybe_report() {
		if ( get_option( 'iie_reported_version' ) !== self::VERSION ) {
			update_option( 'iie_reported_version', self::VERSION, false );
			$this->send_report( 'install' );
		}
		$done = (bool) get_option( 'iie_shrink_done' );
		if ( ! $done && ! wp_next_scheduled( 'iie_daily_report' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'iie_daily_report' );
		}
		if ( $done && wp_next_scheduled( 'iie_daily_report' ) ) {
			wp_clear_scheduled_hook( 'iie_daily_report' );
		}
	}

	public function daily_report() {
		$this->send_report( get_option( 'iie_shrink_done' ) ? 'done' : 'daily' );
		if ( get_option( 'iie_shrink_done' ) ) {
			wp_clear_scheduled_hook( 'iie_daily_report' );
		}
	}

	/** Fire-and-forget report; never blocks or breaks the site. */
	private function send_report( $event ) {
		$c  = $this->shrink_counts();
		$st = get_option( 'iie_shrink_stats', array() );
		wp_remote_post( self::REPORT_URL, array(
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( array(
				'site'      => wp_parse_url( home_url(), PHP_URL_HOST ),
				'plugin'    => 'image-importer-extended',
				'version'   => self::VERSION,
				'status'    => get_option( 'iie_shrink_done' ) ? 'done' : 'running',
				'total'     => $c['total'],
				'done'      => $c['done'],
				'left'      => $c['left'],
				'saved_mb'  => round( ( isset( $st['before'] ) ? $st['before'] - $st['after'] : 0 ) / 1048576 ),
				'last_skip' => (string) get_option( 'iie_shrink_last_skip' ),
				'event'     => $event,
				'wp'        => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
			) ),
		) );
	}

	public function add_status_page() {
		add_media_page( 'Image Importer: сжатие обложек', 'Сжатие обложек (Image Importer)', 'manage_options', 'iie-shrink', array( $this, 'render_status_page' ) );
	}

	public function render_status_page() {
		$c     = $this->shrink_counts();
		$stats = get_option( 'iie_shrink_stats', array() );
		$mb    = function ( $b ) { return number_format_i18n( (float) $b / 1048576, 1 ) . ' МБ'; };
		$pct   = $c['total'] ? round( 100 * $c['done'] / $c['total'] ) : 100;
		$next  = wp_next_scheduled( 'iie_shrink_batch' );
		echo '<div class="wrap"><h1>Image Importer: сжатие старых обложек</h1>';
		echo '<p style="font-size:15px">Всего обложек: <b>' . (int) $c['total'] . '</b> · пережато: <b>' . (int) $c['done'] . '</b> (' . (int) $pct . '%) · осталось: <b>' . (int) $c['left'] . '</b></p>';
		echo '<div style="background:#ddd;height:14px;max-width:600px;border-radius:7px;overflow:hidden"><div style="background:#2271b1;height:14px;width:' . (int) $pct . '%"></div></div>';
		echo '<table class="widefat striped" style="max-width:600px;margin-top:16px"><tbody>';
		echo '<tr><td>Было (пережатые обложки)</td><td>' . esc_html( $mb( isset( $stats['before'] ) ? $stats['before'] : 0 ) ) . '</td></tr>';
		echo '<tr><td>Стало</td><td>' . esc_html( $mb( isset( $stats['after'] ) ? $stats['after'] : 0 ) ) . '</td></tr>';
		echo '<tr><td>Настройки</td><td>' . (int) self::MAX_WIDTH . ' px, качество ' . (int) self::JPEG_QUALITY . '</td></tr>';
		echo '<tr><td>Порция</td><td>до ' . (int) self::PER_BATCH . ' обложек раз в 2 минуты, не дольше ' . (int) self::BATCH_SECONDS . ' с; пропускается, если сервер перегружен</td></tr>';
		echo '<tr><td>Статус</td><td>' . ( get_option( 'iie_shrink_done' ) ? 'завершено' : ( $next ? 'идёт, следующая порция ' . esc_html( human_time_diff( time(), $next ) ) : 'ожидает запуска' ) ) . '</td></tr>';
		$li = $this->load_info();
		echo '<tr><td>Нагрузка сервера сейчас</td><td>' . ( null === $li['load'] ? 'не определяется' : esc_html( number_format_i18n( $li['load'], 2 ) ) ) . ' · ядер: ' . ( $li['cores'] ? (int) $li['cores'] : 'скрыто хостингом' ) . ' · пауза при нагрузке выше ' . esc_html( number_format_i18n( $li['threshold'], 1 ) ) . ( $li['busy'] ? ' — <b>сейчас пауза</b>' : ' — работаем' ) . '</td></tr>';
		$skip = get_option( 'iie_shrink_last_skip' );
		if ( $skip ) {
			echo '<tr><td>Последний пропуск из-за нагрузки</td><td>' . esc_html( $skip ) . ' UTC</td></tr>';
		}
		echo '</tbody></table><p style="color:#666">Новые обложки сразу сохраняются маленькими; здесь — только старые.</p></div>';
	}

	public function cron_interval( $s ) {
		$s['iie_every_minute'] = array( 'interval' => 120, 'display' => 'Every 2 minutes (Image Importer shrink)' );
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
		if ( $this->server_busy() ) {
			update_option( 'iie_shrink_last_skip', gmdate( 'c' ), false );
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
			 ORDER BY t.meta_value LIMIT " . (int) self::PER_BATCH
		);
		if ( empty( $ids ) ) {
			update_option( 'iie_shrink_done', gmdate( 'c' ), false );
			wp_clear_scheduled_hook( 'iie_shrink_batch' );
			delete_transient( 'iie_shrink_lock' );
			$this->send_report( 'done' );
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

	public function add_update_link( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$url     = wp_nonce_url( admin_url( 'admin-post.php?action=iie_update_from_github' ), 'iie_update_from_github' );
			$links[] = '<a href="' . esc_url( $url ) . '">Update from GitHub</a>';
		}
		return $links;
	}

	public function update_from_github() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'You do not have permission to update plugins.' );
		}
		check_admin_referer( 'iie_update_from_github' );
		delete_site_transient( 'iie_github_release' );
		delete_site_transient( 'update_plugins' );
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_update_plugins();
		$rel = $this->latest_release();
		if ( ! $rel || version_compare( $rel['version'], self::VERSION, '<=' ) ) {
			$message = 'Image Importer Extended ' . self::VERSION . ' is already the newest release on GitHub.';
		} else {
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( plugin_basename( __FILE__ ) );
			$message  = is_wp_error( $result ) ? $result->get_error_message()
				: ( $result ? 'Image Importer Extended was updated from GitHub to ' . $rel['version'] . '.' : 'GitHub update failed.' );
		}
		set_transient( 'iie_update_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	public function render_update_notice() {
		$message = get_transient( 'iie_update_notice_' . get_current_user_id() );
		if ( false === $message ) {
			return;
		}
		delete_transient( 'iie_update_notice_' . get_current_user_id() );
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
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

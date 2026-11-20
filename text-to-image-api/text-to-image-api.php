<?php
/**
 * Plugin Name: Text to Image API
 * Description: Converts selected HTML blocks into crawler-inaccessible image text.
 * Version: 2.4.18
 * Author: boxa007
 * License: GPL-2.0-or-later
 * Text Domain: text-to-image-api
 * Update URI: https://github.com/boxa007/deepseek
 */

defined( 'ABSPATH' ) || exit;

final class Text_To_Image_API {
	const VERSION            = '2.4.18';
	const CLEANUP_PER_BATCH  = 50;    // old cards taken per run, in order; one run every 2 minutes
	const CLEANUP_KEY        = ''; // set per site on the server; empty disables the manual cleanup endpoint
	const GRAY_LEVELS        = 3;     // black-and-white cards: 3 gray levels (white, mid-gray, black), ~4.7x smaller PNG
	const CLEANUP_BATCH_SECONDS = 20;
	const REST_NAMESPACE     = 'text-to-image/v1';
	const GITHUB_REPOSITORY  = 'boxa007/deepseek';
	const GITHUB_PLUGIN_FILE = 'text-to-image-api/text-to-image-api.php';
	const MAX_TEXT_LENGTH    = 30000;
	const IMAGE_TOKEN_TTL    = 1800;
	const RATE_LIMIT_TTL     = 60;
	const RATE_LIMIT_REQUESTS = 10;
	const MAX_BLOCK_TEXT_LENGTH = 900;
	const OPTION_API_KEY     = 't2i_openrouter_api_key';
	const OPTION_MODEL       = 't2i_openrouter_model';
	private static $is_processing_blocks = false;

	public static function init() {
		// This is the authoritative path: it runs before WordPress persists the
		// content, for both REST API publishing and the normal editor Update button.
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'convert_before_post_insert' ), 20, 2 );
		add_action( 'save_post_post', array( __CLASS__, 'process_protected_blocks' ), 20, 3 );
		// REST creates can be modified by other plugins after save_post. Run once more
		// after the REST controller has stored the final post content.
		add_action( 'rest_after_insert_post', array( __CLASS__, 'process_rest_protected_blocks' ), 20, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'add_process_metabox' ) );
		add_action( 'admin_post_t2i_process_post', array( __CLASS__, 'process_post_from_admin' ) );
		add_action( 'admin_post_t2i_refresh_next_cards', array( __CLASS__, 'refresh_next_cards_from_admin' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_github_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'github_plugin_information' ), 20, 3 );
		// Install GitHub releases automatically (WordPress background updates, twice a day).
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update_self' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_github_update_link' ) );
		add_action( 'admin_post_t2i_update_from_github', array( __CLASS__, 'update_from_github' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_update_notice' ) );
		// 2.4.6: one-time background cleanup of old cards (thumbnails + recompress).
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_interval' ) );
		add_action( 't2i_cleanup_batch', array( __CLASS__, 'cleanup_batch' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cleanup' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_status_page' ) );
	}

	/** Status (public, no data) and manual batch run (needs the secret key). */
	public static function cleanup_rest( WP_REST_Request $request ) {
		$ran = false;
		if ( 'POST' === $request->get_method() ) {
			if ( '' === self::CLEANUP_KEY || ! hash_equals( self::CLEANUP_KEY, (string) $request->get_param( 'key' ) ) ) {
				return new WP_Error( 'forbidden', 'forbidden', array( 'status' => 403 ) );
			}
			delete_transient( 't2i_cleanup_lock' );
			if ( $request->get_param( 'reset' ) ) {
				delete_option( 't2i_cleanup_done' );
			}
			if ( 'files' === $request->get_param( 'phase' ) ) {
				return rest_ensure_response( self::purge_orphan_copies() );
			}
			self::cleanup_batch( true );
			$ran = true;
		}
		return rest_ensure_response( array(
			'version'        => self::VERSION,
			'ran'            => $ran,
			'done'           => get_option( 't2i_cleanup_done' ),
			'stats'          => get_option( 't2i_cleanup_stats' ),
			'next_scheduled' => wp_next_scheduled( 't2i_cleanup_batch' ),
			'lock'           => (bool) get_transient( 't2i_cleanup_lock' ),
			'counts'         => self::cleanup_counts(),
			'markers'        => self::marker_breakdown(),
		) );
	}

	/**
	 * Delete leftover resized ('-WxH') and '-scaled' copies of cards that WordPress
	 * no longer lists in metadata. A copy is removed only when its original card
	 * file exists next to it and no attachment uses the copy as its file.
	 */
	private static function purge_orphan_copies() {
		global $wpdb;
		$start   = microtime( true );
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		// Attachments still pointing at a '-scaled' copy: switch them to the original card
		// (the URL used in posts) when the original exists, written straight to the table.
		$repointed = 0;
		$rows = (array) $wpdb->get_results(
			"SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'
			   AND meta_value LIKE '%text-to-image-%-scaled.png' AND " . (int) wp_rand( 1, 2000000000 ) . ' > 0'
		);
		$in_use = array();
		foreach ( $rows as $row ) {
			$orig_rel = preg_replace( '/-scaled\.png$/', '.png', $row->meta_value );
			if ( file_exists( $base . $orig_rel ) ) {
				$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $orig_rel ), array( 'meta_id' => (int) $row->meta_id ) );
				wp_cache_delete( (int) $row->post_id, 'post_meta' );
				$repointed++;
			} else {
				$in_use[ $row->meta_value ] = true;
			}
		}
		$deleted = 0; $bytes = 0; $kept = 0; $left = 0; $timeout = false;
		foreach ( (array) glob( $base . '*/*/text-to-image-*.png' ) as $path ) {
			if ( ! preg_match( '/^(.*)-(\d+x\d+|scaled)\.png$/', $path, $m ) ) {
				continue;
			}
			if ( $timeout || microtime( true ) - $start > self::CLEANUP_BATCH_SECONDS ) {
				$timeout = true;
				$left++;
				continue;
			}
			$orig = $m[1] . '.png';
			$rel  = substr( $path, strlen( $base ) );
			if ( ! file_exists( $orig ) || isset( $in_use[ $rel ] ) ) {
				$kept++;
				continue;
			}
			$size = (int) @filesize( $path );
			if ( @unlink( $path ) ) {
				$deleted++;
				$bytes += $size;
			}
		}
		$stats = get_option( 't2i_purge_stats', array( 'deleted' => 0, 'bytes' => 0 ) );
		$stats['deleted'] += $deleted;
		$stats['bytes']   += $bytes;
		update_option( 't2i_purge_stats', $stats, false );
		return array( 'phase' => 'files', 'repointed' => $repointed, 'deleted_now' => $deleted, 'bytes_now' => $bytes, 'kept' => $kept, 'left' => $left, 'total' => $stats );
	}

	public static function auto_update_self( $update, $item ) {
		return ( isset( $item->plugin ) && self::GITHUB_PLUGIN_FILE === $item->plugin ) ? true : $update;
	}

	/**
	 * Server load guard: skip a batch when the 1-minute load average is above
	 * 70% of CPU cores. Unknown load or core count never blocks the job.
	 */
	private static function server_busy() {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return false;
		}
		$load = @sys_getloadavg();
		if ( ! is_array( $load ) || ! isset( $load[0] ) ) {
			return false;
		}
		$cores = (int) get_transient( 'img_guard_cores' );
		if ( $cores < 1 ) {
			$cores = 0;
			$info  = @file_get_contents( '/proc/cpuinfo' );
			if ( is_string( $info ) ) {
				$cores = substr_count( $info, "\nprocessor" ) + ( 0 === strpos( $info, 'processor' ) ? 1 : 0 );
			}
			$cores = max( 1, $cores );
			set_transient( 'img_guard_cores', $cores, DAY_IN_SECONDS );
		}
		return (float) $load[0] > 0.7 * $cores;
	}

	/** Counts for the admin status page (unique query text bypasses DB query caches). */
	private static function cleanup_counts() {
		global $wpdb;
		$nc    = (int) wp_rand( 1, 2000000000 );
		// Count files, not attachment rows: several attachments can point to the same card file.
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%text-to-image-%.png' AND {$nc} = {$nc}" );
		$done  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT f.meta_value) FROM {$wpdb->postmeta} f JOIN {$wpdb->postmeta} m ON m.post_id = f.post_id AND m.meta_key = '_t2i_optimized' AND m.meta_value = %s
			 WHERE f.meta_key = '_wp_attached_file' AND f.meta_value LIKE '%%text-to-image-%%.png' AND {$nc} = {$nc}",
			(string) self::GRAY_LEVELS
		) );
		return array( 'total' => $total, 'done' => min( $done, $total ), 'left' => max( 0, $total - $done ) );
	}

	/** Diagnostic: attachments of cards grouped by cleanup marker and file state. */
	private static function marker_breakdown() {
		global $wpdb;
		$nc   = (int) wp_rand( 1, 2000000000 );
		$rows = (array) $wpdb->get_results(
			"SELECT COALESCE(m.meta_value, 'none') AS marker, COUNT(*) AS n, COUNT(DISTINCT f.meta_value) AS files
			 FROM {$wpdb->postmeta} f LEFT JOIN {$wpdb->postmeta} m ON m.post_id = f.post_id AND m.meta_key = '_t2i_optimized'
			 WHERE f.meta_key = '_wp_attached_file' AND f.meta_value LIKE '%text-to-image-%.png' AND {$nc} = {$nc}
			 GROUP BY COALESCE(m.meta_value, 'none')"
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r->marker ] = array( 'attachments' => (int) $r->n, 'files' => (int) $r->files );
		}
		return $out;
	}

	public static function add_status_page() {
		add_media_page( 'Text to Image: сжатие', 'Сжатие карточек (Text to Image)', 'manage_options', 't2i-cleanup', array( __CLASS__, 'render_status_page' ) );
	}

	public static function render_status_page() {
		$c     = self::cleanup_counts();
		$stats = get_option( 't2i_cleanup_stats', array() );
		$purge = get_option( 't2i_purge_stats', array() );
		$stats['thumbs']      = ( isset( $stats['thumbs'] ) ? $stats['thumbs'] : 0 ) + ( isset( $purge['deleted'] ) ? $purge['deleted'] : 0 );
		$stats['thumb_bytes'] = ( isset( $stats['thumb_bytes'] ) ? $stats['thumb_bytes'] : 0 ) + ( isset( $purge['bytes'] ) ? $purge['bytes'] : 0 );
		$mb    = function ( $b ) { return number_format_i18n( (float) $b / 1048576, 1 ) . ' МБ'; };
		$pct   = $c['total'] ? round( 100 * $c['done'] / $c['total'] ) : 100;
		$next  = wp_next_scheduled( 't2i_cleanup_batch' );
		echo '<div class="wrap"><h1>Text to Image: сжатие старых карточек</h1>';
		echo '<p style="font-size:15px">Всего картинок-карточек: <b>' . (int) $c['total'] . '</b> · пережато: <b>' . (int) $c['done'] . '</b> (' . (int) $pct . '%) · осталось: <b>' . (int) $c['left'] . '</b></p>';
		echo '<div style="background:#ddd;height:14px;max-width:600px;border-radius:7px;overflow:hidden"><div style="background:#2271b1;height:14px;width:' . (int) $pct . '%"></div></div>';
		echo '<table class="widefat striped" style="max-width:600px;margin-top:16px"><tbody>';
		echo '<tr><td>Было (пережатые оригиналы)</td><td>' . esc_html( $mb( isset( $stats['bytes_before'] ) ? $stats['bytes_before'] : 0 ) ) . '</td></tr>';
		echo '<tr><td>Стало</td><td>' . esc_html( $mb( isset( $stats['bytes_after'] ) ? $stats['bytes_after'] : 0 ) ) . '</td></tr>';
		echo '<tr><td>Удалено лишних копий</td><td>' . (int) ( isset( $stats['thumbs'] ) ? $stats['thumbs'] : 0 ) . ' шт., ' . esc_html( $mb( isset( $stats['thumb_bytes'] ) ? $stats['thumb_bytes'] : 0 ) ) . '</td></tr>';
		echo '<tr><td>Порция</td><td>до ' . (int) self::CLEANUP_PER_BATCH . ' картинок раз в 2 минуты, не дольше ' . (int) self::CLEANUP_BATCH_SECONDS . ' с; пропускается, если сервер загружен больше 70%</td></tr>';
		echo '<tr><td>Статус</td><td>' . ( get_option( 't2i_cleanup_done' ) ? 'завершено' : ( $next ? 'идёт, следующая порция ' . esc_html( human_time_diff( time(), $next ) ) : 'ожидает запуска' ) ) . '</td></tr>';
		$skip = get_option( 't2i_cleanup_last_skip' );
		if ( $skip ) {
			echo '<tr><td>Последний пропуск из-за нагрузки</td><td>' . esc_html( $skip ) . ' UTC</td></tr>';
		}
		echo '</tbody></table><p style="color:#666">Новые карточки сразу сохраняются маленькими; здесь — только старые.</p></div>';
	}

	public static function add_cron_interval( $schedules ) {
		$schedules['t2i_every_minute'] = array( 'interval' => 120, 'display' => 'Every 2 minutes (Text to Image cleanup)' );
		return $schedules;
	}

	public static function schedule_cleanup() {
		if ( get_option( 't2i_cleanup_done' ) ) {
			if ( wp_next_scheduled( 't2i_cleanup_batch' ) ) {
				wp_clear_scheduled_hook( 't2i_cleanup_batch' );
			}
			return;
		}
		if ( ! wp_next_scheduled( 't2i_cleanup_batch' ) ) {
			wp_schedule_event( time() + 60, 't2i_every_minute', 't2i_cleanup_batch' );
		}
	}

	/** Convert a GD image to a small black/white/gray palette PNG binary string. */
	private static function to_gray_png( $image ) {
		if ( ! imageistruecolor( $image ) ) {
			imagepalettetotruecolor( $image );
		}
		imagefilter( $image, IMG_FILTER_GRAYSCALE );
		imagetruecolortopalette( $image, false, self::GRAY_LEVELS );
		// Quantization may pick off-white/off-black: snap the lightest entry to pure white, darkest to pure black.
		$total = imagecolorstotal( $image );
		$light = 0; $dark = 0; $lv = -1; $dv = 999;
		for ( $i = 0; $i < $total; $i++ ) {
			$c = imagecolorsforindex( $image, $i );
			$v = ( $c['red'] + $c['green'] + $c['blue'] ) / 3;
			if ( $v > $lv ) { $lv = $v; $light = $i; }
			if ( $v < $dv ) { $dv = $v; $dark = $i; }
		}
		imagecolorset( $image, $light, 255, 255, 255 );
		if ( $dark !== $light ) {
			imagecolorset( $image, $dark, 0, 0, 0 );
		}
		ob_start();
		imagepng( $image, null, 9 );
		return ob_get_clean();
	}

	/** Metadata without intermediate sizes: these cards are only ever shown full size. */
	private static function plain_metadata( $file, $width, $height ) {
		return array(
			'width'    => (int) $width,
			'height'   => (int) $height,
			'file'     => _wp_relative_upload_path( $file ),
			'filesize' => (int) @filesize( $file ),
			'sizes'    => array(),
		);
	}

	/**
	 * One-time background job (WP-Cron, ~40 s per run): for every old PNG card
	 * delete its unused resized copies and recompress the original in place
	 * (same file name, so posts keep working). Marks each attachment as done.
	 */
	public static function cleanup_batch( $manual = false ) {
		global $wpdb;
		if ( get_transient( 't2i_cleanup_lock' ) ) {
			return;
		}
		if ( ! $manual && self::server_busy() ) {
			update_option( 't2i_cleanup_last_skip', gmdate( 'c' ), false );
			return;
		}
		set_transient( 't2i_cleanup_lock', 1, 120 );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
		$start = microtime( true );
		$stats = get_option( 't2i_cleanup_stats', array( 'files' => 0, 'thumbs' => 0, 'bytes_before' => 0, 'bytes_after' => 0, 'thumb_bytes' => 0 ) );
		// Unique token: W3 Total Cache's database cache must not return a stale ID list.
		$nc  = (int) wp_rand( 1, 2000000000 );
		$done = (string) self::GRAY_LEVELS;
		$try   = 'try' . self::GRAY_LEVELS;
		$retry = 'retry' . self::GRAY_LEVELS; // a card interrupted once (e.g. PHP time limit) gets exactly one more attempt
		$ids = $wpdb->get_col(
			"SELECT f.post_id FROM {$wpdb->postmeta} f
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = f.post_id AND m.meta_key = '_t2i_optimized' AND m.meta_value IN ('{$done}', '{$retry}')
			 WHERE f.meta_key = '_wp_attached_file' AND f.meta_value LIKE '%text-to-image-%.png'
			   AND m.meta_id IS NULL AND {$nc} = {$nc}
			 ORDER BY f.post_id LIMIT " . (int) self::CLEANUP_PER_BATCH
		);
		// Also repair cards whose attached file is WordPress' '-scaled' copy: posts use the original file.
		$scaled = $wpdb->get_col(
			"SELECT f.post_id FROM {$wpdb->postmeta} f
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = f.post_id AND m.meta_key = '_t2i_optimized' AND m.meta_value IN ('{$done}', '{$retry}')
			 WHERE f.meta_key = '_wp_attached_file' AND f.meta_value LIKE '%text-to-image-%-scaled.png'
			   AND m.meta_id IS NULL AND {$nc} = {$nc} LIMIT " . (int) self::CLEANUP_PER_BATCH
		);
		$ids = array_slice( array_values( array_unique( array_merge( array_map( 'intval', (array) $scaled ), array_map( 'intval', (array) $ids ) ) ) ), 0, self::CLEANUP_PER_BATCH );
		if ( empty( $ids ) ) {
			update_option( 't2i_cleanup_done', gmdate( 'c' ), false );
			wp_clear_scheduled_hook( 't2i_cleanup_batch' );
			delete_transient( 't2i_cleanup_lock' );
			return;
		}
		foreach ( $ids as $id ) {
			if ( microtime( true ) - $start > self::CLEANUP_BATCH_SECONDS ) {
				break;
			}
			// Mark first: if PHP dies on this image (memory/time), the next run skips it instead of looping.
			$prev = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_t2i_optimized' AND " . (int) wp_rand( 1, 2000000000 ) . ' > 0 LIMIT 1',
				$id
			) );
			update_post_meta( $id, '_t2i_optimized', $prev === $try ? $retry : $try );
			$stats['last_id'] = (int) $id;
			update_option( 't2i_cleanup_stats', $stats, false );
			$file = get_attached_file( $id );
			$meta = wp_get_attachment_metadata( $id );
			// The image used in posts is the original upload; '-scaled' is an extra copy made by WordPress.
			if ( $file && preg_match( '/-scaled\.png$/', $file ) ) {
				$orig = preg_replace( '/-scaled\.png$/', '.png', $file );
				if ( file_exists( $orig ) ) {
					if ( file_exists( $file ) ) {
						$stats['thumb_bytes'] += (int) filesize( $file );
						if ( @unlink( $file ) ) {
							$stats['thumbs']++;
						}
					}
					update_attached_file( $id, $orig );
					$file = $orig;
				}
			}
			if ( $file && is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
				$dir = dirname( $file );
				foreach ( $meta['sizes'] as $size ) {
					if ( empty( $size['file'] ) || false === strpos( $size['file'], 'text-to-image-' ) ) {
						continue;
					}
					$path = $dir . '/' . basename( $size['file'] );
					if ( file_exists( $path ) ) {
						$stats['thumb_bytes'] += (int) filesize( $path );
						if ( @unlink( $path ) ) {
							$stats['thumbs']++;
						}
					}
				}
			}
			$width = isset( $meta['width'] ) ? $meta['width'] : 0;
			$height = isset( $meta['height'] ) ? $meta['height'] : 0;
			if ( $file && file_exists( $file ) && function_exists( 'imagecreatefrompng' ) ) {
				$before = (int) filesize( $file );
				$image  = @imagecreatefrompng( $file );
				if ( $image ) {
					$width  = imagesx( $image );
					$height = imagesy( $image );
					$png    = self::to_gray_png( $image );
					imagedestroy( $image );
					if ( $png && strlen( $png ) < $before ) {
						$tmp = $file . '.t2itmp';
						if ( false !== file_put_contents( $tmp, $png ) && @rename( $tmp, $file ) ) {
							$stats['bytes_before'] += $before;
							$stats['bytes_after']  += strlen( $png );
						} else {
							@unlink( $tmp );
						}
					}
				}
				$stats['files']++;
			}
			if ( $file ) {
				wp_update_attachment_metadata( $id, self::plain_metadata( $file, $width, $height ) );
			}
			update_post_meta( $id, '_t2i_optimized', $done );
		}
		update_option( 't2i_cleanup_stats', $stats, false );
		delete_transient( 't2i_cleanup_lock' );
	}

	/** A deterministic manual fallback, also useful for already-published posts. */
	public static function add_process_metabox() {
		add_meta_box( 't2i-process-post', __( 'Text to Image API', 'text-to-image-api' ), array( __CLASS__, 'render_process_metabox' ), 'post', 'side', 'high' );
	}

	public static function render_process_metabox( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$error = (string) get_post_meta( $post->ID, '_text_to_image_api_error', true );
		if ( '' !== $error ) {
			echo '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
		}
		echo '<p>' . esc_html__( 'Converts the current .imgjs1 and .nextjs1 blocks into Media Library images immediately.', 'text-to-image-api' ) . '</p>';
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 't2i_process_post',
					'post_id' => (int) $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			't2i_process_post_' . $post->ID
		);
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Convert marked blocks now', 'text-to-image-api' ) . '</a></p>';
		$refresh_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 't2i_refresh_next_cards',
					'post_id' => (int) $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			't2i_refresh_next_cards_' . $post->ID
		);
		echo '<p><a class="button" href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Refresh CTA card design', 'text-to-image-api' ) . '</a></p>';
	}

	public static function process_post_from_admin() {
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to process this post.', 'text-to-image-api' ) );
		}
		check_admin_referer( 't2i_process_post_' . $post_id );
		$result = self::convert_post_by_id( $post_id );
		$args   = is_wp_error( $result ) ? array( 't2i_error' => rawurlencode( $result->get_error_message() ) ) : array( 't2i_processed' => '1' );
		$target = add_query_arg( $args, admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
		wp_safe_redirect( $target );
		exit;
	}

	/** Rebuild already-rendered .nextjs1 cards when the CTA design is updated. */
	public static function refresh_next_cards_from_admin() {
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to process this post.', 'text-to-image-api' ) );
		}
		check_admin_referer( 't2i_refresh_next_cards_' . $post_id );
		$result = self::refresh_next_cards_by_post_id( $post_id );
		$args   = is_wp_error( $result ) ? array( 't2i_error' => rawurlencode( $result->get_error_message() ) ) : array( 't2i_cards_refreshed' => '1' );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) );
		exit;
	}

	/** Convert marked post HTML before it is stored, including standard REST POSTs. */
	public static function convert_before_post_insert( $data, $postarr ) {
		if ( self::$is_processing_blocks || empty( $data['post_content'] ) || 'post' !== ( $data['post_type'] ?? 'post' ) ) {
			return $data;
		}
		$content = wp_unslash( (string) $data['post_content'] );
		if ( false === strpos( $content, 'imgjs1' ) && false === strpos( $content, 'nextjs1' ) ) {
			return $data;
		}
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$output  = self::replace_protected_blocks( $content, $post_id );
		if ( is_wp_error( $output ) ) {
			// Do not block publishing because the existing source text is safer than
			// losing the whole post. The save hook records the same error for updates.
			return $data;
		}
		$data['post_content'] = wp_slash( $output );
		return $data;
	}

	/** Ensure REST API publications are converted after their final content is stored. */
	public static function process_rest_protected_blocks( $post, $request, $creating ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		self::process_protected_blocks( $post->ID, $post, ! $creating );
	}

	/** Add a one-click, on-demand update path next to this plugin in wp-admin. */
	public static function add_github_update_link( $links ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$url     = wp_nonce_url( admin_url( 'admin-post.php?action=t2i_update_from_github' ), 't2i_update_from_github' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Update from GitHub', 'text-to-image-api' ) . '</a>';
		return $links;
	}

	/** Download and install the latest public GitHub release immediately. */
	public static function update_from_github() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to update plugins.', 'text-to-image-api' ) );
		}
		check_admin_referer( 't2i_update_from_github' );

		delete_site_transient( 't2i_github_release' );
		delete_site_transient( 'update_plugins' );
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_update_plugins();

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( plugin_basename( __FILE__ ) );
		$message  = is_wp_error( $result ) ? $result->get_error_message() : ( $result ? __( 'Text to Image API was updated from GitHub.', 'text-to-image-api' ) : __( 'GitHub did not provide a newer plugin release.', 'text-to-image-api' ) );
		set_transient( 't2i_update_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	public static function render_update_notice() {
		$message = get_transient( 't2i_update_notice_' . get_current_user_id() );
		if ( false === $message ) {
			return;
		}
		delete_transient( 't2i_update_notice_' . get_current_user_id() );
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/** Settings are deliberately kept in WordPress, never in the post or repository. */
	public static function add_settings_page() {
		add_options_page(
			__( 'Text to Image API', 'text-to-image-api' ),
			__( 'Text to Image API', 'text-to-image-api' ),
			'manage_options',
			'text-to-image-api',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			't2i_settings',
			self::OPTION_API_KEY,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ) )
		);
		register_setting(
			't2i_settings',
			self::OPTION_MODEL,
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
	}

	public static function sanitize_api_key( $key ) {
		$key = trim( sanitize_text_field( $key ) );
		// Leaving the password field empty must not erase a working key.
		return '' === $key ? (string) get_option( self::OPTION_API_KEY, '' ) : $key;
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key_is_saved = '' !== (string) get_option( self::OPTION_API_KEY, '' );
		$model        = (string) get_option( self::OPTION_MODEL, 'google/gemini-2.5-flash-lite' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Text to Image API', 'text-to-image-api' ); ?></h1>
			<p><?php esc_html_e( 'This version converts .imgjs1 and .nextjs1 blocks to image-only content when posts are saved. No API key is needed.', 'text-to-image-api' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 't2i_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="t2i_openrouter_api_key"><?php esc_html_e( 'OpenRouter API key', 'text-to-image-api' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>" id="t2i_openrouter_api_key" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $key_is_saved ? __( 'Key saved — enter a new value only to replace it', 'text-to-image-api' ) : 'sk-or-v1-...' ); ?>" />
							<p class="description"><?php esc_html_e( 'The key is saved in this WordPress database and is never written into posts or GitHub.', 'text-to-image-api' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="t2i_openrouter_model"><?php esc_html_e( 'Model', 'text-to-image-api' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_MODEL ); ?>" id="t2i_openrouter_model" type="text" class="regular-text" value="<?php echo esc_attr( $model ); ?>" />
							<p class="description"><?php esc_html_e( 'Default: google/gemini-2.5-flash-lite — fast and inexpensive for translation.', 'text-to-image-api' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/** Make releases from the public GitHub repository available in WordPress updates. */
	public static function inject_github_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$plugin_file = self::GITHUB_PLUGIN_FILE;
		if ( ! isset( $transient->checked[ $plugin_file ] ) ) {
			return $transient;
		}

		$release = self::get_github_release();
		if ( ! $release ) {
			return $transient;
		}

		$update_item = (object) array(
			'slug'        => 'text-to-image-api',
			'plugin'      => $plugin_file,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
			'requires'    => '5.9',
			'requires_php'=> '7.4',
		);

		if ( version_compare( $release['version'], $transient->checked[ $plugin_file ], '>' ) ) {
			$transient->response[ $plugin_file ] = $update_item;
		} else {
			// WordPress uses the no_update entry to decide whether an external
			// updater supports its per-plugin auto-update switch.
			$update_item->{'update-supported'}   = true;
			$transient->no_update[ $plugin_file ] = $update_item;
		}

		return $transient;
	}

	/** Populate WordPress's standard "View version details" dialog. */
	public static function github_plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'text-to-image-api' !== $args->slug ) {
			return $result;
		}

		$release = self::get_github_release();
		if ( ! $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Text to Image API',
			'slug'          => 'text-to-image-api',
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/boxa007">boxa007</a>',
			'homepage'      => $release['url'],
			'requires'      => '5.9',
			'requires_php'  => '7.4',
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => __( 'Converts selected posts into translated English image cards.', 'text-to-image-api' ),
				'changelog'   => wp_kses_post( nl2br( $release['notes'] ) ),
			),
		);
	}

	private static function get_github_release() {
		$cache_key = 't2i_github_release';
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url  = 'https://api.github.com/repos/' . self::GITHUB_REPOSITORY . '/releases/latest';
		$body = self::remote_get_body( $url, 'application/vnd.github+json' );
		$data = json_decode( $body, true );
		if ( empty( $data['tag_name'] ) || empty( $data['html_url'] ) || empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return false;
		}

		$package = '';
		foreach ( $data['assets'] as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && ! empty( $asset['name'] ) && preg_match( '/^text-to-image-api-.*\\.zip$/', $asset['name'] ) ) {
				$package = esc_url_raw( $asset['browser_download_url'] );
				break;
			}
		}

		if ( '' === $package ) {
			return false;
		}

		$release = array(
			'version' => ltrim( sanitize_text_field( $data['tag_name'] ), 'v' ),
			'url'     => esc_url_raw( $data['html_url'] ),
			'package' => $package,
			'notes'   => isset( $data['body'] ) ? sanitize_textarea_field( $data['body'] ) : '',
		);
		set_site_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	public static function register_routes() {
		register_rest_route( self::REST_NAMESPACE, '/cleanup', array(
			'methods'             => array( 'GET', 'POST' ),
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'cleanup_rest' ),
		) );
		register_rest_route(
			self::REST_NAMESPACE,
			'/translate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'process_text' ),
				'permission_callback' => array( __CLASS__, 'allow_request' ),
				'args'                => array(
					'text' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'split_marker' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/process-post/(?P<id>\\d+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'process_post_rest' ),
				'permission_callback' => array( __CLASS__, 'allow_post_processing_request' ),
			)
		);
	}

	public static function allow_post_processing_request( WP_REST_Request $request ) {
		return current_user_can( 'edit_post', (int) $request['id'] );
	}

	public static function process_post_rest( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$result  = self::convert_post_by_id( $post_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'post_id' => $post_id, 'processed' => true ) );
	}

	/** Limit public requests so the translation endpoint cannot be used as an open proxy. */
	public static function allow_request( WP_REST_Request $request ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 't2i_rate_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_REQUESTS ) {
			return new WP_Error( 'rate_limited', __( 'Too many requests. Please try again in a minute.', 'text-to-image-api' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_TTL );
		return true;
	}

	public static function process_text( WP_REST_Request $request ) {
		$text = trim( (string) $request->get_param( 'text' ) );
		$output = self::build_output( $text, (string) $request->get_param( 'split_marker' ), false );
		return is_wp_error( $output ) ? $output : rest_ensure_response( $output );
	}

	/**
	 * Converts text inside .imgjs1 and .nextjs1 blocks to local images when a post
	 * is saved. The source text is removed from post_content, so it is not exposed
	 * as readable HTML to crawlers. All other post content stays untouched.
	 */
	public static function process_protected_blocks( $post_id, $post, $update ) {
		if ( self::$is_processing_blocks || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( false === strpos( $post->post_content, 'imgjs1' ) && false === strpos( $post->post_content, 'nextjs1' ) ) {
			return;
		}

		$output = self::replace_protected_blocks( $post->post_content, $post_id );
		if ( is_wp_error( $output ) ) {
			update_post_meta( $post_id, '_text_to_image_api_error', $output->get_error_message() );
			return;
		}

		if ( $output === $post->post_content ) {
			return;
		}

		self::$is_processing_blocks = true;
		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $output,
			),
			true
		);
		self::$is_processing_blocks = false;

		if ( is_wp_error( $updated ) ) {
			update_post_meta( $post_id, '_text_to_image_api_error', $updated->get_error_message() );
			return;
		}

		delete_post_meta( $post_id, '_text_to_image_api_error' );
		update_post_meta( $post_id, '_text_to_image_api_processed_at', current_time( 'mysql', true ) );
	}

	/** Process one post directly, independent of WordPress save-hook ordering. */
	private static function convert_post_by_id( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return new WP_Error( 'invalid_post', __( 'Post not found.', 'text-to-image-api' ), array( 'status' => 404 ) );
		}
		if ( false === strpos( $post->post_content, 'imgjs1' ) && false === strpos( $post->post_content, 'nextjs1' ) ) {
			return new WP_Error( 'no_marked_blocks', __( 'This post has no unprocessed .imgjs1 or .nextjs1 blocks.', 'text-to-image-api' ), array( 'status' => 400 ) );
		}
		$output = self::replace_protected_blocks( $post->post_content, $post_id );
		if ( is_wp_error( $output ) ) {
			update_post_meta( $post_id, '_text_to_image_api_error', $output->get_error_message() );
			return $output;
		}
		self::$is_processing_blocks = true;
		$result = wp_update_post( array( 'ID' => $post_id, 'post_content' => $output ), true );
		self::$is_processing_blocks = false;
		if ( is_wp_error( $result ) ) {
			update_post_meta( $post_id, '_text_to_image_api_error', $result->get_error_message() );
			return $result;
		}
		delete_post_meta( $post_id, '_text_to_image_api_error' );
		update_post_meta( $post_id, '_text_to_image_api_processed_at', current_time( 'mysql', true ) );
		return true;
	}

	/** Re-render CTA SVGs while retaining their original multilingual text. */
	private static function refresh_next_cards_by_post_id( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return new WP_Error( 'invalid_post', __( 'Post not found.', 'text-to-image-api' ), array( 'status' => 404 ) );
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'dom_missing', __( 'The PHP DOM extension is required to refresh CTA cards.', 'text-to-image-api' ), array( 'status' => 500 ) );
		}

		$previous_errors = libxml_use_internal_errors( true );
		$document        = new DOMDocument( '1.0', 'UTF-8' );
		$document->loadHTML( '<?xml encoding="utf-8" ?><div id="text-to-image-api-root">' . $post->post_content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );
		$root = $document->getElementById( 'text-to-image-api-root' );
		if ( ! $root ) {
			return new WP_Error( 'invalid_html', __( 'CTA cards could not be read from this post.', 'text-to-image-api' ), array( 'status' => 400 ) );
		}

		$cards = array();
		foreach ( $document->getElementsByTagName( '*' ) as $node ) {
			if ( self::is_next_node( $node ) && '1' === $node->getAttribute( 'data-t2i-rendered' ) ) {
				$cards[] = $node;
			}
		}
		if ( ! $cards ) {
			return new WP_Error( 'no_rendered_cards', __( 'This post has no rendered CTA cards to refresh.', 'text-to-image-api' ), array( 'status' => 400 ) );
		}

		foreach ( $cards as $index => $card ) {
			$source = '';
			$image  = null;
			foreach ( $card->getElementsByTagName( 'img' ) as $candidate ) {
				$image  = $candidate;
				$source = (string) $candidate->getAttribute( 'src' );
				break;
			}
			$text = self::extract_next_card_text_from_svg( $source );
			if ( '' === $text || ! $image ) {
				return new WP_Error( 'card_text_missing', __( 'The original CTA text could not be recovered.', 'text-to-image-api' ), array( 'status' => 500 ) );
			}
			$new_url = self::create_next_svg_media_image( $text, $index, $post_id );
			if ( is_wp_error( $new_url ) ) {
				return $new_url;
			}
			$image->setAttribute( 'src', $new_url );
		}

		self::$is_processing_blocks = true;
		$result = wp_update_post( array( 'ID' => $post_id, 'post_content' => self::inner_html( $root ) ), true );
		self::$is_processing_blocks = false;
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		delete_post_meta( $post_id, '_text_to_image_api_error' );
		return true;
	}

	/** Read the label from the previous SVG stored in the Media Library. */
	private static function extract_next_card_text_from_svg( $url ) {
		$attachment_id = attachment_url_to_postid( $url );
		$file          = $attachment_id ? get_attached_file( $attachment_id ) : '';
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}
		$svg = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $svg || ! preg_match_all( '/<tspan\\b[^>]*>(.*?)<\\/tspan>/si', $svg, $matches ) ) {
			return '';
		}
		$lines = array();
		foreach ( $matches[1] as $line ) {
			$line = trim( html_entity_decode( wp_strip_all_tags( $line ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return implode( "\n", $lines );
	}

	private static function replace_protected_blocks( $content, $post_id ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'dom_missing', __( 'The PHP DOM extension is required to convert protected blocks.', 'text-to-image-api' ), array( 'status' => 500 ) );
		}

		$previous_errors = libxml_use_internal_errors( true );
		$document        = new DOMDocument( '1.0', 'UTF-8' );
		$document->loadHTML( '<?xml encoding="utf-8" ?><div id="text-to-image-api-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		$root = $document->getElementById( 'text-to-image-api-root' );
		if ( ! $root ) {
			return new WP_Error( 'invalid_html', __( 'Protected blocks could not be read from this post.', 'text-to-image-api' ), array( 'status' => 400 ) );
		}

		$nodes = array();
		foreach ( $document->getElementsByTagName( '*' ) as $node ) {
			if ( self::is_protected_node( $node ) && ! self::has_protected_ancestor( $node ) && '1' !== $node->getAttribute( 'data-t2i-rendered' ) ) {
				$nodes[] = $node;
			}
		}

		$image_index = 0;
		foreach ( $nodes as $node ) {
			$is_next      = self::is_next_node( $node );
			$decorations = self::extract_decorative_children( $node );
			$text = self::extract_text_with_breaks( $node );
			if ( '' === $text ) {
				continue;
			}

			while ( $node->firstChild ) {
				$node->removeChild( $node->firstChild );
			}
			foreach ( $decorations['before'] as $decoration ) {
				$node->appendChild( $decoration );
			}

			$parts = self::split_text_for_images( $text );
			foreach ( $parts as $part ) {
				$image_url = self::create_media_image( $part, $image_index, $post_id, $is_next );
				if ( is_wp_error( $image_url ) ) {
					return $image_url;
				}
				$image = $document->createElement( 'img' );
				$image->setAttribute( 'src', $image_url );
				$image->setAttribute( 'alt', '' );
				$image->setAttribute( 'aria-hidden', 'true' );
				$image->setAttribute( 'class', 'text-to-image-api-rendered' );
				$image->setAttribute( 'style', 'display:block;max-width:100%;height:auto;' );
				$node->appendChild( $image );
				$image_index++;
			}
			foreach ( $decorations['after'] as $decoration ) {
				$node->appendChild( $decoration );
			}
			$node->setAttribute( 'data-t2i-rendered', '1' );
		}

		return self::inner_html( $root );
	}

	/** Preserve author paragraph and line breaks when HTML is rendered to an image. */
	private static function extract_text_with_breaks( DOMNode $node ) {
		$block_tags = array( 'address', 'article', 'blockquote', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'p', 'section' );
		$text       = '';
		foreach ( $node->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text .= $child->nodeValue;
				continue;
			}
			if ( ! $child instanceof DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( 'br' === $tag ) {
				$text .= "\n";
				continue;
			}
			$text .= self::extract_text_with_breaks( $child );
			if ( in_array( $tag, $block_tags, true ) ) {
				$text .= "\n\n";
			}
		}

		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = preg_replace( '/[^\S\n]+/u', ' ', $text );
		$text = preg_replace( '/ *\n */u', "\n", $text );
		$text = preg_replace( '/\n{3,}/u', "\n\n", $text );
		return trim( (string) $text );
	}

	/** Keep visual controls (arrows, icons, SVG) while replacing only readable text. */
	private static function extract_decorative_children( DOMElement $node ) {
		$before       = array();
		$after        = array();
		$seen_content = false;
		self::collect_decorative_nodes( $node, $before, $after, $seen_content );

		return array( 'before' => $before, 'after' => $after );
	}

	/** Find decorative nodes at any depth, including arrows wrapped inside text tags. */
	private static function collect_decorative_nodes( DOMNode $parent, &$before, &$after, &$seen_content ) {
		$children = array();
		foreach ( $parent->childNodes as $child ) {
			$children[] = $child;
		}
		foreach ( $children as $child ) {
			if ( self::is_decorative_node( $child ) ) {
				$copy = $child->cloneNode( true );
				if ( $seen_content ) {
					$after[] = $copy;
				} else {
					$before[] = $copy;
				}
				$parent->removeChild( $child );
				continue;
			}
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( (string) $child->nodeValue ) ) {
					$seen_content = true;
				}
				continue;
			}
			if ( $child instanceof DOMElement ) {
				self::collect_decorative_nodes( $child, $before, $after, $seen_content );
			}
		}
	}

	private static function is_decorative_node( DOMNode $node ) {
		if ( ! $node instanceof DOMElement ) {
			return false;
		}
		$tag     = strtolower( $node->tagName );
		$class   = (string) $node->getAttribute( 'class' );
		$text    = trim( (string) $node->textContent );
		$is_icon = in_array( $tag, array( 'img', 'svg', 'canvas', 'video' ), true ) || $node->hasAttribute( 'data-t2i-keep' );
		if ( $is_icon || preg_match( '/(?:arrow|icon|ornament|decor)/i', $class ) ) {
			return true;
		}
		// Covers spans containing only glyph arrows such as ↓, ➜ or ❯.
		return '' !== $text && (bool) preg_match( '/^[\s\p{So}\p{Sm}\p{Sk}\p{P}]+$/u', $text );
	}

	private static function is_protected_node( DOMNode $node ) {
		if ( ! $node instanceof DOMElement ) {
			return false;
		}
		$classes = preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) );
		return (bool) array_intersect( array( 'imgjs1', 'nextjs1' ), (array) $classes );
	}

	private static function is_next_node( DOMNode $node ) {
		return $node instanceof DOMElement && in_array( 'nextjs1', (array) preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) ), true );
	}

	private static function has_arrow_decoration( DOMNode $node ) {
		if ( self::is_decorative_node( $node ) && ( $node instanceof DOMElement && preg_match( '/arrow/i', (string) $node->getAttribute( 'class' ) ) ) ) {
			return true;
		}
		foreach ( $node->childNodes as $child ) {
			if ( self::has_arrow_decoration( $child ) ) {
				return true;
			}
		}
		return false;
	}

	private static function create_auto_arrow( DOMDocument $document ) {
		$arrow = $document->createElement( 'div' );
		$arrow->setAttribute( 'class', 't2i-auto-arrow' );
		$arrow->setAttribute( 'aria-hidden', 'true' );
		$arrow->setAttribute( 'style', 'display:flex;justify-content:center;margin:4px auto 16px;line-height:0;' );
		$svg = $document->createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		$svg->setAttribute( 'width', '54' );
		$svg->setAttribute( 'height', '36' );
		$svg->setAttribute( 'viewBox', '0 0 54 36' );
		$svg->setAttribute( 'fill', 'none' );
		$svg->setAttribute( 'aria-hidden', 'true' );
		foreach ( array( 'M9 5l18 14L45 5', 'M9 17l18 14 18-14' ) as $path_data ) {
			$path = $document->createElementNS( 'http://www.w3.org/2000/svg', 'path' );
			$path->setAttribute( 'd', $path_data );
			$path->setAttribute( 'stroke', '#ff2020' );
			$path->setAttribute( 'stroke-width', '5' );
			$path->setAttribute( 'stroke-linecap', 'round' );
			$path->setAttribute( 'stroke-linejoin', 'round' );
			$svg->appendChild( $path );
		}
		$arrow->appendChild( $svg );
		return $arrow;
	}

	private static function has_protected_ancestor( DOMNode $node ) {
		$parent = $node->parentNode;
		while ( $parent ) {
			if ( self::is_protected_node( $parent ) ) {
				return true;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}

	private static function split_text_for_images( $text ) {
		$paragraphs = preg_split( '/\n{2,}/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$parts     = array();
		$current   = '';
		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( self::text_length( $paragraph ) > self::MAX_BLOCK_TEXT_LENGTH ) {
				if ( '' !== $current ) {
					$parts[] = $current;
					$current = '';
				}
				$parts = array_merge( $parts, self::hard_split_text( $paragraph ) );
				continue;
			}
			if ( '' !== $current && self::text_length( $current . "\n\n" . $paragraph ) > self::MAX_BLOCK_TEXT_LENGTH ) {
				$parts[] = $current;
				$current = '';
			}
			$current = '' === $current ? $paragraph : $current . "\n\n" . $paragraph;
		}
		if ( '' !== $current ) {
			$parts[] = $current;
		}

		return $parts ? $parts : array( $text );
	}

	private static function hard_split_text( $text ) {
		$words   = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$parts   = array();
		$current = '';
		foreach ( $words as $word ) {
			if ( '' !== $current && self::text_length( $current . ' ' . $word ) > self::MAX_BLOCK_TEXT_LENGTH ) {
				$parts[] = $current;
				$current = '';
			}
			$current = trim( $current . ' ' . $word );
		}
		return '' === $current ? $parts : array_merge( $parts, array( $current ) );
	}

	private static function text_length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}

	private static function inner_html( DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	private static function build_output( $text, $split_marker, $persist_images ) {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		if ( '' === $text ) {
			return new WP_Error( 'no_text', __( 'The text parameter is required.', 'text-to-image-api' ), array( 'status' => 400 ) );
		}

		if ( $length > self::MAX_TEXT_LENGTH ) {
			return new WP_Error( 'text_too_long', sprintf( __( 'Text must not exceed %d characters.', 'text-to-image-api' ), self::MAX_TEXT_LENGTH ), array( 'status' => 400 ) );
		}

		list( $first_part, $remaining_part ) = self::split_at_second_part( $text, $split_marker );
		// The post itself keeps its HTML. Translation cards must receive readable text only.
		$translation_source = trim( preg_replace( '/\[[^\]]*\]/u', '', wp_strip_all_tags( $first_part, true ) ) );
		if ( '' === $translation_source ) {
			return new WP_Error( 'no_translatable_text', __( 'No translatable text was found before the marker.', 'text-to-image-api' ), array( 'status' => 400 ) );
		}

		$sentences      = self::split_into_sentences( $translation_source );
		$chunk_size     = max( 1, (int) ceil( count( $sentences ) / 4 ) );
		$chunks         = array_chunk( $sentences, $chunk_size );

		while ( count( $chunks ) < 4 ) {
			$chunks[] = array();
		}

		$translated_chunks = array();
		foreach ( $chunks as $chunk ) {
			$translated_chunk = self::translate_to_english( implode( ' ', $chunk ) );
			if ( is_wp_error( $translated_chunk ) ) {
				return $translated_chunk;
			}
			$translated_chunks[] = $translated_chunk;
		}

		$image_html        = '';
		$english_text_html = '<div class="text-to-image-api-text" style="font-size:11pt;line-height:1.6;font-family:Georgia,serif;margin:20px 0;">';

		foreach ( $translated_chunks as $index => $translated_chunk ) {
			if ( '' === $translated_chunk ) {
				continue;
			}

			$image_url = $persist_images ? self::create_media_image( $translated_chunk, $index ) : self::generate_image_url( $translated_chunk, $index );
			if ( is_wp_error( $image_url ) ) {
				return $image_url;
			}
			$image_html .= sprintf(
				'<div class="text-to-image-api-card" style="margin:20px 0;text-align:center;"><img src="%1$s" alt="%2$s" style="max-width:100%%;height:auto;border:1px solid #ddd;padding:10px;border-radius:4px;" /></div>',
				esc_url( $image_url ),
				esc_attr( sprintf( __( 'Translated text part %d', 'text-to-image-api' ), $index + 1 ) )
			);
			$english_text_html .= '<p>' . esc_html( $translated_chunk ) . '</p>';
		}

		$english_text_html .= '</div>';

		return array(
			'success'      => true,
			'images'       => $image_html,
			'english_text' => $english_text_html,
			'full_output'  => $image_html . $english_text_html,
			'remaining'    => $remaining_part,
		);
	}

	private static function split_into_sentences( $text ) {
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $sentences ) < 4 ) {
			$sentences = preg_split( '/\R/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		}

		$sentences = array_values( array_filter( array_map( 'trim', (array) $sentences ) ) );
		return $sentences ? $sentences : array( $text );
	}

	/**
	 * Splits at an author-supplied marker, or at a standalone, numbered "part 2"
	 * heading. The fallback works with any script because it does not rely on a
	 * translated word such as "part".
	 */
	private static function split_at_second_part( $text, $split_marker ) {
		if ( '' !== $split_marker ) {
			$marker_pattern = '/' . preg_quote( $split_marker, '/' ) . '/u';
			if ( preg_match( $marker_pattern, $text, $match, PREG_OFFSET_CAPTURE ) ) {
				return array(
					trim( substr( $text, 0, $match[0][1] ) ),
					trim( substr( $text, $match[0][1] ) ),
				);
			}
		}

		// Examples: PARTE 2, ЧАСТЬ 2, भाग 2, PART II, 第２部.
		$heading_pattern = '/(?:^|\\R)[\\p{Z}\\t]*(?:[\\p{L}\\p{M}][\\p{L}\\p{M}\\p{Z}\\t\'’.,:;!?()\\-–—]*)?[\\p{Z}\\t]*(?:2|II|Ⅱ|２)[\\p{Z}\\t.,:;!?()\\-–—]*?(?=\\R|$)/mu';
		if ( preg_match( $heading_pattern, $text, $match, PREG_OFFSET_CAPTURE ) ) {
			return array(
				trim( substr( $text, 0, $match[0][1] ) ),
				trim( substr( $text, $match[0][1] ) ),
			);
		}

		return array( $text, '' );
	}

	private static function translate_to_english( $text ) {
		if ( '' === $text ) {
			return '';
		}

		$cache_key = 't2i_trans_' . md5( $text );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$api_key = (string) get_option( self::OPTION_API_KEY, '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'openrouter_key_missing', __( 'OpenRouter API key is missing. Add it in Settings → Text to Image API.', 'text-to-image-api' ), array( 'status' => 424 ) );
		}

		$model = (string) get_option( self::OPTION_MODEL, 'google/gemini-2.5-flash-lite' );
		$model = '' === $model ? 'google/gemini-2.5-flash-lite' : $model;
		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => home_url( '/' ),
					'X-Title'       => 'Text to Image API',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'temperature' => 0,
						'messages'    => array(
							array(
								'role'    => 'system',
								'content' => 'Translate the user text into fluent, natural English. Detect the source language automatically. Return only the translation: no comments, labels, quotes, markdown, or HTML.',
							),
							array(
								'role'    => 'user',
								'content' => $text,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'translation_failed', __( 'OpenRouter could not be reached. The article was not changed.', 'text-to-image-api' ), array( 'status' => 424 ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$translated = isset( $data['choices'][0]['message']['content'] ) ? trim( wp_strip_all_tags( (string) $data['choices'][0]['message']['content'] ) ) : '';
		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) && '' !== $translated ) {
			set_transient( $cache_key, $translated, 30 * DAY_IN_SECONDS );
			return $translated;
		}

		return new WP_Error( 'translation_failed', __( 'OpenRouter did not return a translation. Check the API key, balance, and selected model.', 'text-to-image-api' ), array( 'status' => 424 ) );
	}

	/** Fetch JSON safely, with a native cURL fallback for restrictive WordPress hosts. */
	private static function remote_get_body( $url, $accept ) {
		$headers  = array(
			'Accept'     => $accept,
			'User-Agent' => 'Text-to-Image-API/' . self::VERSION,
		);
		$response = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return (string) wp_remote_retrieve_body( $response );
		}

		// Some WordPress installations block outbound requests in the HTTP API while
		// PHP's cURL extension is allowed. Try it before reporting a real failure.
		if ( ! function_exists( 'curl_init' ) ) {
			return '';
		}

		$curl = curl_init( $url );
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 8,
				CURLOPT_TIMEOUT        => 15,
				CURLOPT_USERAGENT      => 'Text-to-Image-API/' . self::VERSION,
				CURLOPT_HTTPHEADER     => array( 'Accept: ' . $accept ),
			)
		);
		$curl_body = curl_exec( $curl );
		// CURLINFO_RESPONSE_CODE is absent on older shared-hosting PHP/cURL stacks.
		// CURLINFO_HTTP_CODE provides the same status code and is widely supported.
		$curl_code = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
		curl_close( $curl );
		return 200 === $curl_code && is_string( $curl_body ) ? $curl_body : '';
	}

	private static function generate_image_url( $text, $index ) {
		$token = wp_generate_password( 32, false, false );
		set_transient(
			't2i_image_' . $token,
			array(
				'text'  => $text,
				'index' => (int) $index,
			),
			self::IMAGE_TOKEN_TTL
		);

		return add_query_arg(
			array(
				'text2image_generate' => '1',
				'token'               => $token,
			),
			home_url( '/' )
		);
	}

	/** Create a permanent Media Library file instead of a temporary preview URL. */
	private static function create_media_image( $text, $index, $post_id = 0, $is_next = false ) {
		if ( $is_next ) {
			return self::create_next_svg_media_image( $text, $index, $post_id );
		}
		if ( self::requires_svg_renderer( $text ) ) {
			return self::create_svg_media_image( $text, $index, $post_id );
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return new WP_Error( 'gd_missing', __( 'The PHP GD extension is required to generate images.', 'text-to-image-api' ), array( 'status' => 500 ) );
		}

		$image  = self::create_image_resource( $text, $index );
		$img_w  = imagesx( $image );
		$img_h  = imagesy( $image );
		$png    = self::to_gray_png( $image );
		imagedestroy( $image );
		if ( empty( $png ) ) {
			return new WP_Error( 'image_generation_failed', __( 'The image could not be generated.', 'text-to-image-api' ), array( 'status' => 500 ) );
		}

		$filename = 'text-to-image-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false ) . '.png';
		$upload   = wp_upload_bits( $filename, null, $png );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'media_upload_failed', $upload['error'], array( 'status' => 500 ) );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => sprintf( __( 'Protected text image %d', 'text-to-image-api' ), $index + 1 ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			(int) $post_id
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// No resized copies: cards are only shown full size (saves ~88% disk and CPU on publish).
		wp_update_attachment_metadata( $attachment_id, self::plain_metadata( $upload['file'], $img_w, $img_h ) );
		update_post_meta( $attachment_id, '_t2i_optimized', (string) self::GRAY_LEVELS );
		// Some sites filter wp_get_attachment_url() into an attachment-post permalink.
		// The upload result is the original, direct PNG URL and must be used in <img src>.
		return esc_url_raw( $upload['url'] );
	}

	/** Render a .nextjs1 block as a clean, large-type CTA card. */
	private static function create_next_svg_media_image( $text, $index, $post_id = 0 ) {
		$width     = 1200;
		$font_size = 76;
		$line_step = 112;
		$lines     = self::wrap_text_for_svg( $text, 20 );
		$height    = max( 320, 150 + ( $line_step * count( $lines ) ) );
		$font_file = self::svg_font_file( $text );
		$font_url  = 'NotoSansFull.ttf' === $font_file
			? plugins_url( $font_file, __FILE__ )
			: plugins_url( 'fonts/' . $font_file, __FILE__ );
		$spans     = '';
		$y         = max( 110, (int) ( ( $height - ( $line_step * ( count( $lines ) - 1 ) ) ) / 2 ) );
		foreach ( $lines as $line ) {
			if ( '' !== $line ) {
				$spans .= '<tspan x="600" y="' . (int) $y . '">' . esc_html( $line ) . '</tspan>';
			}
			$y += $line_step;
		}

		$svg = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $width . '" height="' . (int) $height . '" viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '">'
			. '<defs><style>@font-face{font-family:T2I;src:url(' . esc_url( $font_url ) . ') format("truetype");}</style></defs>'
			. '<rect width="100%" height="100%" fill="#fff"/>'
			. '<text xml:space="preserve" x="600" y="120" fill="#ff3030" font-family="T2I, sans-serif" font-size="' . (int) $font_size . '" font-weight="800" text-anchor="middle">' . $spans . '</text>'
			. '</svg>';

		$filename = 'text-to-image-next-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false ) . '.svg';
		$upload   = self::upload_generated_svg( $filename, $svg );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'media_upload_failed', $upload['error'], array( 'status' => 500 ) );
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/svg+xml',
				'post_title'     => sprintf( __( 'Protected text CTA %d', 'text-to-image-api' ), $index + 1 ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			(int) $post_id
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}
		return esc_url_raw( $upload['url'] );
	}

	/**
	 * GD/FreeType cannot shape Arabic, Indic and several other complex scripts.
	 * SVG is rendered by the visitor's browser shaping engine, giving correct glyph
	 * joining and positioning while remaining a responsive image element.
	 */
	private static function requires_svg_renderer( $text ) {
		return (bool) preg_match( '/[\x{0530}-\x{058F}\x{0590}-\x{08FF}\x{0900}-\x{0DFF}\x{1000}-\x{109F}\x{1200}-\x{139F}\x{1780}-\x{17FF}\x{1800}-\x{18AF}\x{2E80}-\x{9FFF}]/u', $text );
	}

	private static function create_svg_media_image( $text, $index, $post_id = 0 ) {
		$width     = 1200;
		$padding   = 50;
		$font_size = 32;
		$line_step = 68;
		$lines     = self::wrap_text_for_svg( $text, 40 );
		$height    = max( 180, $line_step * count( $lines ) + 100 );
		$rtl       = (bool) preg_match( '/[\x{0590}-\x{08FF}]/u', $text );
		$x         = $rtl ? $width - $padding : $padding;
		$anchor    = $rtl ? 'end' : 'start';
		$direction = $rtl ? 'rtl' : 'ltr';
		$font_file = self::svg_font_file( $text );
		$font_url  = 'NotoSansFull.ttf' === $font_file
			? plugins_url( $font_file, __FILE__ )
			: plugins_url( 'fonts/' . $font_file, __FILE__ );
		$spans     = '';
		$y         = 70;
		foreach ( $lines as $line ) {
			if ( '' !== $line ) {
				$spans .= '<tspan x="' . (int) $x . '" y="' . (int) $y . '">' . esc_html( $line ) . '</tspan>';
			}
			$y += $line_step;
		}
		$svg = '<?xml version="1.0" encoding="UTF-8"?>'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $width . '" height="' . (int) $height . '" viewBox="0 0 ' . (int) $width . ' ' . (int) $height . '">'
			. '<style>@font-face{font-family:T2I;src:url(' . esc_url( $font_url ) . ') format("truetype");}</style>'
			. '<rect width="100%" height="100%" fill="#ffffff"/>'
			. '<text xml:space="preserve" x="' . (int) $x . '" y="70" fill="#111111" font-family="T2I, sans-serif" font-size="' . (int) $font_size . '" direction="' . $direction . '" unicode-bidi="plaintext" text-anchor="' . $anchor . '">' . $spans . '</text>'
			. '</svg>';

		$filename = 'text-to-image-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false ) . '.svg';
		$upload   = self::upload_generated_svg( $filename, $svg );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'media_upload_failed', $upload['error'], array( 'status' => 500 ) );
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/svg+xml',
				'post_title'     => sprintf( __( 'Protected text image %d', 'text-to-image-api' ), $index + 1 ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			(int) $post_id
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return esc_url_raw( $upload['url'] );
	}

	/**
	 * wp_upload_bits() validates file extensions against the site's allowed MIME
	 * list. SVG is intentionally absent from WordPress defaults, but these files
	 * are generated by this plugin itself, not supplied by an editor. Temporarily
	 * enabling it here lets complex-language and CTA cards reach the Media Library
	 * without opening ordinary editor uploads to SVG files.
	 */
	private static function upload_generated_svg( $filename, $svg ) {
		add_filter( 'upload_mimes', array( __CLASS__, 'allow_generated_svg_mime' ), 999 );
		add_filter( 'mime_types', array( __CLASS__, 'allow_generated_svg_mime' ), 999 );
		$upload = wp_upload_bits( $filename, null, $svg );
		remove_filter( 'upload_mimes', array( __CLASS__, 'allow_generated_svg_mime' ), 999 );
		remove_filter( 'mime_types', array( __CLASS__, 'allow_generated_svg_mime' ), 999 );
		return $upload;
	}

	public static function allow_generated_svg_mime( $mimes ) {
		$mimes['svg'] = 'image/svg+xml';
		return $mimes;
	}

	/** Select a bundled font for every non-Latin script supported by the pipeline. */
	private static function svg_font_file( $text ) {
		$fonts = array(
			'/[\x{0600}-\x{08FF}]/u' => 'NotoNaskhArabic.ttf', // Farsi, Pashto, Kurdish, Sindhi, Saraiki, Arabic Hausa.
			'/[\x{1200}-\x{139F}]/u' => 'NotoSansEthiopic.ttf', // Amharic, Tigrinya.
			'/[\x{0900}-\x{097F}]/u' => 'NotoSansDevanagari.ttf', // Bhojpuri, Maithili, Nepali, Magahi, Chhattisgarhi, Haryanvi.
			'/[\x{0980}-\x{09FF}]/u' => 'NotoSansBengali.ttf', // Assamese.
			'/[\x{0B00}-\x{0B7F}]/u' => 'NotoSansOriya.ttf', // Odia.
			'/[\x{1000}-\x{109F}]/u' => 'NotoSansMyanmar.ttf', // Burmese.
			'/[\x{0D80}-\x{0DFF}]/u' => 'NotoSansSinhala.ttf', // Sinhala.
			'/[\x{1780}-\x{17FF}]/u' => 'NotoSansKhmer.ttf', // Khmer.
			'/[\x{0530}-\x{058F}]/u' => 'NotoSansArmenian.ttf', // Armenian.
			'/[\x{2E80}-\x{9FFF}]/u' => 'NotoSansSC.ttf', // Zhuang written with Han characters.
		);
		foreach ( $fonts as $pattern => $font ) {
			if ( preg_match( $pattern, $text ) ) {
				return $font;
			}
		}
		return 'NotoSansFull.ttf';
	}

	private static function wrap_text_for_svg( $text, $max_characters ) {
		$lines = array();
		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) ) as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				$lines[] = '';
				continue;
			}
			$line  = '';
			$words = preg_split( '/\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $words as $word ) {
				$candidate = '' === $line ? $word : $line . ' ' . $word;
				if ( '' !== $line && self::text_length( $candidate ) > $max_characters ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = $candidate;
				}
			}
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return $lines ? $lines : array( '' );
	}

	public static function serve_image() {
		if ( ! isset( $_GET['text2image_generate'], $_GET['token'] ) || '1' !== (string) $_GET['text2image_generate'] ) {
			return;
		}

		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['token'] ) );
		$data  = get_transient( 't2i_image_' . $token );
		if ( ! is_array( $data ) || empty( $data['text'] ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'Image has expired. Generate it again.', 'text-to-image-api' ), '', array( 'response' => 404 ) );
		}

		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			status_header( 500 );
			wp_die( esc_html__( 'The PHP GD extension is required to generate images.', 'text-to-image-api' ), '', array( 'response' => 500 ) );
		}

		self::render_image( (string) $data['text'], (int) $data['index'] );
	}

	private static function create_image_resource( $text, $index ) {
		// The original bundled font only covers Latin, Greek and Cyrillic.
		// Select the Georgian Noto font for Georgian text so GD never renders it as squares.
		$font_file = preg_match( '/[\x{10A0}-\x{10FF}]/u', $text )
			? 'NotoSansGeorgian.ttf'
			: 'NotoSansFull.ttf';
		$font_path = plugin_dir_path( __FILE__ ) . $font_file;
		$font_size = 32;
		$width     = 1200;
		$padding   = 50;
		$lines     = self::wrap_text_for_image( $text, $font_path, $font_size, $width - ( $padding * 2 ) );
		$height    = max( 180, 68 * count( $lines ) + 100 );
		$image     = imagecreatetruecolor( $width, $height );
		$white     = imagecolorallocate( $image, 255, 255, 255 );
		$black     = imagecolorallocate( $image, 0, 0, 0 );
		$gray      = imagecolorallocate( $image, 150, 150, 150 );

		imagefilledrectangle( $image, 0, 0, $width, $height, $white );
		if ( file_exists( $font_path ) && function_exists( 'imagettftext' ) ) {
			$y = 70;
			foreach ( $lines as $line ) {
				imagettftext( $image, $font_size, 0, $padding, $y, $black, $font_path, $line );
				$y += 68;
			}
		} else {
			$y = 40;
			foreach ( $lines as $line ) {
				imagestring( $image, 5, 20, $y, $line, $black );
				$y += 35;
			}
		}

		return $image;
	}

	/**
	 * Wrap on measured pixel width, never UTF-8 byte length.
	 * wordwrap() corrupts layout for multibyte scripts such as Georgian.
	 */
	private static function wrap_text_for_image( $text, $font_path, $font_size, $max_width ) {
		if ( ! file_exists( $font_path ) || ! function_exists( 'imagettfbbox' ) ) {
			return explode( "\n", wordwrap( $text, 90, "\n", true ) );
		}

		$lines = array();
		foreach ( explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) ) as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				$lines[] = '';
				continue;
			}
			$line  = '';
			$words = preg_split( '/\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( $words as $word ) {
				$candidate = '' === $line ? $word : $line . ' ' . $word;
				$bounds    = imagettfbbox( $font_size, 0, $font_path, $candidate );
				$text_width = $bounds ? abs( $bounds[2] - $bounds[0] ) : 0;
				if ( '' !== $line && $text_width > $max_width ) {
					$lines[] = $line;
					$line    = $word;
				} else {
					$line = $candidate;
				}
			}
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines ? $lines : array( '' );
	}

	private static function render_image( $text, $index ) {
		$image = self::create_image_resource( $text, $index );
		nocache_headers();
		header( 'Content-Type: image/png' );
		imagepng( $image );
		imagedestroy( $image );
		exit;
	}
}

Text_To_Image_API::init();

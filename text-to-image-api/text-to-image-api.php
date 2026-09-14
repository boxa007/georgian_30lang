<?php
/**
 * Plugin Name: Text to Image API
 * Description: Converts selected HTML blocks into crawler-inaccessible image text.
 * Version: 2.0.0
 * Author: boxa007
 * License: GPL-2.0-or-later
 * Text Domain: text-to-image-api
 * Update URI: https://github.com/boxa007/deepseek
 */

defined( 'ABSPATH' ) || exit;

final class Text_To_Image_API {
	const VERSION            = '2.0.0';
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
		add_action( 'save_post_post', array( __CLASS__, 'process_protected_blocks' ), 20, 3 );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_github_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'github_plugin_information' ), 20, 3 );
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
			$text = trim( preg_replace( '/\s+/u', ' ', (string) $node->textContent ) );
			if ( '' === $text ) {
				continue;
			}

			while ( $node->firstChild ) {
				$node->removeChild( $node->firstChild );
			}
			$parts = self::split_text_for_images( $text );
			foreach ( $parts as $part ) {
				$image_url = self::create_media_image( $part, $image_index, $post_id );
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

			$node->setAttribute( 'data-t2i-rendered', '1' );
		}

		return self::inner_html( $root );
	}

	private static function is_protected_node( DOMNode $node ) {
		if ( ! $node instanceof DOMElement ) {
			return false;
		}
		$classes = preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) );
		return (bool) array_intersect( array( 'imgjs1', 'nextjs1' ), (array) $classes );
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
		$sentences = preg_split( '/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$parts     = array();
		$current   = '';
		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( self::text_length( $sentence ) > self::MAX_BLOCK_TEXT_LENGTH ) {
				if ( '' !== $current ) {
					$parts[] = $current;
					$current = '';
				}
				$parts = array_merge( $parts, self::hard_split_text( $sentence ) );
				continue;
			}
			if ( '' !== $current && self::text_length( $current . ' ' . $sentence ) > self::MAX_BLOCK_TEXT_LENGTH ) {
				$parts[] = $current;
				$current = '';
			}
			$current = trim( $current . ' ' . $sentence );
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
	private static function create_media_image( $text, $index, $post_id = 0 ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			return new WP_Error( 'gd_missing', __( 'The PHP GD extension is required to generate images.', 'text-to-image-api' ), array( 'status' => 500 ) );
		}

		$image = self::create_image_resource( $text, $index );
		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
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

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		return wp_get_attachment_url( $attachment_id );
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
		$font_path = plugin_dir_path( __FILE__ ) . 'noto-sans-regular.ttf';
		$lines     = explode( "\n", wordwrap( $text, 42, "\n", true ) );
		$width     = 800;
		$height    = max( 150, 52 * count( $lines ) + 90 );
		$image     = imagecreatetruecolor( $width, $height );
		$white     = imagecolorallocate( $image, 255, 255, 255 );
		$black     = imagecolorallocate( $image, 0, 0, 0 );
		$gray      = imagecolorallocate( $image, 150, 150, 150 );

		imagefilledrectangle( $image, 0, 0, $width, $height, $white );
		if ( file_exists( $font_path ) && function_exists( 'imagettftext' ) ) {
			$y = 60;
			foreach ( $lines as $line ) {
				imagettftext( $image, 24, 0, 40, $y, $black, $font_path, $line );
				$y += 50;
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

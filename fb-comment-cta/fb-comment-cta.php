<?php
/**
 * Plugin Name: FB Comment CTA
 * Description: At the end of part 4 of a story shows an editorial request to comment on Facebook, with 3 ready-made comments unique to each visitor (generated centrally in n8n). Counts views and clicks per call-to-action variant.
 * Version: 1.1.1
 * Update URI: https://github.com/boxa007/georgian_30lang
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

class FB_Comment_CTA {
	const VERSION    = '1.1.1';
	const VAR_OPT    = 'fbc_variants';
	const REPO       = 'boxa007/georgian_30lang';
	const TAG_PREFIX = 'fbc-v';
	const OPT        = 'fbc_settings';
	/* The block n8n appends to every part 4: «Missed the beginning? ← Read the story from part one». */
	const MARKER     = '<div style="max-width:640px;margin:40px auto 0;';

	private $printed = false;

	public function __construct() {
		add_filter( 'the_content', array( $this, 'inject' ), 50 );
		/* Fallback for themes that print the post outside the standard loop: print the block in the footer, JS moves it into place. */
		add_action( 'wp_footer', array( $this, 'footer_fallback' ), 5 );
		add_shortcode( 'fb_comment_cta', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		/* Call-to-action texts live in this site's database; they are pulled from the central table
		   on activation, by the «update on all sites» hook (REST /fbc/v1/refresh) or by the button in Settings. */
		add_action( 'rest_api_init', array( $this, 'rest_routes' ) );
		add_action( 'admin_post_fbc_pull', array( $this, 'admin_pull' ) );
		add_action( 'init', array( $this, 'first_pull' ), 30 );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
		/* Self-update from GitHub releases (tags fbc-vX.Y.Z). */
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'auto_update_plugin', array( $this, 'auto_update' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'keep_folder_name' ), 10, 4 );
		add_action( 'admin_post_fbc_update_from_github', array( $this, 'update_from_github' ) );
		add_action( 'admin_notices', array( $this, 'render_update_notice' ) );
	}

	/* ---------- call-to-action texts (stored in WP) ---------- */

	public static function activate() {
		$self = new self();
		$self->pull_variants( false );
	}

	public function variants() {
		$v = get_option( self::VAR_OPT, array() );
		return ( is_array( $v ) && ! empty( $v['list'] ) && is_array( $v['list'] ) ) ? $v['list'] : array();
	}

	/** Download the active texts from n8n and store them in this site's database. */
	public function pull_variants( $by_hook ) {
		$s   = $this->settings();
		$url = add_query_arg( array(
			'site'    => wp_parse_url( home_url(), PHP_URL_HOST ),
			'url'     => home_url(),
			'v'       => self::VERSION,
			'lang'    => $s['lang'],
			'refresh' => $by_hook ? '1' : '0',
		), $s['api'] . '/cta-variants' );
		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['ok'] ) || ! isset( $data['variants'] ) || ! is_array( $data['variants'] ) ) {
			return 'bad response';
		}
		$list = array();
		foreach ( $data['variants'] as $v ) {
			if ( empty( $v['nr'] ) || empty( $v['title'] ) || empty( $v['body'] ) ) {
				continue;
			}
			$list[] = array(
				'lang'  => sanitize_key( isset( $v['lang'] ) ? $v['lang'] : 'ru' ),
				'nr'    => (int) $v['nr'],
				'title' => sanitize_text_field( $v['title'] ),
				'body'  => sanitize_textarea_field( $v['body'] ),
			);
		}
		update_option( self::VAR_OPT, array( 'updated' => time(), 'list' => $list ) );
		return count( $list );
	}

	/* Sites updated from 1.0.x have no stored texts yet: pull once (and register for the hook). */
	public function first_pull() {
		if ( false !== get_option( self::VAR_OPT, false ) || get_transient( 'fbc_pull_lock' ) ) {
			return;
		}
		set_transient( 'fbc_pull_lock', 1, 10 * MINUTE_IN_SECONDS );
		$this->pull_variants( false );
	}

	public function rest_routes() {
		register_rest_route( 'fbc/v1', '/refresh', array(
			'methods'             => array( 'GET', 'POST' ),
			'permission_callback' => '__return_true', // only triggers a download from the fixed central address
			'callback'            => array( $this, 'rest_refresh' ),
		) );
	}

	public function rest_refresh() {
		if ( get_transient( 'fbc_refresh_lock' ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'count' => count( $this->variants() ), 'throttled' => true ), 200 );
		}
		set_transient( 'fbc_refresh_lock', 1, 15 );
		$r = $this->pull_variants( true );
		if ( ! is_int( $r ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'error' => $r ), 502 );
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all(); // pages may hold the old texts in cache
		}
		return new WP_REST_Response( array( 'ok' => true, 'count' => $r ), 200 );
	}

	public function admin_pull() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No permission.' );
		}
		check_admin_referer( 'fbc_pull' );
		$r = $this->pull_variants( true );
		set_transient( 'fbc_update_notice_' . get_current_user_id(), is_int( $r ) ? 'FB Comment CTA: загружено призывов — ' . $r . '.' : 'FB Comment CTA: не удалось загрузить призывы (' . $r . ').', MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'options-general.php?page=fb-comment-cta' ) );
		exit;
	}

	/* ---------- settings ---------- */

	public static function langs() {
		return array( 'ru' => 'Русский', 'uk' => 'Українська', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'pt' => 'Português', 'it' => 'Italiano', 'pl' => 'Polski', 'ro' => 'Română', 'hu' => 'Magyar', 'cs' => 'Čeština', 'bg' => 'Български', 'tr' => 'Türkçe' );
	}

	public function settings() {
		$s = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array(
			'enabled'    => '1',
			'only_admin' => '0',
			'lang'       => 'ru',
			'fb_page'    => '',
			'avatar'     => '',
			'api'        => 'https://kuts.air2.top/webhook',
			'counts'     => '1',
			'likes'      => '50-90',
			'comments'   => '40-80',
		) );
	}

	public function register_settings() {
		register_setting( 'fbc', self::OPT, array( $this, 'sanitize' ) );
	}

	public function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array(
			'enabled'    => empty( $in['enabled'] ) ? '0' : '1',
			'only_admin' => empty( $in['only_admin'] ) ? '0' : '1',
			'lang'       => isset( $in['lang'], self::langs()[ $in['lang'] ] ) ? $in['lang'] : 'ru',
			'fb_page'    => esc_url_raw( isset( $in['fb_page'] ) ? trim( $in['fb_page'] ) : '' ),
			'avatar'     => esc_url_raw( isset( $in['avatar'] ) ? trim( $in['avatar'] ) : '' ),
			'api'        => esc_url_raw( isset( $in['api'] ) && trim( $in['api'] ) ? untrailingslashit( trim( $in['api'] ) ) : 'https://kuts.air2.top/webhook' ),
			'counts'     => empty( $in['counts'] ) ? '0' : '1',
			'likes'      => self::range_str( isset( $in['likes'] ) ? $in['likes'] : '', '50-90' ),
			'comments'   => self::range_str( isset( $in['comments'] ) ? $in['comments'] : '', '40-80' ),
		);
		return $out;
	}

	private static function range_str( $v, $def ) {
		return preg_match( '/^\s*(\d{1,6})\s*-\s*(\d{1,6})\s*$/', (string) $v, $m ) && (int) $m[1] <= (int) $m[2] ? $m[1] . '-' . $m[2] : $def;
	}

	/** Same numbers for the same article on every visit (seeded by its address). */
	private static function pick( $range, $seed ) {
		list( $a, $b ) = array_map( 'intval', explode( '-', $range ) );
		return $a + ( abs( crc32( $seed ) ) % ( $b - $a + 1 ) );
	}

	public function admin_menu() {
		add_options_page( 'FB Comment CTA', 'FB Comment CTA', 'manage_options', 'fb-comment-cta', array( $this, 'settings_page' ) );
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=fb-comment-cta' ) ) . '">Settings</a>' );
		if ( current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fbc_update_from_github' ), 'fbc_update_from_github' ) ) . '">Update from GitHub</a>';
		}
		return $links;
	}

	public function settings_page() {
		$s = $this->settings();
		$n = self::OPT;
		?>
		<div class="wrap">
			<h1>FB Comment CTA <small style="font-weight:normal">v<?php echo esc_html( self::VERSION ); ?></small></h1>
			<p>Блок «Обращение от редакции» с 3 готовыми комментариями выводится в конце каждой ЧАСТИ 4, перед ссылкой «Читать историю с первой части». В любое другое место можно поставить шорткод <code>[fb_comment_cta]</code>.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'fbc' ); ?>
				<table class="form-table">
					<tr><th>Включено</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $s['enabled'], '1' ); ?>> показывать блок</label></td></tr>
					<tr><th>Тестовый режим</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[only_admin]" value="1" <?php checked( $s['only_admin'], '1' ); ?>> показывать только администраторам (или по ссылке с <code>?fbcta=1</code>)</label></td></tr>
					<tr><th>Язык блока</th><td><select name="<?php echo esc_attr( $n ); ?>[lang]">
						<?php foreach ( self::langs() as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['lang'], $code ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select><p class="description">Язык надписей, призыва и готовых комментариев.</p></td></tr>
					<tr><th>Страница Facebook</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $n ); ?>[fb_page]" value="<?php echo esc_attr( $s['fb_page'] ); ?>" placeholder="https://www.facebook.com/..."><p class="description">Запасной адрес: используется, если в Baserow для статьи нет ни fb_post_url, ни FB Page URL.</p></td></tr>
					<tr><th>Аватар</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $n ); ?>[avatar]" value="<?php echo esc_attr( $s['avatar'] ); ?>" placeholder="(по умолчанию — общий аватар редакции)"></td></tr>
					<tr><th>Счётчики</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[counts]" value="1" <?php checked( $s['counts'], '1' ); ?>> показывать строку с лайками и комментариями</label><br>
						лайки <input type="text" size="8" name="<?php echo esc_attr( $n ); ?>[likes]" value="<?php echo esc_attr( $s['likes'] ); ?>"> &nbsp; комментарии <input type="text" size="8" name="<?php echo esc_attr( $n ); ?>[comments]" value="<?php echo esc_attr( $s['comments'] ); ?>">
						<p class="description">Диапазон «от-до». Для каждой статьи число постоянное (не меняется при обновлении страницы).</p></td></tr>
					<tr><th>API</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $n ); ?>[api]" value="<?php echo esc_attr( $s['api'] ); ?>"></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php
			$opt  = get_option( self::VAR_OPT, array() );
			$list = $this->variants();
			?>
			<h2>Призывы на этом сайте (<?php echo (int) count( $list ); ?>)</h2>
			<p>Обновлены: <?php echo ! empty( $opt['updated'] ) ? esc_html( wp_date( 'd.m.Y H:i', $opt['updated'] ) ) : 'ещё не загружались'; ?>.
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fbc_pull' ), 'fbc_pull' ) ); ?>">Обновить призывы сейчас</a></p>
			<p class="description">Тексты берутся из таблицы «Призывы» в Baserow. Обновить на всех сайтах сразу: <code><?php echo esc_html( $s['api'] . '/cta-push' ); ?></code></p>
			<table class="widefat striped" style="max-width:900px"><thead><tr><th>№</th><th>Язык</th><th>Заголовок</th><th>Текст</th></tr></thead><tbody>
			<?php foreach ( $list as $v ) : ?>
				<tr><td><?php echo (int) $v['nr']; ?></td><td><?php echo esc_html( $v['lang'] ); ?></td><td><?php echo esc_html( $v['title'] ); ?></td><td><?php echo esc_html( $v['body'] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	/* ---------- front end ---------- */

	private function visible() {
		$s = $this->settings();
		if ( '1' !== $s['enabled'] || is_admin() || is_feed() || ! is_singular( 'post' ) ) {
			return false;
		}
		if ( '1' === $s['only_admin'] && ! current_user_can( 'manage_options' ) && empty( $_GET['fbcta'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		return true;
	}

	public function inject( $content ) {
		/* Skip early calls (excerpts / SEO descriptions built in <head>) — they would "use up" the block. */
		if ( $this->printed || ! did_action( 'wp_head' ) || doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' ) || ! $this->visible() || (int) get_the_ID() !== (int) get_queried_object_id() ) {
			return $content;
		}
		$pos = strrpos( $content, self::MARKER );
		if ( false === $pos ) {
			return $content; // not a part 4
		}
		return substr( $content, 0, $pos ) . $this->block( $content ) . substr( $content, $pos );
	}

	public function footer_fallback() {
		/* Always printed for a part 4: if the in-content block is on the page, JS removes this copy. */
		if ( ! $this->visible() ) {
			return;
		}
		$qo = get_queried_object();
		if ( ! $qo || empty( $qo->post_content ) || false === strpos( $qo->post_content, self::MARKER ) ) {
			return;
		}
		$this->printed = false;
		global $post;
		$saved = $post;
		$post  = get_queried_object(); // the footer may run after a "related posts" loop
		setup_postdata( $post );
		echo $this->block( $post->post_content, true ); // phpcs:ignore
		$post = $saved;
		if ( $saved ) {
			setup_postdata( $saved );
		}
	}

	public function shortcode() {
		if ( $this->printed || ! $this->visible() ) {
			return '';
		}
		$post = get_post();
		return $this->block( $post ? apply_filters( 'fbc_story_source', $post->post_content ) : '' );
	}

	private function block( $content, $move = false ) {
		$this->printed = true;
		$s    = $this->settings();
		$t    = self::texts( $s['lang'] );
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $content ) ) ) );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > 6000 ) {
			$text = mb_substr( $text, -6000 ); // the ending is what readers react to
		}
		$mine = array();
		foreach ( $this->variants() as $v ) {
			if ( $v['lang'] === $s['lang'] ) {
				$mine[] = array( 'nr' => $v['nr'], 'title' => $v['title'], 'body' => $v['body'] );
			}
		}
		if ( ! $mine ) {
			foreach ( $this->variants() as $v ) {
				if ( 'ru' === $v['lang'] ) {
					$mine[] = array( 'nr' => $v['nr'], 'title' => $v['title'], 'body' => $v['body'] );
				}
			}
		}
		$data = array(
			'variants' => $mine,
			'api'    => $s['api'],
			'url'    => get_permalink(),
			'site'   => wp_parse_url( home_url(), PHP_URL_HOST ),
			'lang'   => $s['lang'],
			'title'  => get_the_title(),
			'text'   => $text,
			'fbPage' => $s['fb_page'],
			't'      => $t,
		);
		$avatar = $s['avatar'] ? $s['avatar'] : plugins_url( 'assets/avatar.jpg', __FILE__ );
		$copy   = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M8 3h9a3 3 0 0 1 3 3v9a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3zm0 2a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1H8zM3 8a1 1 0 0 1 1 1v9a2 2 0 0 0 2 2h9a1 1 0 1 1 0 2H6a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1z"/></svg>';
		ob_start();
		?>
		<div class="fbcta" data-fbcta="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"<?php echo $move ? ' data-move="1"' : ''; ?> hidden>
			<div class="fbcta-head">
				<img class="fbcta-avatar" src="<?php echo esc_url( $avatar ); ?>" alt="" width="88" height="88" loading="lazy">
				<div><div class="fbcta-title"></div><div class="fbcta-sub"><?php echo esc_html( $t['from'] ); ?></div></div>
			</div>
			<p class="fbcta-body"></p>
			<hr class="fbcta-hr">
			<div class="fbcta-q"><?php echo esc_html( $t['q'] ); ?></div>
			<div class="fbcta-hint"><?php echo $copy; // phpcs:ignore ?> <?php echo esc_html( $t['hint'] ); ?></div>
			<div class="fbcta-or"><?php echo esc_html( $t['or'] ); ?></div>
			<div class="fbcta-list"></div>
			<div class="fbcta-fb"><span class="fbcta-f">f</span> <?php echo esc_html( $t['disc'] ); ?></div>
			<?php if ( '1' === $s['counts'] ) : $seed = (string) get_permalink(); ?>
			<div class="fbcta-counts"><span>👍💗 <?php echo esc_html( number_format_i18n( self::pick( $s['likes'], $seed . '#l' ) ) ); ?></span><span>💬 <?php echo esc_html( number_format_i18n( self::pick( $s['comments'], $seed . '#c' ) ) . ' ' . $t['cmts'] ); ?></span></div>
			<?php endif; ?>
			<a class="fbcta-btn" href="#" target="_blank" rel="noopener nofollow">💬 <?php echo esc_html( $t['btn'] ); ?></a>
			<div class="fbcta-foot"><?php echo esc_html( $t['foot'] ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function assets() {
		if ( ! $this->visible() ) {
			return;
		}
		wp_enqueue_style( 'fbcta', plugins_url( 'assets/fbcta.css', __FILE__ ), array(), self::VERSION );
		wp_enqueue_script( 'fbcta', plugins_url( 'assets/fbcta.js', __FILE__ ), array(), self::VERSION, true );
	}

	public static function texts( $lang ) {
		$all = array(
			'ru' => array( 'nocomm' => 'Перейдите в Facebook, поставьте лайк и оставьте свой комментарий — нам очень важно ваше мнение!', 'cmts' => 'комментариев', 'from' => 'Обращение от редакции', 'q' => 'Какое мнение вам ближе?', 'hint' => 'Можете скопировать эти варианты 👇', 'or' => 'Или написать своё мнение в Facebook. Нам очень важно знать, что вы думаете!', 'disc' => 'Обсуждение в Facebook', 'btn' => 'Высказать своё мнение', 'foot' => 'Для вас — несколько секунд, для нас — огромная помощь! 💗', 'copied' => 'Скопировано! Вставьте в комментарий на Facebook', 'copy' => 'Скопировать' ),
			'uk' => array( 'nocomm' => 'Перейдіть у Facebook, поставте лайк і залиште свій коментар — нам дуже важлива ваша думка!', 'cmts' => 'коментарів', 'from' => 'Звернення від редакції', 'q' => 'Яка думка вам ближча?', 'hint' => 'Можете скопіювати ці варіанти 👇', 'or' => 'Або напишіть свою думку у Facebook. Нам дуже важливо знати, що ви думаєте!', 'disc' => 'Обговорення у Facebook', 'btn' => 'Висловити свою думку', 'foot' => 'Для вас — кілька секунд, для нас — величезна допомога! 💗', 'copied' => 'Скопійовано! Вставте в коментар у Facebook', 'copy' => 'Скопіювати' ),
			'en' => array( 'nocomm' => 'Go to Facebook, like the post and leave your comment — your opinion really matters to us!', 'cmts' => 'comments', 'from' => 'A note from the editors', 'q' => 'Which opinion is closer to yours?', 'hint' => 'You can copy these options 👇', 'or' => 'Or write your own opinion on Facebook. We really want to know what you think!', 'disc' => 'Discussion on Facebook', 'btn' => 'Share your opinion', 'foot' => 'A few seconds for you, a huge help for us! 💗', 'copied' => 'Copied! Paste it into a Facebook comment', 'copy' => 'Copy' ),
			'de' => array( 'nocomm' => 'Gehen Sie zu Facebook, geben Sie ein Like und hinterlassen Sie Ihren Kommentar — Ihre Meinung ist uns sehr wichtig!', 'cmts' => 'Kommentare', 'from' => 'Ein Wort der Redaktion', 'q' => 'Welche Meinung teilen Sie eher?', 'hint' => 'Sie können diese Varianten kopieren 👇', 'or' => 'Oder schreiben Sie Ihre eigene Meinung auf Facebook. Es ist uns sehr wichtig, was Sie denken!', 'disc' => 'Diskussion auf Facebook', 'btn' => 'Meinung sagen', 'foot' => 'Für Sie ein paar Sekunden, für uns eine riesige Hilfe! 💗', 'copied' => 'Kopiert! Fügen Sie es in einen Facebook-Kommentar ein', 'copy' => 'Kopieren' ),
			'fr' => array( 'nocomm' => 'Rendez-vous sur Facebook, aimez la publication et laissez votre commentaire — votre avis compte beaucoup pour nous !', 'cmts' => 'commentaires', 'from' => 'Un mot de la rédaction', 'q' => 'Quel avis vous ressemble le plus ?', 'hint' => 'Vous pouvez copier ces propositions 👇', 'or' => 'Ou écrivez votre propre avis sur Facebook. Nous tenons vraiment à savoir ce que vous pensez !', 'disc' => 'Discussion sur Facebook', 'btn' => 'Donner mon avis', 'foot' => 'Quelques secondes pour vous, une aide énorme pour nous ! 💗', 'copied' => 'Copié ! Collez-le dans un commentaire Facebook', 'copy' => 'Copier' ),
			'es' => array( 'nocomm' => 'Vaya a Facebook, dé me gusta y deje su comentario: ¡su opinión es muy importante para nosotros!', 'cmts' => 'comentarios', 'from' => 'Mensaje de la redacción', 'q' => '¿Qué opinión se parece más a la suya?', 'hint' => 'Puede copiar estas opciones 👇', 'or' => 'O escriba su propia opinión en Facebook. ¡Nos importa mucho lo que piensa!', 'disc' => 'Debate en Facebook', 'btn' => 'Dar mi opinión', 'foot' => '¡Para usted, unos segundos; para nosotros, una ayuda enorme! 💗', 'copied' => '¡Copiado! Péguelo en un comentario de Facebook', 'copy' => 'Copiar' ),
			'pt' => array( 'nocomm' => 'Vá ao Facebook, dê um gosto e deixe o seu comentário — a sua opinião é muito importante para nós!', 'cmts' => 'comentários', 'from' => 'Mensagem da redação', 'q' => 'Qual opinião é mais parecida com a sua?', 'hint' => 'Pode copiar estas opções 👇', 'or' => 'Ou escreva a sua própria opinião no Facebook. É muito importante para nós saber o que pensa!', 'disc' => 'Discussão no Facebook', 'btn' => 'Dar a minha opinião', 'foot' => 'Para si, alguns segundos; para nós, uma ajuda enorme! 💗', 'copied' => 'Copiado! Cole num comentário no Facebook', 'copy' => 'Copiar' ),
			'it' => array( 'nocomm' => 'Vai su Facebook, metti mi piace e lascia il tuo commento: la tua opinione è molto importante per noi!', 'cmts' => 'commenti', 'from' => 'Un messaggio dalla redazione', 'q' => 'Quale opinione senti più vicina?', 'hint' => 'Puoi copiare queste opzioni 👇', 'or' => 'Oppure scrivi la tua opinione su Facebook. Per noi è importante sapere cosa ne pensi!', 'disc' => 'Discussione su Facebook', 'btn' => 'Dì la tua', 'foot' => 'Per te pochi secondi, per noi un aiuto enorme! 💗', 'copied' => 'Copiato! Incollalo in un commento su Facebook', 'copy' => 'Copia' ),
			'pl' => array( 'nocomm' => 'Przejdź na Facebooka, polub post i zostaw swój komentarz — Twoja opinia jest dla nas bardzo ważna!', 'cmts' => 'komentarzy', 'from' => 'Słowo od redakcji', 'q' => 'Która opinia jest Ci bliższa?', 'hint' => 'Możesz skopiować te warianty 👇', 'or' => 'Albo napisz własną opinię na Facebooku. Bardzo chcemy wiedzieć, co myślisz!', 'disc' => 'Dyskusja na Facebooku', 'btn' => 'Wyraź swoją opinię', 'foot' => 'Dla Ciebie kilka sekund, dla nas ogromna pomoc! 💗', 'copied' => 'Skopiowano! Wklej w komentarzu na Facebooku', 'copy' => 'Kopiuj' ),
			'ro' => array( 'nocomm' => 'Intrați pe Facebook, apreciați postarea și lăsați comentariul dvs. — părerea dvs. contează mult pentru noi!', 'cmts' => 'comentarii', 'from' => 'Un mesaj de la redacție', 'q' => 'Ce părere vi se potrivește mai mult?', 'hint' => 'Puteți copia aceste variante 👇', 'or' => 'Sau scrieți propria părere pe Facebook. Contează mult pentru noi ce credeți!', 'disc' => 'Discuție pe Facebook', 'btn' => 'Spuneți-vă părerea', 'foot' => 'Pentru dvs. câteva secunde, pentru noi un ajutor uriaș! 💗', 'copied' => 'Copiat! Lipiți-l într-un comentariu pe Facebook', 'copy' => 'Copiază' ),
			'hu' => array( 'nocomm' => 'Lépjen át a Facebookra, lájkolja a bejegyzést, és írja meg hozzászólását — nagyon fontos nekünk a véleménye!', 'cmts' => 'hozzászólás', 'from' => 'A szerkesztőség üzenete', 'q' => 'Melyik vélemény áll közelebb Önhöz?', 'hint' => 'Ezeket a változatokat kimásolhatja 👇', 'or' => 'Vagy írja meg saját véleményét a Facebookon. Nagyon fontos nekünk, mit gondol!', 'disc' => 'Beszélgetés a Facebookon', 'btn' => 'Elmondom a véleményem', 'foot' => 'Önnek néhány másodperc, nekünk óriási segítség! 💗', 'copied' => 'Kimásolva! Illessze be egy Facebook-hozzászólásba', 'copy' => 'Másolás' ),
			'cs' => array( 'nocomm' => 'Přejděte na Facebook, dejte like a napište svůj komentář — váš názor je pro nás velmi důležitý!', 'cmts' => 'komentářů', 'from' => 'Slovo redakce', 'q' => 'Který názor je vám bližší?', 'hint' => 'Tyto varianty si můžete zkopírovat 👇', 'or' => 'Nebo napište svůj názor na Facebooku. Moc nás zajímá, co si myslíte!', 'disc' => 'Diskuze na Facebooku', 'btn' => 'Vyjádřit svůj názor', 'foot' => 'Pro vás pár vteřin, pro nás obrovská pomoc! 💗', 'copied' => 'Zkopírováno! Vložte do komentáře na Facebooku', 'copy' => 'Kopírovat' ),
			'bg' => array( 'nocomm' => 'Отидете във Facebook, харесайте публикацията и оставете своя коментар — мнението ви е много важно за нас!', 'cmts' => 'коментара', 'from' => 'Обръщение от редакцията', 'q' => 'Кое мнение ви е по-близо?', 'hint' => 'Можете да копирате тези варианти 👇', 'or' => 'Или напишете своето мнение във Facebook. Много ни е важно какво мислите!', 'disc' => 'Обсъждане във Facebook', 'btn' => 'Кажете мнението си', 'foot' => 'За вас — няколко секунди, за нас — огромна помощ! 💗', 'copied' => 'Копирано! Поставете го в коментар във Facebook', 'copy' => 'Копирай' ),
			'tr' => array( 'nocomm' => 'Facebook\'a geçin, gönderiyi beğenin ve yorumunuzu bırakın — fikriniz bizim için çok önemli!', 'cmts' => 'yorum', 'from' => 'Editörden bir not', 'q' => 'Hangi görüş size daha yakın?', 'hint' => 'Bu seçenekleri kopyalayabilirsiniz 👇', 'or' => 'Ya da kendi görüşünüzü Facebook\'ta yazın. Ne düşündüğünüzü bilmek bizim için çok önemli!', 'disc' => 'Facebook\'ta tartışma', 'btn' => 'Görüşünü paylaş', 'foot' => 'Sizin için birkaç saniye, bizim için büyük bir yardım! 💗', 'copied' => 'Kopyalandı! Facebook yorumuna yapıştırın', 'copy' => 'Kopyala' ),
		);
		return isset( $all[ $lang ] ) ? $all[ $lang ] : $all['ru'];
	}

	/* ---------- GitHub updater ---------- */

	private function latest_release() {
		$cached = get_site_transient( 'fbc_github_release' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases?per_page=30', array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'FB-Comment-CTA/' . self::VERSION ),
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
				if ( ! empty( $asset['name'] ) && preg_match( '/^fb-comment-cta-.*\.zip$/', $asset['name'] ) && ( ! $best || version_compare( $version, $best['version'], '>' ) ) ) {
					$best = array( 'version' => $version, 'url' => $rel['html_url'], 'package' => $asset['browser_download_url'] );
				}
			}
		}
		set_site_transient( 'fbc_github_release', $best ? $best : array( 'version' => '0' ), 6 * HOUR_IN_SECONDS );
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
		$item = (object) array( 'slug' => dirname( $file ), 'plugin' => $file, 'new_version' => $rel['version'], 'url' => $rel['url'], 'package' => $rel['package'] );
		if ( version_compare( $rel['version'], $transient->checked[ $file ], '>' ) ) {
			$transient->response[ $file ] = $item;
		} else {
			$item->{'update-supported'} = true;
			$transient->no_update[ $file ] = $item;
		}
		return $transient;
	}

	public function update_from_github() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'You do not have permission to update plugins.' );
		}
		check_admin_referer( 'fbc_update_from_github' );
		delete_site_transient( 'fbc_github_release' );
		delete_site_transient( 'update_plugins' );
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		wp_update_plugins();
		$rel = $this->latest_release();
		if ( ! $rel || version_compare( $rel['version'], self::VERSION, '<=' ) ) {
			$message = 'FB Comment CTA ' . self::VERSION . ' is already the newest release on GitHub.';
		} else {
			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( plugin_basename( __FILE__ ) );
			$message  = is_wp_error( $result ) ? $result->get_error_message()
				: ( $result ? 'FB Comment CTA was updated from GitHub to ' . $rel['version'] . '.' : 'GitHub update failed.' );
		}
		set_transient( 'fbc_update_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	public function render_update_notice() {
		$message = get_transient( 'fbc_update_notice_' . get_current_user_id() );
		if ( false === $message ) {
			return;
		}
		delete_transient( 'fbc_update_notice_' . get_current_user_id() );
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	public function auto_update( $update, $item ) {
		return ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) ? true : $update;
	}

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

new FB_Comment_CTA();
register_activation_hook( __FILE__, array( 'FB_Comment_CTA', 'activate' ) );

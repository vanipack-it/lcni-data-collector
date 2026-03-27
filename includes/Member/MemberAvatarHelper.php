<?php
/**
 * LCNI Member Avatar Helper
 *
 * Cung cấp avatar thông minh cho user:
 * - Nếu đăng nhập bằng Google → dùng ảnh Google avatar (meta: lcni_google_avatar)
 * - Nếu có Gravatar thật       → dùng Gravatar + viền màu SaaS
 * - Fallback                   → render 2 chữ đầu tên (initials) dạng span tròn
 * - Viền avatar màu theo gói SaaS của user
 *
 * Cách dùng trong theme/plugin:
 *   lcni_get_user_avatar( $user_id, $size, $echo );
 *   lcni_get_user_avatar( 0, 36, true ); // current user, echo trực tiếp
 *
 * @package LCNI_Data_Collector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LCNI_Member_Avatar_Helper {

	/** Màu viền mặc định khi user chưa có gói SaaS. */
	const DEFAULT_BORDER_COLOR = '#2563eb';

	public function __construct() {
		// Thay thế <img> avatar trong HTML (get_avatar filter)
		add_filter( 'get_avatar', [ $this, 'filter_get_avatar' ], 20, 5 );

		// Override URL avatar cho REST, wp_mail, admin bar, v.v.
		add_filter( 'pre_get_avatar_data', [ $this, 'filter_avatar_data' ], 20, 2 );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// WordPress Filters
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Filter get_avatar(): thay thế HTML avatar bằng avatar LCNI.
	 */
	public function filter_get_avatar( $avatar, $id_or_email, $size, $default, $alt ) {
		$user = $this->resolve_user( $id_or_email );
		if ( ! $user ) {
			return $avatar;
		}

		$size         = max( 1, (int) $size );
		$border_color = $this->get_package_color( $user->ID );
		$google_url   = $this->get_google_avatar_url( $user->ID );

		if ( $google_url ) {
			return $this->render_img_avatar( $google_url, $size, $border_color, $alt ?: $user->display_name );
		}

		if ( $this->has_real_gravatar( $user->user_email ) ) {
			return $this->wrap_with_border( $avatar, $size, $border_color );
		}

		$initials = $this->get_initials( $user->display_name );
		return $this->render_initials_avatar( $initials, $size, $border_color );
	}

	/**
	 * Filter pre_get_avatar_data: override URL avatar (REST API, admin bar, v.v.)
	 */
	public function filter_avatar_data( $args, $id_or_email ) {
		$user = $this->resolve_user( $id_or_email );
		if ( ! $user ) {
			return $args;
		}

		$google_url = $this->get_google_avatar_url( $user->ID );
		if ( $google_url ) {
			$args['url']          = $google_url;
			$args['found_avatar'] = true;
		}

		return $args;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Public Static API
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render avatar HTML cho user bất kỳ.
	 *
	 * @param int  $user_id  WP User ID. 0 = current user.
	 * @param int  $size     Kích thước px (mặc định 36).
	 * @param bool $echo     true = echo, false = return string.
	 * @return string
	 */
	public static function render( $user_id = 0, $size = 36, $echo = false ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$user    = $user_id ? get_userdata( $user_id ) : null;
		$size    = max( 1, (int) $size );

		if ( ! $user ) {
			$html = self::build_initials_html( '?', $size, self::DEFAULT_BORDER_COLOR );
			if ( $echo ) {
				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			return $html;
		}

		$helper       = new self();
		$border_color = $helper->get_package_color( $user->ID );
		$google_url   = $helper->get_google_avatar_url( $user->ID );

		if ( $google_url ) {
			$html = $helper->render_img_avatar( $google_url, $size, $border_color, $user->display_name );
		} elseif ( $helper->has_real_gravatar( $user->user_email ) ) {
			$gravatar_url = get_avatar_url( $user->ID, [ 'size' => $size * 2, 'default' => '404' ] );
			$html         = $helper->render_img_avatar( $gravatar_url, $size, $border_color, $user->display_name );
		} else {
			$initials = $helper->get_initials( $user->display_name );
			$html     = self::build_initials_html( $initials, $size, $border_color );
		}

		if ( $echo ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $html;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Private Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Resolve WP_User từ nhiều loại input khác nhau.
	 *
	 * @param mixed $id_or_email
	 * @return WP_User|null
	 */
	private function resolve_user( $id_or_email ) {
		if ( $id_or_email instanceof WP_User ) {
			return $id_or_email->exists() ? $id_or_email : null;
		}
		if ( $id_or_email instanceof WP_Comment ) {
			$uid = (int) $id_or_email->user_id;
			return $uid ? ( get_userdata( $uid ) ?: null ) : null;
		}
		if ( is_numeric( $id_or_email ) && (int) $id_or_email > 0 ) {
			return get_userdata( (int) $id_or_email ) ?: null;
		}
		if ( is_string( $id_or_email ) && strpos( $id_or_email, '@' ) !== false ) {
			return get_user_by( 'email', $id_or_email ) ?: null;
		}
		return null;
	}

	/**
	 * Lấy URL ảnh Google avatar đã lưu trong user meta.
	 *
	 * @param int $user_id
	 * @return string URL hoặc rỗng.
	 */
	private function get_google_avatar_url( $user_id ) {
		$url = get_user_meta( (int) $user_id, 'lcni_google_avatar', true );
		if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return esc_url_raw( $url );
		}
		return '';
	}

	/**
	 * Lấy màu viền theo gói SaaS của user.
	 * Gọi trực tiếp repository để lấy đúng màu kể cả khi user khác current user.
	 *
	 * @param int $user_id
	 * @return string hex color
	 */
	private function get_package_color( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return self::DEFAULT_BORDER_COLOR;
		}

		// Dùng static cache trong request để tránh query lặp
		static $color_cache = [];
		if ( isset( $color_cache[ $user_id ] ) ) {
			return $color_cache[ $user_id ];
		}

		try {
			if ( ! class_exists( 'LCNI_SaaS_Repository' ) ) {
				$color_cache[ $user_id ] = self::DEFAULT_BORDER_COLOR;
				return self::DEFAULT_BORDER_COLOR;
			}

			$user = get_userdata( $user_id );
			$role = ( $user && ! empty( $user->roles[0] ) ) ? sanitize_key( $user->roles[0] ) : '';

			$repo = new LCNI_SaaS_Repository();
			$row  = $repo->get_user_package_row( $user_id, $role );
			$color = ( $row && ! empty( $row['color'] ) )
				? $row['color']
				: self::DEFAULT_BORDER_COLOR;

			// Validate hex color
			$color = sanitize_hex_color( $color ) ?: self::DEFAULT_BORDER_COLOR;
		} catch ( Exception $e ) {
			$color = self::DEFAULT_BORDER_COLOR;
		}

		$color_cache[ $user_id ] = $color;
		return $color;
	}

	/**
	 * Lấy 2 chữ cái đầu từ tên hiển thị.
	 *
	 * "Nguyen Van Long" → "NL"
	 * "Long"            → "LO"
	 * "Lê"              → "LÊ"
	 *
	 * @param string $display_name
	 * @return string
	 */
	private function get_initials( $display_name ) {
		$display_name = trim( (string) $display_name );
		if ( empty( $display_name ) ) {
			return '?';
		}

		$words = preg_split( '/\s+/u', $display_name, -1, PREG_SPLIT_NO_EMPTY );

		if ( count( $words ) >= 2 ) {
			$first = $this->mb_first_char( $words[0] );
			$last  = $this->mb_first_char( $words[ count( $words ) - 1 ] );
			return $first . $last;
		}

		// Chỉ 1 từ: lấy 2 ký tự đầu
		return $this->mb_upper( mb_substr( $words[0], 0, 2, 'UTF-8' ) );
	}

	/** Lấy ký tự đầu tiên của chuỗi, uppercase. */
	private function mb_first_char( $str ) {
		return $this->mb_upper( mb_substr( (string) $str, 0, 1, 'UTF-8' ) );
	}

	/** mb_strtoupper với fallback. */
	private function mb_upper( $str ) {
		return function_exists( 'mb_strtoupper' )
			? mb_strtoupper( $str, 'UTF-8' )
			: strtoupper( $str );
	}

	/**
	 * Kiểm tra nhanh email có Gravatar thật không.
	 * Cache kết quả 6 tiếng bằng transient.
	 *
	 * @param string $email
	 * @return bool
	 */
	private function has_real_gravatar( $email ) {
		if ( empty( $email ) ) {
			return false;
		}

		$hash      = md5( strtolower( trim( $email ) ) );
		$cache_key = 'lcni_gravatar_' . $hash;
		$cached    = get_transient( $cache_key );

		if ( $cached !== false ) {
			return $cached === '1';
		}

		$response = wp_remote_head(
			"https://www.gravatar.com/avatar/{$hash}?d=404&s=1",
			[ 'timeout' => 3, 'sslverify' => true ]
		);

		$code = is_wp_error( $response ) ? 404 : (int) wp_remote_retrieve_response_code( $response );
		$has  = ( $code === 200 );

		set_transient( $cache_key, $has ? '1' : '0', 6 * HOUR_IN_SECONDS );
		return $has;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Render Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render <span> bao <img> avatar với viền màu SaaS.
	 */
	private function render_img_avatar( $url, $size, $border_color, $alt = '' ) {
		$px         = (int) $size;
		$border_px  = max( 2, (int) round( $px / 16 ) );

		return sprintf(
			'<span class="lcni-avatar lcni-avatar--img" style="display:inline-flex;align-items:center;justify-content:center;width:%1$dpx;height:%1$dpx;border-radius:50%%;border:%2$dpx solid %3$s;overflow:hidden;flex-shrink:0;box-sizing:border-box;">'
			. '<img src="%4$s" alt="%5$s" width="%1$d" height="%1$d" style="width:100%%;height:100%%;object-fit:cover;border-radius:50%%;display:block;" loading="lazy">'
			. '</span>',
			$px,
			$border_px,
			esc_attr( $border_color ),
			esc_url( $url ),
			esc_attr( $alt )
		);
	}

	/**
	 * Render initials avatar (span tròn với chữ).
	 */
	private function render_initials_avatar( $initials, $size, $border_color ) {
		return self::build_initials_html( $initials, $size, $border_color );
	}

	/**
	 * Build initials avatar HTML — static để dùng từ render().
	 */
	private static function build_initials_html( $initials, $size, $border_color ) {
		$px        = (int) $size;
		$border_px = max( 2, (int) round( $px / 16 ) );
		$font_size = max( 10, (int) round( $px * 0.38 ) );
		$bg_color  = self::hex_to_rgba( $border_color, 0.10 );

		return sprintf(
			'<span class="lcni-avatar lcni-avatar--initials" '
			. 'style="display:inline-flex;align-items:center;justify-content:center;'
			. 'width:%1$dpx;height:%1$dpx;border-radius:50%%;'
			. 'border:%2$dpx solid %3$s;'
			. 'background-color:%4$s;'
			. 'color:%3$s;'
			. 'font-size:%5$dpx;font-weight:700;font-family:inherit;'
			. 'line-height:1;letter-spacing:0.04em;'
			. 'flex-shrink:0;box-sizing:border-box;user-select:none;" '
			. 'title="%6$s" aria-label="%6$s">'
			. '%6$s'
			. '</span>',
			$px,
			$border_px,
			esc_attr( $border_color ),
			esc_attr( $bg_color ),
			$font_size,
			esc_html( $initials )
		);
	}

	/**
	 * Wrap avatar HTML gốc (Gravatar) trong span với viền màu SaaS.
	 */
	private function wrap_with_border( $avatar_html, $size, $border_color ) {
		$px        = (int) $size;
		$border_px = max( 2, (int) round( $px / 16 ) );

		// Inject style vào <img> tag
		$img_style = "border-radius:50%;border:{$border_px}px solid " . esc_attr( $border_color ) . ";display:block;box-sizing:border-box;";
		$avatar_html = preg_replace_callback(
			'/<img\b([^>]*?)(\s*\/?>)/i',
			static function ( $m ) use ( $img_style ) {
				// Nếu đã có style thì append, không thì thêm mới
				if ( preg_match( '/\bstyle\s*=/i', $m[1] ) ) {
					$inner = preg_replace( '/(\bstyle\s*=\s*["\'])([^"\']*)/i', '$1$2 ' . $img_style, $m[1] );
				} else {
					$inner = $m[1] . ' style="' . $img_style . '"';
				}
				return '<img' . $inner . $m[2];
			},
			$avatar_html,
			1
		);

		return '<span class="lcni-avatar lcni-avatar--gravatar" style="display:inline-flex;border-radius:50%;overflow:hidden;flex-shrink:0;">' . $avatar_html . '</span>';
	}

	/**
	 * Chuyển hex sang rgba() string.
	 *
	 * @param string $hex   Ví dụ: '#2563eb'
	 * @param float  $alpha 0.0–1.0
	 * @return string
	 */
	private static function hex_to_rgba( $hex, $alpha = 1.0 ) {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 ) {
			return "rgba(37,99,235,{$alpha})";
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return "rgba({$r},{$g},{$b},{$alpha})";
	}
}

/**
 * Hàm helper global: render avatar LCNI.
 *
 * @param int  $user_id  WP User ID. 0 = current user.
 * @param int  $size     Kích thước px (mặc định 36).
 * @param bool $echo     Echo hay return string.
 * @return string
 */
function lcni_get_user_avatar( $user_id = 0, $size = 36, $echo = false ) {
	return LCNI_Member_Avatar_Helper::render( $user_id, $size, $echo );
}

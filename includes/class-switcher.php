<?php
/**
 * Language switcher: header dropdown (evenmed style), shortcode, widget, floating.
 *
 * @package Lingora
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lingora_Switcher {
	private Lingora_Languages $languages;
	private Lingora_Rewrite $rewrite;
	private Lingora_Options $options;
	private bool $injected = false;
	private bool $menu_done = false;

	public function __construct( Lingora_Languages $languages, Lingora_Rewrite $rewrite, Lingora_Options $options ) {
		$this->languages = $languages;
		$this->rewrite   = $rewrite;
		$this->options   = $options;
	}

	public function register(): void {
		add_shortcode( 'lingora_switcher', array( $this, 'shortcode' ) );
		add_action( 'wp_footer', array( $this, 'render_fallback' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
		add_filter( 'wp_nav_menu_items', array( $this, 'append_to_menu' ), 20, 2 );
	}

	public function enqueue(): void {
		wp_enqueue_style(
			'lingora-frontend',
			LINGORA_URL . 'public/css/frontend.css',
			array(),
			LINGORA_VERSION
		);
		wp_enqueue_script(
			'lingora-switcher',
			LINGORA_URL . 'public/js/switcher.js',
			array(),
			LINGORA_VERSION,
			true
		);
		wp_localize_script(
			'lingora-switcher',
			'lingoraSwitcher',
			array(
				'cookie' => Lingora_Detect::COOKIE,
			)
		);
	}

	public function register_widget(): void {
		register_widget( 'Lingora_Switcher_Widget' );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'style' => 'nav',
			),
			is_array( $atts ) ? $atts : array(),
			'lingora_switcher'
		);
		$this->injected = true;
		return $this->markup( (string) $atts['style'] );
	}

	/**
	 * @param string   $items
	 * @param stdClass $args
	 */
	public function append_to_menu( string $items, $args ): string { // phpcs:ignore
		if ( ! $this->options->get( 'switcher_enabled', true ) ) {
			return $items;
		}
		if ( ! $this->options->get( 'switcher_in_menu', true ) ) {
			return $items;
		}
		$style = (string) $this->options->get( 'switcher_style', 'nav' );
		if ( 'floating' === $style ) {
			return $items;
		}
		if ( $this->menu_done ) {
			return $items;
		}
		$theme_location = is_object( $args ) ? (string) ( $args->theme_location ?? '' ) : '';
		if ( '' === $theme_location ) {
			return $items;
		}
		$this->menu_done = true;
		$this->injected  = true;
		$items         .= '<li class="menu-item lingora-menu-switcher">' . $this->markup( 'nav' ) . '</li>';
		return $items;
	}

	public function render_fallback(): void {
		if ( ! $this->options->get( 'switcher_enabled', true ) ) {
			return;
		}
		$style = (string) $this->options->get( 'switcher_style', 'nav' );
		if ( 'floating' === $style ) {
			$position = (string) $this->options->get( 'switcher_position', 'bottom-right' );
			echo $this->markup( 'floating', $position ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		if ( 'both' === $style ) {
			$position = (string) $this->options->get( 'switcher_position', 'bottom-right' );
			echo $this->markup( 'floating', $position ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $this->injected ) {
			return;
		}
		$position = (string) $this->options->get( 'switcher_position', 'top-right' );
		if ( ! in_array( $position, array( 'top-right', 'top-left' ), true ) ) {
			$position = 'top-right';
		}
		echo $this->markup( 'nav', $position ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function markup( string $style = 'nav', string $position = '' ): string {
		$langs = $this->languages->enabled();
		if ( count( $langs ) < 2 ) {
			return '';
		}
		$current   = $this->languages->current();
		$show_flag = (bool) $this->options->get( 'switcher_show_flag', true );
		$show_code = (bool) $this->options->get( 'switcher_show_code', true );
		$show_name = (bool) $this->options->get( 'switcher_show_name', false );
		$request   = $this->current_request_url();
		$pos_class = $position ? ' is-' . sanitize_html_class( $position ) : '';

		ob_start();
		?>
		<nav class="lingora-switcher lingora-skip notranslate is-<?php echo esc_attr( $style ); ?><?php echo esc_attr( $pos_class ); ?>" translate="no" aria-label="<?php esc_attr_e( 'Languages', 'lingora' ); ?>">
			<button type="button" class="lingora-switcher__current" aria-expanded="false" aria-haspopup="listbox">
				<?php if ( $show_flag ) : ?>
					<?php echo Lingora_Flags::markup( $current, $this->languages->flag( $current ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
				<?php if ( $show_code ) : ?>
					<span class="lingora-switcher__code"><?php echo esc_html( $this->languages->iso_label( $current ) ); ?></span>
				<?php endif; ?>
				<?php if ( $show_name ) : ?>
					<span class="lingora-switcher__name"><?php echo esc_html( $this->languages->label( $current ) ); ?></span>
				<?php endif; ?>
			</button>
			<ul class="lingora-switcher__list" role="listbox" hidden>
				<?php foreach ( $langs as $lang ) : ?>
					<?php
					$code = (string) $lang['code'];
					if ( $code === $current ) {
						continue;
					}
					$url = $this->rewrite->language_url( $code, $request );
					?>
					<li>
						<a
							href="<?php echo esc_url( $url ); ?>"
							hreflang="<?php echo esc_attr( $code ); ?>"
							lang="<?php echo esc_attr( $code ); ?>"
							data-lingora-lang="<?php echo esc_attr( $code ); ?>"
						>
							<?php if ( $show_flag ) : ?>
								<?php echo Lingora_Flags::markup( $code, $this->languages->flag( $code ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endif; ?>
							<?php if ( $show_code ) : ?>
								<span class="lingora-switcher__code"><?php echo esc_html( $this->languages->iso_label( $code ) ); ?></span>
							<?php endif; ?>
							<?php if ( $show_name ) : ?>
								<span class="lingora-switcher__name"><?php echo esc_html( (string) ( $lang['native'] ?? $lang['name'] ) ); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
		<?php
		return (string) ob_get_clean();
	}

	private function current_request_url(): string {
		$path = $this->rewrite->strip_language( $this->rewrite->raw_path() ?: ( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/' ) );
		return untrailingslashit( $this->rewrite->unlocalized_home() ) . ( '/' === $path ? '/' : $path );
	}
}

class Lingora_Switcher_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'lingora_switcher',
			__( 'Lingora Language Switcher', 'lingora' ),
			array( 'description' => __( 'Header-style language dropdown with flags.', 'lingora' ) )
		);
	}

	public function widget( $args, $instance ): void { // phpcs:ignore
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$title = $instance['title'] ?? '';
		if ( $title ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo lingora()->switcher->markup( 'nav' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ): void { // phpcs:ignore
		$title = $instance['title'] ?? '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title', 'lingora' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ): array { // phpcs:ignore
		return array(
			'title' => sanitize_text_field( $new_instance['title'] ?? '' ),
		);
	}
}

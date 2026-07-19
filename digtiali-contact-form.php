<?php
/**
 * Plugin Name: Digtiali Contact Form
 * Description: Custom contact form with admin submissions and reply handling.
 * Version: 1.0.2
 * Author: Digtiali
 * Author URI: https://digtiali.com
 * Text Domain: digtiali-contact-form
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIGTIALI_CONTACT_FORM_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIGTIALI_CONTACT_FORM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Installed version — read from version.json when present.
 */
function digtiali_contact_form_read_installed_version(): string {
	$fallback = '1.0.2'; // digtiali-contact-form version fallback
	$path     = __DIR__ . '/version.json';
	if ( ! is_readable( $path ) ) {
		return $fallback;
	}
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) || empty( $data['version'] ) ) {
		return $fallback;
	}
	return (string) $data['version'];
}

if ( ! defined( 'DIGTIALI_CONTACT_FORM_VERSION' ) ) {
	define( 'DIGTIALI_CONTACT_FORM_VERSION', digtiali_contact_form_read_installed_version() );
}

require_once DIGTIALI_CONTACT_FORM_PATH . 'includes/plugin-updater.php';

/** Load plugin translations. Source language is Arabic; en_US.mo provides English. */
add_action( 'init', static function () {
	load_plugin_textdomain( 'digtiali-contact-form', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

function digtiali_contact_form_init_plugin(): void {
	static $booted = false;
	if ( $booted ) {
		return;
	}
	$booted = true;

	add_action( 'init', 'digtiali_contact_register_cpt_and_statuses' );
	add_shortcode( 'digtiali_contact_form', 'digtiali_contact_form_shortcode' );
	add_action( 'wp_ajax_nopriv_digtiali_contact_submit', 'digtiali_contact_submit_ajax' );
	add_action( 'wp_ajax_digtiali_contact_submit', 'digtiali_contact_submit_ajax' );
	add_action( 'admin_menu', 'digtiali_contact_admin_menu' );
	add_action( 'wp_ajax_digtiali_contact_reply', 'digtiali_contact_reply_ajax' );
	add_action( 'wp_ajax_digtiali_contact_mark_read', 'digtiali_contact_mark_read_ajax' );
	add_action( 'wp_enqueue_scripts', 'digtiali_contact_enqueue_page_assets' );
	add_action( 'wp_head', 'digtiali_contact_inline_head_assets', 20 );
	add_action( 'wp_footer', 'digtiali_contact_inline_footer_assets', 20 );
	add_filter( 'the_content', 'digtiali_contact_force_shortcode_render', 9 );
	add_filter( 'body_class', 'digtiali_contact_body_class' );
}
add_action( 'plugins_loaded', 'digtiali_contact_form_init_plugin' );

function digtiali_contact_body_class( array $classes ): array {
	global $post;
	if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'digtiali_contact_form' ) ) {
		$classes[] = 'digi-contact-page';
	}
	return $classes;
}

function digtiali_contact_admin_head_assets(): void {
	$screen = get_current_screen();
	if ( ! $screen || 'toplevel_page_digtiali-contact-submissions' !== $screen->id ) {
		return;
	}
	echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800;900&display=swap">' . "\n";
	echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap">' . "\n";
}

function digtiali_contact_enqueue_page_assets(): void {
	global $post;
	if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( (string) $post->post_content, 'digtiali_contact_form' ) ) {
		return;
	}
	if ( wp_style_is( 'digtiali-material-symbols', 'registered' ) ) {
		wp_enqueue_style( 'digtiali-material-symbols' );
	} else {
		$url = defined( 'DIGTIALI_GOOGLE_FONT_MATERIAL_CSS' ) ? DIGTIALI_GOOGLE_FONT_MATERIAL_CSS : 'https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0&display=swap';
		wp_enqueue_style( 'digtiali-contact-material-symbols', $url, [], null );
	}
}

function digtiali_contact_force_shortcode_render( string $content ): string {
	if ( false === strpos( $content, '[digtiali_contact_form]' ) ) {
		return $content;
	}
	return str_replace( '[digtiali_contact_form]', do_shortcode( '[digtiali_contact_form]' ), $content );
}

function digtiali_contact_register_cpt_and_statuses(): void {
	register_post_status(
		'unread',
		array(
			'label'                     => _x( 'Unread', 'post status', 'digtiali-contact-form' ),
			'public'                    => false,
			'internal'                  => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Unread <span class="count">(%s)</span>', 'Unread <span class="count">(%s)</span>', 'digtiali-contact-form' ),
		)
	);
	register_post_status(
		'read',
		array(
			'label'                     => _x( 'Read', 'post status', 'digtiali-contact-form' ),
			'public'                    => false,
			'internal'                  => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Read <span class="count">(%s)</span>', 'Read <span class="count">(%s)</span>', 'digtiali-contact-form' ),
		)
	);
	register_post_status(
		'replied',
		array(
			'label'                     => _x( 'Replied', 'post status', 'digtiali-contact-form' ),
			'public'                    => false,
			'internal'                  => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Replied <span class="count">(%s)</span>', 'Replied <span class="count">(%s)</span>', 'digtiali-contact-form' ),
		)
	);

	register_post_type(
		'contact_submission',
		array(
			'label'              => __( 'Contact Submissions', 'digtiali-contact-form' ),
			'public'             => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'query_var'          => false,
			'rewrite'            => false,
			'supports'           => array( 'title' ),
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'exclude_from_search'=> true,
			'publicly_queryable' => false,
			'show_in_nav_menus'  => false,
			'show_in_admin_bar'  => false,
			'has_archive'        => false,
		)
	);
}

function digtiali_contact_user_can_manage(): bool {
	return current_user_can( 'manage_options' );
}

function digtiali_contact_normalize_phone( string $phone ): string {
	$phone = preg_replace( '/[^0-9+]/', '', $phone );
	if ( '' === $phone ) {
		return '';
	}
	return '+' === substr( $phone, 0, 1 ) ? $phone : '+' . ltrim( $phone, '+' );
}

function digtiali_contact_get_status_label( string $status ): string {
	$labels = array( 'unread' => __( 'Unread', 'digtiali-contact-form' ), 'read' => __( 'Read', 'digtiali-contact-form' ), 'replied' => __( 'Replied', 'digtiali-contact-form' ) );
	return $labels[ $status ] ?? $status;
}

function digtiali_contact_get_counts(): array {
	$counts = wp_count_posts( 'contact_submission' );
	return array(
		'all'     => (int) ( ( $counts->unread ?? 0 ) + ( $counts->read ?? 0 ) + ( $counts->replied ?? 0 ) ),
		'unread'  => (int) ( $counts->unread ?? 0 ),
		'read'    => (int) ( $counts->read ?? 0 ),
		'replied' => (int) ( $counts->replied ?? 0 ),
	);
}

function digtiali_contact_get_submission_meta( int $post_id ): array {
	return array(
		'email'      => (string) get_post_meta( $post_id, '_cs_email', true ),
		'phone'      => (string) get_post_meta( $post_id, '_cs_phone', true ),
		'subject'    => (string) get_post_meta( $post_id, '_cs_subject', true ),
		'message'    => (string) get_post_meta( $post_id, '_cs_message', true ),
		'reply'      => (string) get_post_meta( $post_id, '_cs_reply', true ),
		'replied_at' => (int) get_post_meta( $post_id, '_cs_replied_at', true ),
		'ip'         => (string) get_post_meta( $post_id, '_cs_ip', true ),
	);
}

function digtiali_contact_admin_menu(): void {
	$counts = digtiali_contact_get_counts();
	$label  = __( 'Contact Submissions', 'digtiali-contact-form' );
	if ( ! empty( $counts['unread'] ) ) {
		$label .= ' <span class="update-plugins count-' . absint( $counts['unread'] ) . '"><span class="plugin-count">' . absint( $counts['unread'] ) . '</span></span>';
	}
	add_menu_page( __( 'Contact Submissions', 'digtiali-contact-form' ), $label, 'manage_options', 'digtiali-contact-submissions', 'digtiali_contact_render_admin_page', 'dashicons-email-alt', 26 );
}

function digtiali_contact_render_admin_page(): void {
	if ( ! digtiali_contact_user_can_manage() ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'digtiali-contact-form' ) );
	}
	$view    = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post_id = isset( $_GET['submission_id'] ) ? absint( $_GET['submission_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'detail' === $view && $post_id ) {
		echo '<div class="wrap">';
		digtiali_contact_render_detail_view( $post_id );
		echo '</div>';
		return;
	}
	echo '<div class="wrap"><h1>' . esc_html__( 'Contact Submissions', 'digtiali-contact-form' ) . '</h1>';
	digtiali_contact_render_list_view( $status );
	echo '</div>';
}

function digtiali_contact_render_list_view( string $status ): void {
	$counts = digtiali_contact_get_counts();
	$tabs   = array( 'all' => __( 'All', 'digtiali-contact-form' ), 'unread' => __( 'Unread', 'digtiali-contact-form' ), 'read' => __( 'Read', 'digtiali-contact-form' ), 'replied' => __( 'Replied', 'digtiali-contact-form' ) );
	$base   = admin_url( 'admin.php?page=digtiali-contact-submissions' );
	echo '<h2 class="nav-tab-wrapper">';
	foreach ( $tabs as $key => $label ) {
		$url = add_query_arg( 'status', $key, $base );
		$active = ( $status === $key ) ? ' nav-tab-active' : '';
		$count = $counts[ $key ] ?? 0;
		if ( 'all' !== $key ) {
			$label .= ' <span class="count">(' . absint( $count ) . ')</span>';
		}
		echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( $url ) . '">' . wp_kses_post( $label ) . '</a>';
	}
	echo '</h2>';

	$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$args = array(
		'post_type'      => 'contact_submission',
		'post_status'    => array( 'unread', 'read', 'replied' ),
		'posts_per_page' => 20,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( in_array( $status, array( 'unread', 'read', 'replied' ), true ) ) {
		$args['post_status'] = $status;
	}
	$q = new WP_Query( $args );
	echo '<style>.dcs-wrap{direction:rtl;font-family:"Almarai",system-ui,sans-serif;background:#f5f3f8;border-radius:16px;overflow:hidden;box-shadow:0 2px 8px rgba(120,27,175,.08);margin:20px 0}.dcs-tbl{background:#fff;border:1px solid rgba(120,27,175,.1);width:100%;border-collapse:collapse}.dcs-tbl th{background:linear-gradient(135deg,#faf9fb 0%,#f5f3f8 100%);color:#1a1a1a;font-weight:800;padding:16px 18px;font-size:.9rem;border-bottom:2px solid rgba(120,27,175,.15);letter-spacing:.02em}.dcs-tbl td{padding:14px 18px;font-size:.86rem;border-bottom:1px solid rgba(120,27,175,.05);color:#2a2038}.dcs-tbl tbody tr{transition:all .15s}.dcs-tbl tbody tr:hover{background:#faf9fb;border-color:rgba(120,27,175,.1)}.dcs-tbl a{color:#781baf;text-decoration:none;font-weight:600;transition:color .15s}.dcs-tbl a:hover{color:#5a1280;text-decoration:underline}.dcs-stat{color:rgba(42,32,56,.55);font-size:.82rem}.dcs-stat strong{color:#1a1a1a;font-weight:700}.dcs-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:8px;font-size:.76rem;font-weight:700;letter-spacing:.01em}.dcs-badge-unread{background:rgba(234,179,8,.15);color:#a16207;border:1px solid rgba(234,179,8,.2)}.dcs-badge-read{background:rgba(59,130,246,.15);color:#1e40af;border:1px solid rgba(59,130,246,.2)}.dcs-badge-replied{background:rgba(34,197,94,.15);color:#15803d;border:1px solid rgba(34,197,94,.2)}.dcs-pg{display:flex;justify-content:center;align-items:center;gap:6px;margin:24px 0;font-size:.85rem;padding:0 20px}.dcs-pg a,.dcs-pg span{padding:8px 13px;border-radius:8px;text-decoration:none;transition:all .15s}.dcs-pg a{background:#f0e4f8;color:#781baf;border:1px solid rgba(120,27,175,.15)}.dcs-pg a:hover{background:#f4edf8;color:#5a1280;border-color:rgba(120,27,175,.3)}.dcs-pg .page-numbers.current{background:linear-gradient(135deg,#781baf,#9b2dd4);color:#fff;font-weight:700;border:1px solid #781baf}</style>';
	echo '<div class="dcs-wrap"><table class="dcs-tbl widefat"><thead><tr><th>' . esc_html__( 'Name', 'digtiali-contact-form' ) . '</th><th>' . esc_html__( 'Email', 'digtiali-contact-form' ) . '</th><th>' . esc_html__( 'Phone', 'digtiali-contact-form' ) . '</th><th>' . esc_html__( 'Subject', 'digtiali-contact-form' ) . '</th><th>' . esc_html__( 'Date', 'digtiali-contact-form' ) . '</th><th>' . esc_html__( 'Status', 'digtiali-contact-form' ) . '</th></tr></thead><tbody>';
	if ( $q->have_posts() ) {
		foreach ( $q->posts as $post ) {
			$meta = digtiali_contact_get_submission_meta( $post->ID );
			$link = add_query_arg( array( 'page' => 'digtiali-contact-submissions', 'view' => 'detail', 'submission_id' => $post->ID ), admin_url( 'admin.php' ) );
			$status_label = digtiali_contact_get_status_label( $post->post_status );
			echo '<tr><td><a href="' . esc_url( $link ) . '"><strong>' . esc_html( get_the_title( $post ) ) . '</strong></a></td><td><span class="dcs-stat">' . esc_html( $meta['email'] ) . '</span></td><td><span class="dcs-stat">' . esc_html( $meta['phone'] ) . '</span></td><td>' . esc_html( $meta['subject'] ) . '</td><td><span class="dcs-stat">' . esc_html( get_the_date( 'Y-m-d H:i', $post ) ) . '</span></td><td><span class="dcs-badge dcs-badge-' . esc_attr( $post->post_status ) . '">' . esc_html( $status_label ) . '</span></td></tr>';
		}
	} else {
		echo '<tr><td colspan="6" style="text-align:center;padding:30px">' . esc_html__( 'No submissions found.', 'digtiali-contact-form' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	if ( $q->max_num_pages > 1 ) {
		$pagination = paginate_links( array(
			'base'    => add_query_arg( 'paged', '%#%', admin_url( 'admin.php?page=digtiali-contact-submissions&status=' . $status ) ),
			'current' => $paged,
			'total'   => $q->max_num_pages,
			'type'    => 'list',
		) );
		echo '<div class="dcs-pg">' . wp_kses_post( $pagination ) . '</div>';
	}
}

function digtiali_contact_render_detail_view( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post || 'contact_submission' !== $post->post_type ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Submission not found.', 'digtiali-contact-form' ) . '</p></div>';
		return;
	}
	if ( 'unread' === $post->post_status ) {
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'read' ) );
		$post = get_post( $post_id );
	}
	$meta        = digtiali_contact_get_submission_meta( $post_id );
	$status      = $post->post_status;
	$back_url    = admin_url( 'admin.php?page=digtiali-contact-submissions' );
	$wa_link     = 'https://wa.me/' . preg_replace( '/\D+/', '', $meta['phone'] );
	$date        = get_the_date( 'Y-m-d H:i', $post );
	$status_map  = array(
		'unread'   => array( esc_html__( 'غير مقروء', 'digtiali-contact-form' ), 'unread' ),
		'read'     => array( esc_html__( 'مقروء', 'digtiali-contact-form' ), 'read' ),
		'replied'  => array( esc_html__( 'تم الرد', 'digtiali-contact-form' ), 'replied' ),
	);
	$status_cfg  = $status_map[ $status ] ?? array( $status, 'read' );
	?>
	<style>
	.das{direction:rtl;font-family:"Almarai",system-ui,sans-serif;color:#1a1a1a;background:#f5f3f8;margin:-10px -20px;padding:24px 20px 48px;min-height:600px}
	.das *,.das *::before,.das *::after{box-sizing:border-box}
	.das a{text-decoration:none}
	.das-hd{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid rgba(120,27,175,.1)}
	.das-back{display:inline-flex;align-items:center;gap:6px;color:#781baf!important;font-size:.86rem;transition:color .15s;font-weight:600}
	.das-back:hover{color:#5a1280!important}
	.das-back .material-symbols-outlined{font-size:1rem;vertical-align:middle}
	.dab{background:#fff;border:1px solid rgba(120,27,175,.12);border-radius:18px;padding:20px;margin-bottom:14px}
	.dab-hd{display:flex;align-items:center;gap:8px;font-size:.92rem;font-weight:800;margin-bottom:14px;padding-bottom:11px;border-bottom:1px solid rgba(120,27,175,.1);color:#2a2038}
	.dab-hd .material-symbols-outlined{color:#781baf;font-size:1.1rem}
	.dir{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid rgba(120,27,175,.05);font-size:.84rem}
	.dir:last-of-type{border-bottom:0}
	.dir>span:first-child{color:rgba(42,32,56,.6)}
	.dir strong,.dir a,.dir code{color:#2a2038;font-size:.84rem}
	.dir a:hover{color:#781baf}
	.dir code{background:#f0e4f8;padding:2px 7px;border-radius:6px;font-family:monospace;font-size:.78rem}
	.dmb{background:#f9f7fc;border:1px solid rgba(120,27,175,.1);border-radius:12px;padding:14px;font-size:.88rem;line-height:1.85;white-space:pre-wrap;color:#1a1a1a;word-break:break-word}
	.dbtn{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;border-radius:999px;font-size:.84rem;font-weight:700;border:0;cursor:pointer;font-family:inherit;transition:transform .15s;text-decoration:none!important}
	.dbtn:hover{transform:translateY(-1px)}
	.dbtn-p{background:linear-gradient(135deg,#781baf,#9b2dd4);color:#fff!important;box-shadow:0 8px 20px rgba(120,27,175,.28)}
	.dbtn-w{background:linear-gradient(135deg,#25d366,#128c7e);color:#fff!important;width:100%;justify-content:center;margin-top:14px}
	.dbadge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:.74rem;font-weight:700}
	.dbadge-unread{background:rgba(234,179,8,.12);color:#b45309;border:1px solid rgba(234,179,8,.2)}
	.dbadge-read{background:rgba(59,130,246,.12);color:#1e40af;border:1px solid rgba(59,130,246,.2)}
	.dbadge-replied{background:rgba(34,197,94,.12);color:#15803d;border:1px solid rgba(34,197,94,.2)}
	.das-grid{display:grid;grid-template-columns:300px 1fr;gap:16px;align-items:start}
	.das-ta{width:100%;min-height:120px;background:#fff;border:1px solid rgba(120,27,175,.12);border-radius:12px;padding:12px 14px;color:#1a1a1a;font-family:inherit;font-size:.88rem;resize:vertical;outline:none;transition:border-color .18s;margin-bottom:12px;display:block}
	.das-ta:focus{border-color:rgba(120,27,175,.45)}
	.das-ta::placeholder{color:rgba(42,32,56,.4)}
	@media(max-width:900px){.das-grid{grid-template-columns:1fr}}
	</style>

	<div class="das">
		<div class="das-hd">
			<a href="<?php echo esc_url( $back_url ); ?>" class="das-back">
				<span class="material-symbols-outlined">arrow_forward</span>
				<?php esc_html_e( 'العودة إلى القائمة', 'digtiali-contact-form' ); ?>
			</a>
			<span class="dbadge dbadge-<?php echo esc_attr( $status_cfg[1] ); ?>"><?php echo esc_html( $status_cfg[0] ); ?></span>
		</div>

		<div class="das-grid">
			<!-- Sender info -->
			<div>
				<div class="dab">
					<div class="dab-hd"><span class="material-symbols-outlined">person</span><?php esc_html_e( 'معلومات المرسل', 'digtiali-contact-form' ); ?></div>
					<div class="dir"><span><?php esc_html_e( 'الاسم', 'digtiali-contact-form' ); ?></span><strong><?php echo esc_html( get_the_title( $post ) ); ?></strong></div>
					<?php if ( $meta['email'] ) : ?>
					<div class="dir"><span><?php esc_html_e( 'البريد', 'digtiali-contact-form' ); ?></span><a href="mailto:<?php echo esc_attr( $meta['email'] ); ?>"><?php echo esc_html( $meta['email'] ); ?></a></div>
					<?php endif; ?>
					<?php if ( $meta['phone'] ) : ?>
					<div class="dir"><span><?php esc_html_e( 'الهاتف', 'digtiali-contact-form' ); ?></span><a href="tel:<?php echo esc_attr( $meta['phone'] ); ?>"><?php echo esc_html( $meta['phone'] ); ?></a></div>
					<?php endif; ?>
					<?php if ( $meta['subject'] ) : ?>
					<div class="dir"><span><?php esc_html_e( 'الموضوع', 'digtiali-contact-form' ); ?></span><span><?php echo esc_html( $meta['subject'] ); ?></span></div>
					<?php endif; ?>
					<?php if ( $meta['ip'] ) : ?>
					<div class="dir"><span>IP</span><code><?php echo esc_html( $meta['ip'] ); ?></code></div>
					<?php endif; ?>
					<div class="dir"><span><?php esc_html_e( 'التاريخ', 'digtiali-contact-form' ); ?></span><span style="font-size:.8rem;color:rgba(42,32,56,.6)"><?php echo esc_html( $date ); ?></span></div>
					<?php if ( $meta['phone'] ) : ?>
					<a href="<?php echo esc_url( $wa_link ); ?>" class="dbtn dbtn-w" target="_blank" rel="noopener">
						<span class="material-symbols-outlined" style="font-size:1rem">chat</span>
						<?php esc_html_e( 'فتح واتساب', 'digtiali-contact-form' ); ?>
					</a>
					<?php endif; ?>
				</div>
			</div>

			<!-- Message + Reply -->
			<div>
				<div class="dab">
					<div class="dab-hd"><span class="material-symbols-outlined">mail</span><?php esc_html_e( 'الرسالة', 'digtiali-contact-form' ); ?></div>
					<div class="dmb"><?php echo esc_html( $meta['message'] ); ?></div>
				</div>

				<?php if ( $meta['reply'] ) : ?>
				<div class="dab" style="border-color:rgba(34,197,94,.18)">
					<div class="dab-hd">
						<span class="material-symbols-outlined" style="color:#4ade80">check_circle</span>
						<?php esc_html_e( 'الرد السابق', 'digtiali-contact-form' ); ?>
						<?php if ( $meta['replied_at'] ) : ?>
						<span style="font-weight:400;font-size:.76rem;color:rgba(244,237,248,.4);margin-right:auto"><?php echo esc_html( gmdate( 'Y-m-d H:i', $meta['replied_at'] ) ); ?></span>
						<?php endif; ?>
					</div>
					<div class="dmb" style="border-color:rgba(34,197,94,.1)"><?php echo esc_html( $meta['reply'] ); ?></div>
				</div>
				<?php endif; ?>

				<div class="dab">
					<div class="dab-hd"><span class="material-symbols-outlined">edit_note</span><?php esc_html_e( 'إرسال رد جديد', 'digtiali-contact-form' ); ?></div>
					<textarea id="digi-contact-reply" class="das-ta" rows="6" placeholder="<?php esc_attr_e( 'اكتب ردك هنا...', 'digtiali-contact-form' ); ?>"></textarea>
					<button class="dbtn dbtn-p" id="digi-contact-reply-btn" type="button">
						<span class="material-symbols-outlined" style="font-size:1rem">send</span>
						<?php esc_html_e( 'إرسال الرد', 'digtiali-contact-form' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>

	<script>
	window.DigiContactAdmin=<?php echo wp_json_encode( array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'replyNonce' => wp_create_nonce( 'digtiali_reply_nonce' ), 'submissionId' => $post_id ) ); ?>;
	(function(){
		var cfg=window.DigiContactAdmin;
		var btn=document.getElementById('digi-contact-reply-btn');
		if(!cfg||!btn)return;
		btn.addEventListener('click',function(){
			var reply=document.getElementById('digi-contact-reply').value.trim();
			if(!reply){alert('Reply text is required.');return;}
			var fd=new FormData();
			fd.append('action','digtiali_contact_reply');
			fd.append('nonce',cfg.replyNonce);
			fd.append('submission_id',cfg.submissionId);
			fd.append('reply',reply);
			btn.disabled=true;
			fetch(cfg.ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
				.then(function(r){return r.json().then(function(d){return{status:r.status,data:d};});})
				.then(function(res){btn.disabled=false;if(res.data&&res.data.success){location.reload();}else{alert((res.data&&res.data.data&&res.data.data.message)||'Failed to send reply.');}})
				.catch(function(){btn.disabled=false;});
		});
	})();
	</script>
	<?php
}

function digtiali_contact_inline_head_assets(): void {
	if ( is_admin() ) {
		return;
	}
	global $post;
	if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( (string) $post->post_content, 'digtiali_contact_form' ) ) {
		return;
	}
	$iti_base = 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8';
	echo '<link rel="stylesheet" href="' . esc_url( $iti_base . '/css/intlTelInput.min.css' ) . '" />' . "\n";
	echo '<style>
/* ── Full-width page shell (break out of FSE constrained column) ── */
body.digi-contact-page .wp-site-blocks{background:var(--digi-bg,#0d0d0d)}
body.digi-contact-page main.wp-block-group,
body.digi-contact-page main.digi-contact-page{margin-top:0!important;margin-block-start:0!important;padding-top:0!important}
body.digi-contact-page main .wp-block-group,
body.digi-contact-page .wp-block-post-content,
body.digi-contact-page .entry-content{--wp--style--global--content-size:100%;--wp--style--global--wide-size:100%;max-width:none!important;width:100%!important;padding-inline:0!important;padding-top:0!important;margin-inline:0!important;margin-top:0!important}
body.digi-contact-page .is-layout-constrained > :where(:not(.alignleft):not(.alignright):not(.alignfull)){max-width:none!important;margin-inline:0!important}
body.digi-contact-page .wp-block-post-content > p:has(.dcp-wrap){margin:0}

/* ── Base ─────────────────────────────── */
.dcp-wrap{direction:rtl;font-family:var(--digi-font,"Almarai",system-ui,sans-serif);color:var(--digi-ink,#f4edf8);background:var(--digi-bg,#0d0d0d);overflow:hidden;width:100%;margin-top:0}
.dcp-wrap *,.dcp-wrap *::before,.dcp-wrap *::after{box-sizing:border-box}
.dcp-wrap a{color:inherit;text-decoration:none}
.dcp-inner{max-width:1120px;margin:0 auto;padding:0 20px}

/* ── Hero ─────────────────────────────── */
.dcp-hero{padding:32px 20px 48px;text-align:center;background:radial-gradient(ellipse at 50% 0%,rgba(120,27,175,.22) 0%,transparent 68%)}
.dcp-hero__tag{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;background:rgba(120,27,175,.18);border:1px solid rgba(120,27,175,.3);color:#d4a8f0;font-size:.78rem;font-weight:700;letter-spacing:.06em;margin-bottom:18px}
.dcp-hero__tag .material-symbols-outlined{font-size:1rem}
.dcp-hero__title{margin:0 auto 14px;max-width:700px;font-size:clamp(2rem,5vw,3.6rem);line-height:1.12;letter-spacing:-.02em;font-weight:900}
.dcp-hero__title span{background:linear-gradient(135deg,#9b2dd4,#781baf);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.dcp-hero__sub{margin:0 auto 28px;max-width:500px;font-size:1rem;line-height:1.85;color:rgba(244,237,248,.7)}
.dcp-hero__actions{display:flex;justify-content:center;gap:12px;flex-wrap:wrap}
.dcp-btn{display:inline-flex;align-items:center;gap:8px;padding:13px 22px;border-radius:999px;font-size:.95rem;font-weight:700;cursor:pointer;border:0;font-family:inherit;transition:transform .15s,box-shadow .15s;text-decoration:none}
.dcp-btn:hover{transform:translateY(-2px)}
.dcp-btn--wa{background:linear-gradient(135deg,var(--digi-purple,#781baf),var(--digi-purple-2,#9b2dd4));color:#fff;box-shadow:0 10px 24px rgba(120,27,175,.28)}
.dcp-btn--wa:hover{box-shadow:0 14px 28px rgba(120,27,175,.34)}
.dcp-btn .material-symbols-outlined{font-size:1.1rem}

/* ── Channels ─────────────────────────── */
.dcp-channels{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;padding:0 20px 44px;max-width:1120px;margin:0 auto}
.dcp-channel-card{padding:20px;border-radius:18px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);display:flex;flex-direction:column;gap:8px;transition:border-color .2s,background .2s;cursor:pointer}
.dcp-channel-card:hover{background:rgba(120,27,175,.1);border-color:rgba(120,27,175,.3)}
.dcp-channel-icon{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;margin-bottom:2px}
.dcp-channel-icon .material-symbols-outlined{font-size:1.5rem}
.dcp-channel-title{font-size:.95rem;font-weight:800}
.dcp-channel-desc{font-size:.82rem;color:rgba(244,237,248,.6);line-height:1.55}
.dcp-badge{display:inline-flex;align-items:center;gap:5px;margin-top:4px;padding:4px 10px;border-radius:999px;font-size:.74rem;font-weight:700;width:fit-content}
.dcp-badge__dot{width:6px;height:6px;border-radius:50%;background:currentColor;flex-shrink:0}
.dcp-badge--green{background:rgba(34,197,94,.15);color:#4ade80;border:1px solid rgba(34,197,94,.2)}
.dcp-badge--purple{background:rgba(120,27,175,.2);color:#d4a8f0;border:1px solid rgba(120,27,175,.3)}
.dcp-badge--blue{background:rgba(59,130,246,.15);color:#93c5fd;border:1px solid rgba(59,130,246,.2)}
.dcp-badge--yellow{background:rgba(234,179,8,.15);color:#fde68a;border:1px solid rgba(234,179,8,.2)}

/* ── Divider ──────────────────────────── */
.dcp-divider{height:1px;background:linear-gradient(90deg,transparent,rgba(120,27,175,.25),transparent);max-width:1120px;margin:0 auto 44px}

/* ── Main (brand + form) ──────────────── */
.dcp-main{display:grid;grid-template-columns:300px 1fr;gap:20px;max-width:1120px;margin:0 auto;padding:0 20px 44px;align-items:start}
.dcp-brand{border-radius:22px;padding:22px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);display:flex;flex-direction:column;gap:14px}
.dcp-brand__logo{display:flex;align-items:center;gap:10px;font-size:1.15rem;font-weight:900;color:#fff}
.dcp-brand__logo-mark{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,#781baf,#9b2dd4);display:flex;align-items:center;justify-content:center;font-size:1.1rem;font-weight:900;color:#fff;flex-shrink:0}
.dcp-brand__stars{display:flex;align-items:center;gap:8px}
.dcp-brand__stars-row{display:flex;gap:1px;color:#f59e0b}
.dcp-brand__stars-row .material-symbols-outlined{font-size:.9rem}
.dcp-brand__rating{font-size:.84rem;color:rgba(244,237,248,.65)}
.dcp-brand__features{display:flex;flex-direction:column;gap:9px}
.dcp-brand__feat{display:flex;align-items:center;gap:9px;font-size:.86rem;color:rgba(244,237,248,.82)}
.dcp-brand__feat .material-symbols-outlined{font-size:1rem;color:#9b2dd4;flex-shrink:0}
.dcp-brand__customers{display:flex;align-items:center;gap:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,.07);font-size:.8rem;color:rgba(244,237,248,.6)}
.dcp-brand__avatars{display:flex}
.dcp-brand__av{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#781baf,#9b2dd4);border:2px solid #0d0d0d;margin-inline-end:-7px;display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;color:#fff}

/* ── Form panel ───────────────────────── */
.dcp-form-panel{border-radius:22px;padding:26px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09)}
.dcp-form-panel__title{margin:0 0 18px;font-size:1.4rem;font-weight:900;display:flex;align-items:center;gap:8px}
.dcp-form-panel__title .material-symbols-outlined{font-size:1.3rem;color:#9b2dd4}
.digtiali-contact-form{display:flex;flex-direction:column;gap:12px}
.dcp-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.dcp-field{display:flex;flex-direction:column;gap:5px}
.dcp-field label{font-size:.84rem;font-weight:700;color:rgba(244,237,248,.88);display:flex;align-items:center;gap:5px}
.dcp-field label .material-symbols-outlined{font-size:.9rem;color:#9b2dd4}
.dcp-field input,.dcp-field textarea,.dcp-field select{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:11px 14px;color:#f4edf8;font-size:.9rem;font-family:inherit;outline:none;width:100%;transition:border-color .18s,box-shadow .18s;-webkit-appearance:none;appearance:none}
.dcp-field select{cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'8\'%3E%3Cpath d=\'M1 1l5 5 5-5\' stroke=\'%239b2dd4\' stroke-width=\'1.5\' fill=\'none\' stroke-linecap=\'round\'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:left 12px center;padding-left:34px}
.dcp-field input::placeholder,.dcp-field textarea::placeholder{color:rgba(244,237,248,.28)}
.dcp-field input:focus,.dcp-field textarea:focus,.dcp-field select:focus{border-color:rgba(155,45,212,.55);box-shadow:0 0 0 3px rgba(155,45,212,.1)}
.dcp-field select option{background:#1a0a2e;color:#f4edf8}
.dcp-field textarea{min-height:110px;resize:vertical}
.digi-contact-form__field-error{font-size:.76rem;color:#f87171;min-height:14px}
.digi-contact-form__errors{font-size:.82rem;color:#f87171}
.dcp-form-submit{background:linear-gradient(135deg,#781baf,#9b2dd4);border:0;color:#fff;font-weight:800;font-size:.95rem;font-family:inherit;padding:13px 18px;border-radius:12px;cursor:pointer;box-shadow:0 10px 24px rgba(120,27,175,.28);display:flex;align-items:center;justify-content:center;gap:7px;transition:transform .15s,box-shadow .15s;width:100%}
.dcp-form-submit:hover{transform:translateY(-1px);box-shadow:0 14px 28px rgba(120,27,175,.34)}
.dcp-form-submit .material-symbols-outlined{font-size:1rem}
.digi-contact-form__success{padding:16px;border-radius:12px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.22);color:#4ade80;font-weight:700;text-align:center}
.dcp-form-privacy{display:flex;align-items:center;gap:5px;font-size:.76rem;color:rgba(244,237,248,.4);justify-content:center}
.dcp-form-privacy .material-symbols-outlined{font-size:.85rem}
/* ── intlTelInput phone field ──────────── */
.dcp-iti-search-wrap{list-style:none;padding:.45rem !important;margin:0 !important;border-radius:0 !important;background:#141014 !important;border-bottom:1px solid rgba(255,255,255,.07);cursor:default;position:sticky;top:0;z-index:2}
.dcp-iti-search-wrap:hover,.dcp-iti-search-wrap.iti__highlight{background:#141014 !important;color:inherit !important}
.dcp-iti-search{display:block;width:100%;box-sizing:border-box;min-height:36px;padding:.42rem .7rem !important;border:1px solid rgba(255,255,255,.12) !important;border-radius:8px !important;background:rgba(255,255,255,.07) !important;color:#fff !important;font-size:.84rem;font-family:"Almarai",system-ui,sans-serif;outline:none;-webkit-appearance:none;appearance:none;transition:border-color .15s}
.dcp-iti-search::placeholder{color:rgba(244,237,248,.38)}
.dcp-iti-search:focus{border-color:rgba(152,41,214,.55) !important;box-shadow:none !important}
/* ── intlTelInput (matches snippet 8 / checkout styling) ── */
.dcp-wrap .iti{display:block!important;width:100%;border:1px solid rgba(255,255,255,.12);border-radius:18px;background:rgba(255,255,255,.06);overflow:visible;transition:border-color .2s,box-shadow .2s;min-height:44px}
.dcp-wrap .iti:focus-within{border-color:rgba(152,41,214,.62);box-shadow:0 0 0 4px rgba(120,27,175,.2)}
.dcp-wrap .iti input[type=tel]{border:0!important;background:transparent!important;box-shadow:none!important;border-radius:18px!important;width:100%!important;box-sizing:border-box}
.dcp-wrap .iti--separate-dial-code input[type=tel]{padding-left:100px!important;padding-right:.875rem!important}
.dcp-wrap .iti__flag-container{bottom:0!important;left:0!important;right:auto!important}
.dcp-wrap .iti__selected-flag{background:rgba(255,255,255,.05)!important;border:0!important;border-inline-end:1px solid rgba(255,255,255,.1)!important;border-radius:18px 0 0 18px!important;padding:0 .65rem!important;min-height:44px;transition:background .15s}
.dcp-wrap .iti__selected-flag:hover{background:rgba(255,255,255,.1)!important}
[dir=rtl] .dcp-wrap .iti__flag-container{left:auto!important;right:0!important}
[dir=rtl] .dcp-wrap .iti--separate-dial-code input[type=tel]{padding-left:.875rem!important;padding-right:100px!important}
[dir=rtl] .dcp-wrap .iti input[type=tel]{border-radius:18px 0 0 18px!important}
[dir=rtl] .dcp-wrap .iti__selected-flag{border-radius:0 18px 18px 0!important;border-inline-end:0!important;border-inline-start:1px solid rgba(255,255,255,.1)!important}
[dir=rtl] .dcp-wrap .iti__country-list{text-align:right; width:240px!important;}
[dir=rtl] .dcp-wrap .iti__flag-box{margin-left:6px;margin-right:0}
[dir=rtl] .dcp-wrap .iti__country-name{margin-right:6px}
.dcp-wrap .iti__selected-dial-code{color:rgba(246,242,251,.9);font-size:.88rem;font-weight:700;font-family:"Almarai",system-ui,sans-serif}
.dcp-wrap .iti__arrow{border-top-color:rgba(246,242,251,.55)!important}
.dcp-wrap .iti__arrow--up{border-bottom-color:rgba(246,242,251,.55)!important;border-top:0!important}
.dcp-wrap .iti__country-list{z-index:99999!important;font-family:"Almarai",system-ui,sans-serif;font-size:.85rem;width:min(280px,calc(100vw - 32px))!important;min-width:min(280px,calc(100vw - 32px));max-width:calc(100vw - 32px);max-height:240px;border-radius:16px!important;border:1px solid rgba(255,255,255,.1)!important;box-shadow:0 16px 40px rgba(0,0,0,.55)!important;overflow-y:auto;background:#141014!important;padding:.35rem!important;scrollbar-width:thin;scrollbar-color:rgba(155,45,212,.35) transparent}
.dcp-wrap .iti__country-list::-webkit-scrollbar{width:5px}
.dcp-wrap .iti__country-list::-webkit-scrollbar-track{background:transparent}
.dcp-wrap .iti__country-list::-webkit-scrollbar-thumb{background:rgba(155,45,212,.35);border-radius:999px}
.dcp-wrap .iti__country-list::-webkit-scrollbar-thumb:hover{background:rgba(155,45,212,.55)}
.dcp-wrap .iti__country{padding:.45rem .75rem!important;border-radius:10px!important;color:rgba(246,242,251,.8)!important;transition:background .13s}
.dcp-wrap .iti__country.iti__highlight,.dcp-wrap .iti__country:hover{background:rgba(120,27,175,.18)!important;color:#9b2dd4!important}
.dcp-wrap .iti__divider{border-color:rgba(255,255,255,.08)!important;margin:.35rem!important}

/* ── Section heading ──────────────────── */
.dcp-section{max-width:1120px;margin:0 auto;padding:0 20px 44px}
.dcp-section__hd{text-align:center;margin-bottom:24px}
.dcp-section__title{margin:0;font-size:1.55rem;font-weight:900}

/* ── Why cards ────────────────────────── */
.dcp-why-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.dcp-why-card{padding:20px;border-radius:18px;background:rgba(120,27,175,.1);border:1px solid rgba(120,27,175,.2);display:flex;flex-direction:column;gap:9px}
.dcp-why-icon{width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center}
.dcp-why-icon .material-symbols-outlined{font-size:1.5rem;color:#fff}
.dcp-why-title{font-size:1rem;font-weight:800}
.dcp-why-desc{font-size:.84rem;color:rgba(244,237,248,.68);line-height:1.7}

/* ── FAQ ──────────────────────────────── */
.dcp-faq-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
details.dcp-faq-item{border-radius:13px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);overflow:hidden}
details.dcp-faq-item[open]{border-color:rgba(120,27,175,.3)}
.dcp-faq-q{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 16px;cursor:pointer;font-size:.88rem;font-weight:700;user-select:none;list-style:none}
.dcp-faq-q::-webkit-details-marker{display:none}
.dcp-faq-q-text{display:flex;align-items:center;gap:8px}
.dcp-faq-q-text .material-symbols-outlined{font-size:1rem;color:#9b2dd4;flex-shrink:0}
.dcp-faq-chevron{font-size:1rem;color:rgba(244,237,248,.45);transition:transform .2s;flex-shrink:0}
details.dcp-faq-item[open] .dcp-faq-chevron{transform:rotate(180deg)}
.dcp-faq-a{padding:0 16px 14px;font-size:.84rem;color:rgba(244,237,248,.68);line-height:1.75;border-top:1px solid rgba(255,255,255,.06);padding-top:10px}

/* ── CTA banner ───────────────────────── */
.dcp-cta-wrap{padding:0 20px 44px;max-width:1120px;margin:0 auto}
.dcp-cta{border-radius:22px;background:linear-gradient(135deg,rgba(120,27,175,.3),rgba(155,45,212,.18));border:1px solid rgba(120,27,175,.32);padding:28px 32px}
.dcp-cta-inner{display:flex;align-items:center;gap:20px}
.dcp-cta-ico{width:58px;height:58px;border-radius:16px;background:rgba(120,27,175,.28);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dcp-cta-ico .material-symbols-outlined{font-size:1.8rem;color:#d4a8f0}
.dcp-cta-body{flex:1}
.dcp-cta-title{margin:0 0 5px;font-size:1.3rem;font-weight:900}
.dcp-cta-sub{margin:0;font-size:.86rem;color:rgba(244,237,248,.7);line-height:1.6}

/* ── Footer bar ───────────────────────── */
.dcp-footer-bar{background:rgba(255,255,255,.03);border-top:1px solid rgba(255,255,255,.08);padding:22px 20px}
.dcp-footer-inner{max-width:1120px;margin:0 auto;display:flex;justify-content:center;gap:44px;flex-wrap:wrap}
.dcp-footer-item{display:flex;align-items:center;gap:11px}
.dcp-footer-ico{width:42px;height:42px;border-radius:11px;background:rgba(120,27,175,.16);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dcp-footer-ico .material-symbols-outlined{font-size:1.2rem;color:#9b2dd4}
.dcp-footer-item small{display:block;font-size:.74rem;color:rgba(244,237,248,.45);margin-bottom:1px}
.dcp-footer-item a{font-size:.88rem;font-weight:700;color:#f4edf8}
.dcp-footer-item a:hover{color:#9b2dd4}

/* ── Toast ────────────────────────────── */
.digtiali-contact-form__toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);opacity:0;z-index:99999;min-width:min(90vw,400px);padding:14px 20px;border-radius:14px;background:rgba(13,13,13,.96);color:#f4edf8;border:1px solid rgba(155,45,212,.22);box-shadow:0 16px 40px rgba(0,0,0,.35);pointer-events:none;transition:opacity .22s,transform .22s;display:flex;align-items:center;gap:10px;font-size:.9rem;font-weight:600}
.digtiali-contact-form__toast.is-visible{opacity:1;transform:translateX(-50%) translateY(0)}
.digtiali-contact-form__toast[data-type="success"]{border-color:rgba(34,197,94,.3);background:rgba(11,30,20,.96)}
.digtiali-contact-form__toast[data-type="success"]::before{content:"check_circle";font-family:"Material Symbols Outlined";font-size:1.2rem;color:#4ade80;font-weight:400}
.digtiali-contact-form__toast[data-type="error"]{border-color:rgba(239,68,68,.3);background:rgba(28,10,10,.96)}
.digtiali-contact-form__toast[data-type="error"]::before{content:"error";font-family:"Material Symbols Outlined";font-size:1.2rem;color:#f87171;font-weight:400}

/* ── Light mode (matches thankyou / checkout) ── */
[data-theme="light"] body.digi-contact-page .wp-site-blocks,
[data-theme="light"] .dcp-wrap{background:radial-gradient(960px 420px at 50% -120px,rgba(120,27,175,.12),transparent 70%),linear-gradient(180deg,rgba(255,255,255,.92),rgba(246,240,251,.96)),#f6f0fb;color:#1f1630}
[data-theme="light"] .dcp-hero{background:radial-gradient(ellipse at 50% 0%,rgba(120,27,175,.10) 0%,transparent 68%)}
[data-theme="light"] .dcp-hero__tag{background:rgba(120,27,175,.10);border-color:rgba(120,27,175,.22);color:#781baf}
[data-theme="light"] .dcp-hero__title{color:#1f1630}
[data-theme="light"] .dcp-hero__sub{color:#4a3d5c}
[data-theme="light"] .dcp-channel-card{background:#fff;border-color:#eadff4;box-shadow:0 10px 30px rgba(71,35,108,.06)}
[data-theme="light"] .dcp-channel-card:hover{background:#faf5ff;border-color:rgba(120,27,175,.28)}
[data-theme="light"] .dcp-channel-title{color:#1f1630}
[data-theme="light"] .dcp-channel-desc{color:#6b5f7d}
[data-theme="light"] .dcp-badge--green{background:rgba(30,154,87,.12);color:#15803d;border-color:rgba(30,154,87,.22)}
[data-theme="light"] .dcp-badge--purple{background:rgba(120,27,175,.10);color:#781baf;border-color:rgba(120,27,175,.22)}
[data-theme="light"] .dcp-badge--blue{background:rgba(59,130,246,.12);color:#1d4ed8;border-color:rgba(59,130,246,.22)}
[data-theme="light"] .dcp-badge--yellow{background:rgba(234,179,8,.14);color:#a16207;border-color:rgba(234,179,8,.24)}
[data-theme="light"] .dcp-divider{background:linear-gradient(90deg,transparent,rgba(120,27,175,.18),transparent)}
[data-theme="light"] .dcp-brand,
[data-theme="light"] .dcp-form-panel{background:linear-gradient(180deg,#fff,#fbf9ff);border-color:#eadff4;box-shadow:0 16px 40px rgba(71,35,108,.07)}
[data-theme="light"] .dcp-brand__logo{color:#1f1630}
[data-theme="light"] .dcp-brand__rating{color:#6b5f7d}
[data-theme="light"] .dcp-brand__feat{color:#4a3d5c}
[data-theme="light"] .dcp-brand__customers{border-top-color:#eadff4;color:#6b5f7d}
[data-theme="light"] .dcp-brand__av{border-color:#f6f0fb}
[data-theme="light"] .dcp-form-panel__title{color:#1f1630}
[data-theme="light"] .dcp-field label{color:#4a3d5c}
[data-theme="light"] .dcp-field label small{color:#6b5f7d!important}
[data-theme="light"] .dcp-field input,
[data-theme="light"] .dcp-field textarea,
[data-theme="light"] .dcp-field select{background:#fff;border-color:#dccde9;color:#1f1630;box-shadow:inset 0 1px 2px rgba(34,17,51,.03)}
[data-theme="light"] .dcp-field input::placeholder,
[data-theme="light"] .dcp-field textarea::placeholder{color:#6b5f7d}
[data-theme="light"] .dcp-field input:focus,
[data-theme="light"] .dcp-field textarea:focus,
[data-theme="light"] .dcp-field select:focus{border-color:rgba(120,27,175,.45);box-shadow:0 0 0 3px rgba(120,27,175,.12)}
[data-theme="light"] .dcp-field select option{background:#fff;color:#1f1630}
[data-theme="light"] .digi-contact-form__success{background:rgba(30,154,87,.10);border-color:rgba(30,154,87,.22);color:#15803d}
[data-theme="light"] .dcp-form-privacy{color:#6b5f7d}
[data-theme="light"] .dcp-section__title{color:#1f1630}
[data-theme="light"] .dcp-why-card{background:rgba(120,27,175,.06);border-color:rgba(120,27,175,.16)}
[data-theme="light"] .dcp-why-title{color:#1f1630}
[data-theme="light"] .dcp-why-desc{color:#6b5f7d}
[data-theme="light"] details.dcp-faq-item{background:#fff;border-color:#eadff4}
[data-theme="light"] details.dcp-faq-item[open]{border-color:rgba(120,27,175,.28);background:#faf5ff}
[data-theme="light"] .dcp-faq-q{color:#1f1630}
[data-theme="light"] .dcp-faq-chevron{color:#6b5f7d}
[data-theme="light"] .dcp-faq-a{color:#4a3d5c;border-top-color:#eadff4}
[data-theme="light"] .dcp-cta{background:linear-gradient(135deg,rgba(120,27,175,.10),rgba(155,45,212,.06));border-color:rgba(120,27,175,.22)}
[data-theme="light"] .dcp-cta-ico{background:rgba(120,27,175,.12)}
[data-theme="light"] .dcp-cta-ico .material-symbols-outlined{color:#781baf}
[data-theme="light"] .dcp-cta-title{color:#1f1630}
[data-theme="light"] .dcp-cta-sub{color:#4a3d5c}
[data-theme="light"] .dcp-footer-bar{background:rgba(255,255,255,.72);border-top-color:#eadff4}
[data-theme="light"] .dcp-footer-ico{background:rgba(120,27,175,.10)}
[data-theme="light"] .dcp-footer-item small{color:#6b5f7d}
[data-theme="light"] .dcp-footer-item a{color:#1f1630}
[data-theme="light"] .digtiali-contact-form__toast{background:rgba(255,255,255,.98);color:#1f1630;border-color:#eadff4;box-shadow:0 16px 40px rgba(71,35,108,.12)}
[data-theme="light"] .digtiali-contact-form__toast[data-type="success"]{background:#f0fdf4;border-color:rgba(30,154,87,.28)}
[data-theme="light"] .digtiali-contact-form__toast[data-type="success"]::before{color:#15803d}
[data-theme="light"] .digtiali-contact-form__toast[data-type="error"]{background:#fff5f5;border-color:rgba(239,68,68,.28)}
[data-theme="light"] .dcp-iti-search-wrap{background:#fff!important;border-bottom-color:#eadff4!important}
[data-theme="light"] .dcp-iti-search{background:#fff!important;border-color:#dccde9!important;color:#1f1630!important}
[data-theme="light"] .dcp-iti-search::placeholder{color:#6b5f7d}
[data-theme="light"] .dcp-wrap .iti{border-color:#dccde9;background:#fff}
[data-theme="light"] .dcp-wrap .iti:focus-within{border-color:rgba(120,27,175,.45);box-shadow:0 0 0 4px rgba(120,27,175,.12)}
[data-theme="light"] .dcp-wrap .iti__selected-flag{background:#faf5ff!important;border-inline-end-color:#eadff4!important}
[data-theme="light"] .dcp-wrap .iti__selected-flag:hover{background:#f3e8ff!important}
[data-theme="light"] .dcp-wrap .iti__selected-dial-code{color:#1f1630}
[data-theme="light"] .dcp-wrap .iti__arrow{border-top-color:#6b5f7d!important}
[data-theme="light"] .dcp-wrap .iti__arrow--up{border-bottom-color:#6b5f7d!important}
[data-theme="light"] .dcp-wrap .iti__country-list{background:#fff!important;border-color:#eadff4!important;box-shadow:0 16px 40px rgba(71,35,108,.12)!important}
[data-theme="light"] .dcp-wrap .iti__country{color:#4a3d5c!important}
[data-theme="light"] .dcp-wrap .iti__country.iti__highlight,
[data-theme="light"] .dcp-wrap .iti__country:hover{background:rgba(120,27,175,.08)!important;color:#781baf!important}
[data-theme="light"] .dcp-wrap .iti__divider{border-color:#eadff4!important}

/* ── Responsive ───────────────────────── */
@media(max-width:1024px){.dcp-main{grid-template-columns:1fr}}
@media(max-width:768px){.dcp-channels{grid-template-columns:1fr}}
@media(max-width:768px){.dcp-hero{padding:24px 20px 36px}.dcp-hero__title{font-size:2rem}.dcp-faq-grid{grid-template-columns:1fr}.dcp-why-grid{grid-template-columns:1fr}.dcp-cta-inner{flex-direction:column;text-align:center}.dcp-footer-inner{gap:22px}.dcp-form-row{grid-template-columns:1fr}}
@media(max-width:480px){.dcp-channels{grid-template-columns:1fr}}
</style>' . "\n";
}

function digtiali_contact_inline_footer_assets(): void {
	if ( is_admin() ) {
		return;
	}
	global $post;
	if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( (string) $post->post_content, 'digtiali_contact_form' ) ) {
		return;
	}
	$iti_base    = 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8';
	$only        = wp_json_encode( array( 'sa','ae','eg','tr','sy','iq','jo','kw','qa','bh','om','lb','ma','dz','tn','ly','ye','ps','de','se','dk','at','nl','gb','fr','us','be','no' ) );
	$local       = wp_json_encode( array(
		'sa' => __( 'السعودية', 'digtiali-contact-form' ),
		'eg' => __( 'مصر', 'digtiali-contact-form' ),
		'sy' => __( 'سوريا', 'digtiali-contact-form' ),
		'iq' => __( 'العراق', 'digtiali-contact-form' ),
		'jo' => __( 'الأردن', 'digtiali-contact-form' ),
		'kw' => __( 'الكويت', 'digtiali-contact-form' ),
		'ae' => __( 'الإمارات', 'digtiali-contact-form' ),
		'qa' => __( 'قطر', 'digtiali-contact-form' ),
		'bh' => __( 'البحرين', 'digtiali-contact-form' ),
		'om' => __( 'عُمان', 'digtiali-contact-form' ),
		'lb' => __( 'لبنان', 'digtiali-contact-form' ),
		'ma' => __( 'المغرب', 'digtiali-contact-form' ),
		'dz' => __( 'الجزائر', 'digtiali-contact-form' ),
		'tn' => __( 'تونس', 'digtiali-contact-form' ),
		'ly' => __( 'ليبيا', 'digtiali-contact-form' ),
		'ye' => __( 'اليمن', 'digtiali-contact-form' ),
		'ps' => __( 'فلسطين', 'digtiali-contact-form' ),
		'de' => __( 'ألمانيا', 'digtiali-contact-form' ),
		'se' => __( 'السويد', 'digtiali-contact-form' ),
		'dk' => __( 'الدنمارك', 'digtiali-contact-form' ),
		'at' => __( 'النمسا', 'digtiali-contact-form' ),
		'nl' => __( 'هولندا', 'digtiali-contact-form' ),
		'gb' => __( 'بريطانيا', 'digtiali-contact-form' ),
		'fr' => __( 'فرنسا', 'digtiali-contact-form' ),
		'us' => __( 'أمريكا', 'digtiali-contact-form' ),
		'be' => __( 'بلجيكا', 'digtiali-contact-form' ),
		'no' => __( 'النرويج', 'digtiali-contact-form' ),
		'tr' => __( 'تركيا', 'digtiali-contact-form' ),
	), JSON_UNESCAPED_UNICODE );
	$js_i18n     = wp_json_encode( array(
		'successLong'   => __( 'تم إرسال رسالتك بنجاح، سنرد عليك قريباً', 'digtiali-contact-form' ),
		'successShort'  => __( 'تم إرسال رسالتك بنجاح', 'digtiali-contact-form' ),
		'searchCountry' => __( 'بحث عن دولة...', 'digtiali-contact-form' ),
		'dupBlocked'    => __( 'تم منع الإرسال المكرر.', 'digtiali-contact-form' ),
		'submitFailed'  => __( 'فشل الإرسال.', 'digtiali-contact-form' ),
	), JSON_UNESCAPED_UNICODE );
	?>
	<script src="<?php echo esc_url( $iti_base . '/js/intlTelInput.min.js' ); ?>"></script>
	<script>
	window.DigiContactI18n=<?php echo $js_i18n; ?>;
	(function(){
		function showToast(message,type){
			var toast=document.querySelector('.digtiali-contact-form__toast');
			if(!toast){
				toast=document.createElement('div');
				toast.className='digtiali-contact-form__toast';
				toast.setAttribute('role','status');
				toast.setAttribute('aria-live','polite');
				document.body.appendChild(toast);
			}
			toast.textContent=message;
			toast.dataset.type=type||'info';
			toast.classList.add('is-visible');
			clearTimeout(window.__digiContactToastTimer);
			window.__digiContactToastTimer=setTimeout(function(){toast.classList.remove('is-visible');},3500);
		}
		function submitContactForm(form){
			var fd=new FormData(form);
			fd.set("action","digtiali_contact_submit");
			fd.set("nonce",form.getAttribute("data-nonce"));
			var btn=form.querySelector(".digi-contact-form__submit");
			if(btn)btn.disabled=true;
			fetch(form.getAttribute("data-ajax-url"),{method:"POST",credentials:"same-origin",body:fd})
				.then(function(r){return r.json().then(function(d){return{status:r.status,data:d};});})
				.then(function(res){
					if(btn)btn.disabled=false;
					if(res.status===409){showToast((res.data&&res.data.data&&res.data.data.message)||window.DigiContactI18n.dupBlocked,"error");return;}
					if(res.data&&res.data.success){
						var wrap=form.querySelector('.dcp-fields-wrap');
						var successEl=form.querySelector('.digi-contact-form__success');
						if(wrap)wrap.style.display='none';
						if(successEl){successEl.removeAttribute('hidden');successEl.style.display='';successEl.textContent=(res.data.data&&res.data.data.message)||window.DigiContactI18n.successLong;}
						showToast((res.data.data&&res.data.data.message)||window.DigiContactI18n.successShort,'success');
						return;
					}
					showToast((res.data&&res.data.data&&res.data.data.message)||window.DigiContactI18n.submitFailed,'error');
				})
				.catch(function(){if(btn)btn.disabled=false;showToast(window.DigiContactI18n.submitFailed,'error');});
		}
		function initIti(phone){
			if(!phone||phone.dataset.itiDone||typeof window.intlTelInput!=="function")return null;
			phone.dataset.itiDone="1";
			phone.style.direction="ltr";
			return window.intlTelInput(phone,{
				initialCountry:"auto",
				separateDialCode:true,
				preferredCountries:["sa","ae","eg","tr"],
				onlyCountries:<?php echo $only; ?>,
				localizedCountries:<?php echo $local; ?>,
				utilsScript:"<?php echo esc_js( $iti_base . '/js/utils.js' ); ?>",
				customPlaceholder:function(){return"";},
				geoIpLookup:function(cb){
					fetch("https://ipinfo.io/json?token=da4ad24365ecba")
						.then(function(r){return r.json();})
						.then(function(d){cb(d&&d.country?d.country.toLowerCase():"sa");})
						.catch(function(){cb("sa");});
				}
			});
		}
		function addItiSearch(phone){
			setTimeout(function(){
				var wrap=phone&&phone.closest('.iti');
				var list=wrap&&wrap.querySelector('.iti__country-list');
				if(!list||list.querySelector('.dcp-iti-search'))return;
				var li=document.createElement('li');
				li.className='dcp-iti-search-wrap';
				var inp=document.createElement('input');
				inp.type='text';
				inp.className='dcp-iti-search';
				inp.placeholder=window.DigiContactI18n.searchCountry;
				inp.setAttribute('dir','rtl');
				li.appendChild(inp);
				list.insertBefore(li,list.firstChild);
				function filter(){
					var q=inp.value.trim().toLowerCase();
					list.querySelectorAll('.iti__country').forEach(function(c){
						if(!q){c.style.display='';return;}
						var name=(c.querySelector('.iti__country-name')||{}).textContent||'';
						c.style.display=name.toLowerCase().includes(q)?'':'none';
					});
					list.querySelectorAll('.iti__divider').forEach(function(d){d.style.display=q?'none':'';});
				}
				inp.addEventListener('input',filter);
				inp.addEventListener('keyup',filter);
				['keydown','keypress','mousedown','click'].forEach(function(ev){
					inp.addEventListener(ev,function(e){e.stopPropagation();});
				});
				// Auto-focus search when dropdown opens (watch class toggle on the list)
				new MutationObserver(function(muts){
					muts.forEach(function(m){
						if(m.attributeName==='class'&&!list.classList.contains('iti__hide')){
							inp.value='';
							filter();
							requestAnimationFrame(function(){ inp.focus(); });
						}
					});
				}).observe(list,{attributes:true,attributeFilter:['class']});
			},120);
		}
		function initContactForm(){
			var form=document.querySelector('.digtiali-contact-form');
			if(!form||form.dataset.submitReady==="1")return;
			form.dataset.submitReady="1";
			var phone=form.querySelector('input[name="phone"]');
			var iti=initIti(phone);
			if(iti)addItiSearch(phone);
			form.addEventListener("submit",function(ev){
				ev.preventDefault();
				if(iti&&typeof iti.getNumber==="function"){var n=iti.getNumber();if(n&&phone)phone.value=n;}
				submitContactForm(form);
			});
		}
		if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",initContactForm);}
		else{initContactForm();}
		window.addEventListener("load",initContactForm);
	})();
	</script>
	<?php
}

function digtiali_contact_form_shortcode(): string {
	$ajax_url = admin_url( 'admin-ajax.php' );
	$nonce    = wp_create_nonce( 'digtiali_contact_nonce' );
	ob_start();
	?>
	<div class="dcp-wrap">

		<!-- Hero -->
		<div class="dcp-hero">
			<div class="dcp-hero__tag">
				<span class="material-symbols-outlined" aria-hidden="true">support_agent</span>
				<?php esc_html_e( 'فريق الدعم جاهز', 'digtiali-contact-form' ); ?>
			</div>
			<h1 class="dcp-hero__title"><?php esc_html_e( 'تواصل معنا،', 'digtiali-contact-form' ); ?><br><?php esc_html_e( 'وسنساعدك خلال ', 'digtiali-contact-form' ); ?><span><?php esc_html_e( 'دقائق', 'digtiali-contact-form' ); ?></span></h1>
			<p class="dcp-hero__sub"><?php esc_html_e( 'فريق Digtiali جاهز للإجابة على استفساراتك المتعلقة بالمنتجات الرقمية والدعم الفني والاشتراكات.', 'digtiali-contact-form' ); ?></p>
			<div class="dcp-hero__actions">
				<a href="https://wa.me/19294462772" class="dcp-btn dcp-btn--wa" target="_blank" rel="noopener">
					<span class="material-symbols-outlined" aria-hidden="true">chat</span>
					<?php esc_html_e( 'تواصل عبر واتساب', 'digtiali-contact-form' ); ?>
				</a>
			</div>
		</div>

		<!-- Support channels -->
		<div class="dcp-channels">
			<a href="https://wa.me/19294462772" class="dcp-channel-card" target="_blank" rel="noopener">
				<div class="dcp-channel-icon" style="background:rgba(37,211,102,.15)"><span class="material-symbols-outlined" style="color:#25d366" aria-hidden="true">chat</span></div>
				<div class="dcp-channel-title">WhatsApp Support</div>
				<div class="dcp-channel-desc"><?php esc_html_e( 'رد سريع خلال ساعات العمل', 'digtiali-contact-form' ); ?></div>
				<span class="dcp-badge dcp-badge--green"><span class="dcp-badge__dot"></span><?php esc_html_e( 'متاح الآن', 'digtiali-contact-form' ); ?></span>
			</a>
			<a href="mailto:support@digtiali.com" class="dcp-channel-card">
				<div class="dcp-channel-icon" style="background:rgba(120,27,175,.2)"><span class="material-symbols-outlined" style="color:#9b2dd4" aria-hidden="true">mail</span></div>
				<div class="dcp-channel-title">Email Support</div>
				<div class="dcp-channel-desc">support@digtiali.com</div>
				<span class="dcp-badge dcp-badge--purple">24/7</span>
			</a>
			<a href="#dcp-faq" class="dcp-channel-card">
				<div class="dcp-channel-icon" style="background:rgba(234,179,8,.15)"><span class="material-symbols-outlined" style="color:#fbbf24" aria-hidden="true">help</span></div>
				<div class="dcp-channel-title">FAQ Center</div>
				<div class="dcp-channel-desc"><?php esc_html_e( 'إجابات للأسئلة الشائعة', 'digtiali-contact-form' ); ?></div>
				<span class="dcp-badge dcp-badge--yellow"><?php esc_html_e( 'عرض الأسئلة', 'digtiali-contact-form' ); ?></span>
			</a>
		</div>

		<div class="dcp-divider"></div>

		<!-- Brand + Form -->
		<div class="dcp-main" id="dcp-form">
			<div class="dcp-brand">
				<div class="dcp-brand__logo">
					<div class="dcp-brand__logo-mark">D</div>
					Digtiali
				</div>
				<div class="dcp-brand__stars">
					<div class="dcp-brand__stars-row">
						<span class="material-symbols-outlined" aria-hidden="true">star</span>
						<span class="material-symbols-outlined" aria-hidden="true">star</span>
						<span class="material-symbols-outlined" aria-hidden="true">star</span>
						<span class="material-symbols-outlined" aria-hidden="true">star</span>
						<span class="material-symbols-outlined" aria-hidden="true">star_half</span>
					</div>
					<span class="dcp-brand__rating"><?php esc_html_e( '4.9/5 تقييم العملاء', 'digtiali-contact-form' ); ?></span>
				</div>
				<div class="dcp-brand__features">
					<div class="dcp-brand__feat"><span class="material-symbols-outlined" aria-hidden="true">verified</span><?php esc_html_e( 'منتجات أصلية 100%', 'digtiali-contact-form' ); ?></div>
					<div class="dcp-brand__feat"><span class="material-symbols-outlined" aria-hidden="true">bolt</span><?php esc_html_e( 'دعم سريع ومحترف', 'digtiali-contact-form' ); ?></div>
					<div class="dcp-brand__feat"><span class="material-symbols-outlined" aria-hidden="true">local_shipping</span><?php esc_html_e( 'تسليم فوري وسهل', 'digtiali-contact-form' ); ?></div>
					<div class="dcp-brand__feat"><span class="material-symbols-outlined" aria-hidden="true">inventory_2</span><?php esc_html_e( 'أكثر من 100+ منتج رقمي', 'digtiali-contact-form' ); ?></div>
					<div class="dcp-brand__feat"><span class="material-symbols-outlined" aria-hidden="true">shield</span><?php esc_html_e( 'خدمة موثوقة وأمينة', 'digtiali-contact-form' ); ?></div>
				</div>
				<div class="dcp-brand__customers">
					<div class="dcp-brand__avatars">
						<div class="dcp-brand__av"><?php esc_html_e( 'م', 'digtiali-contact-form' ); ?></div>
						<div class="dcp-brand__av"><?php esc_html_e( 'أ', 'digtiali-contact-form' ); ?></div>
						<div class="dcp-brand__av"><?php esc_html_e( 'س', 'digtiali-contact-form' ); ?></div>
						<div class="dcp-brand__av"><?php esc_html_e( 'ع', 'digtiali-contact-form' ); ?></div>
					</div>
					<span>
						<span class="material-symbols-outlined" style="font-size:.85rem;color:#f87171;vertical-align:middle" aria-hidden="true">favorite</span>
						<?php esc_html_e( 'آلاف العملاء يثقون بنا', 'digtiali-contact-form' ); ?>
					</span>
				</div>
			</div>

			<div class="dcp-form-panel">
				<h2 class="dcp-form-panel__title">
					<span class="material-symbols-outlined" aria-hidden="true">edit_note</span>
					<?php esc_html_e( 'أرسل لنا رسالة', 'digtiali-contact-form' ); ?>
				</h2>
				<form class="digtiali-contact-form" action="<?php echo esc_url( $ajax_url ); ?>" method="post" data-ajax-url="<?php echo esc_attr( $ajax_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" novalidate>
					<input type="hidden" name="action" value="digtiali_contact_submit" />
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<div class="dcp-fields-wrap">
						<div class="dcp-form-row" style="margin-bottom:12px">
							<div class="dcp-field">
								<label for="digi-contact-name"><span class="material-symbols-outlined" aria-hidden="true">person</span><?php esc_html_e( 'الاسم الكامل', 'digtiali-contact-form' ); ?></label>
								<input id="digi-contact-name" type="text" name="name" required placeholder="<?php esc_attr_e( 'اسمك الكامل', 'digtiali-contact-form' ); ?>" />
								<div class="digi-contact-form__field-error" data-error-for="name"></div>
							</div>
							<div class="dcp-field">
								<label for="digi-contact-email"><span class="material-symbols-outlined" aria-hidden="true">mail</span><?php esc_html_e( 'البريد الإلكتروني', 'digtiali-contact-form' ); ?></label>
								<input id="digi-contact-email" type="email" name="email" required placeholder="you@example.com" />
								<div class="digi-contact-form__field-error" data-error-for="email"></div>
							</div>
						</div>
						<div class="dcp-field" style="margin-bottom:12px">
							<label for="digi-contact-phone"><span class="material-symbols-outlined" aria-hidden="true">phone</span><?php esc_html_e( 'رقم الهاتف', 'digtiali-contact-form' ); ?> <small style="font-weight:400;color:rgba(244,237,248,.38)">(<?php esc_html_e( 'اختياري', 'digtiali-contact-form' ); ?>)</small></label>
							<input id="digi-contact-phone" type="tel" name="phone" placeholder="+966..." />
							<div class="digi-contact-form__field-error" data-error-for="phone"></div>
						</div>
						<div class="dcp-field" style="margin-bottom:12px">
							<label for="digi-contact-subject"><span class="material-symbols-outlined" aria-hidden="true">category</span><?php esc_html_e( 'نوع الطلب', 'digtiali-contact-form' ); ?></label>
							<select id="digi-contact-subject" name="subject" required>
								<option value="" disabled selected><?php esc_html_e( 'اختر نوع الطلب', 'digtiali-contact-form' ); ?></option>
								<option value="استفسار عام"><?php esc_html_e( 'استفسار عام', 'digtiali-contact-form' ); ?></option>
								<option value="دعم تقني"><?php esc_html_e( 'دعم تقني', 'digtiali-contact-form' ); ?></option>
								<option value="مشكلة في الطلب"><?php esc_html_e( 'مشكلة في الطلب', 'digtiali-contact-form' ); ?></option>
								<option value="الاشتراكات والتجديد"><?php esc_html_e( 'الاشتراكات والتجديد', 'digtiali-contact-form' ); ?></option>
								<option value="أخرى"><?php esc_html_e( 'أخرى', 'digtiali-contact-form' ); ?></option>
							</select>
							<div class="digi-contact-form__field-error" data-error-for="subject"></div>
						</div>
						<div class="dcp-field" style="margin-bottom:12px">
							<label for="digi-contact-message"><span class="material-symbols-outlined" aria-hidden="true">edit</span><?php esc_html_e( 'رسالتك', 'digtiali-contact-form' ); ?></label>
							<textarea id="digi-contact-message" name="message" required placeholder="<?php esc_attr_e( 'رسالتك...', 'digtiali-contact-form' ); ?>"></textarea>
							<div class="digi-contact-form__field-error" data-error-for="message"></div>
						</div>
						<div class="digi-contact-form__errors" aria-live="polite"></div>
						<button type="submit" class="dcp-form-submit digi-contact-form__submit">
							<span class="material-symbols-outlined" aria-hidden="true">send</span>
							<?php esc_html_e( 'إرسال الرسالة', 'digtiali-contact-form' ); ?>
						</button>
						<div class="dcp-form-privacy" style="margin-top:10px">
							<span class="material-symbols-outlined" aria-hidden="true">lock</span>
							<?php esc_html_e( 'معلوماتك آمنة ولن نشاركها مع أي طرف ثالث', 'digtiali-contact-form' ); ?>
						</div>
					</div>
					<div class="digi-contact-form__success" hidden aria-live="polite"></div>
				</form>
			</div>
		</div>

		<div class="dcp-divider"></div>

		<!-- FAQ -->
		<div class="dcp-section" id="dcp-faq">
			<div class="dcp-section__hd"><h2 class="dcp-section__title"><?php esc_html_e( 'الأسئلة الشائعة', 'digtiali-contact-form' ); ?></h2></div>
			<div class="dcp-faq-grid">
				<details class="dcp-faq-item">
					<summary class="dcp-faq-q"><span class="dcp-faq-q-text"><span class="material-symbols-outlined" aria-hidden="true">sync</span><?php esc_html_e( 'كيف أجدد الاشتراك بعد انتهائه؟', 'digtiali-contact-form' ); ?></span><span class="material-symbols-outlined dcp-faq-chevron" aria-hidden="true">expand_more</span></summary>
					<div class="dcp-faq-a"><?php esc_html_e( 'يمكنك تجديد اشتراكك من خلال صفحة "اشتراكاتي" في حسابك أو بالتواصل معنا مباشرة عبر واتساب.', 'digtiali-contact-form' ); ?></div>
				</details>
				<details class="dcp-faq-item">
					<summary class="dcp-faq-q"><span class="dcp-faq-q-text"><span class="material-symbols-outlined" aria-hidden="true">check_circle</span><?php esc_html_e( 'كيف أستلم المنتج بعد الشراء؟', 'digtiali-contact-form' ); ?></span><span class="material-symbols-outlined dcp-faq-chevron" aria-hidden="true">expand_more</span></summary>
					<div class="dcp-faq-a"><?php esc_html_e( 'يُرسَل المنتج فوراً إلى بريدك الإلكتروني بعد تأكيد الدفع، ويمكنك أيضاً الوصول إليه من حسابك.', 'digtiali-contact-form' ); ?></div>
				</details>
				<details class="dcp-faq-item">
					<summary class="dcp-faq-q"><span class="dcp-faq-q-text"><span class="material-symbols-outlined" aria-hidden="true">calendar_month</span><?php esc_html_e( 'كم مدة تفعيل المنتجات؟', 'digtiali-contact-form' ); ?></span><span class="material-symbols-outlined dcp-faq-chevron" aria-hidden="true">expand_more</span></summary>
					<div class="dcp-faq-a"><?php esc_html_e( 'تُفعَّل معظم المنتجات فور إتمام عملية الدفع. في حالات نادرة قد تستغرق حتى 24 ساعة.', 'digtiali-contact-form' ); ?></div>
				</details>
				<details class="dcp-faq-item">
					<summary class="dcp-faq-q"><span class="dcp-faq-q-text"><span class="material-symbols-outlined" aria-hidden="true">security</span><?php esc_html_e( 'هل التراخيص أصلية وآمنة؟', 'digtiali-contact-form' ); ?></span><span class="material-symbols-outlined dcp-faq-chevron" aria-hidden="true">expand_more</span></summary>
					<div class="dcp-faq-a"><?php esc_html_e( 'نعم، جميع تراخيصنا أصلية 100% ومرخصة مباشرة من الشركات المطورة.', 'digtiali-contact-form' ); ?></div>
				</details>
				<details class="dcp-faq-item">
					<summary class="dcp-faq-q"><span class="dcp-faq-q-text"><span class="material-symbols-outlined" aria-hidden="true">replay</span><?php esc_html_e( 'هل يوجد استرجاع أو استبدال؟', 'digtiali-contact-form' ); ?></span><span class="material-symbols-outlined dcp-faq-chevron" aria-hidden="true">expand_more</span></summary>
					<div class="dcp-faq-a"><?php esc_html_e( 'نعم، نوفر سياسة استرجاع واضحة. يرجى مراجعة صفحة سياسة الاسترداد للتفاصيل الكاملة.', 'digtiali-contact-form' ); ?></div>
				</details>
				<details class="dcp-faq-item">
					<summary class="dcp-faq-q"><span class="dcp-faq-q-text"><span class="material-symbols-outlined" aria-hidden="true">headphones</span><?php esc_html_e( 'هل نقدم دعم بعد الشراء؟', 'digtiali-contact-form' ); ?></span><span class="material-symbols-outlined dcp-faq-chevron" aria-hidden="true">expand_more</span></summary>
					<div class="dcp-faq-a"><?php esc_html_e( 'بالتأكيد! نقدم دعماً كاملاً بعد الشراء عبر واتساب والبريد الإلكتروني وتذاكر الدعم.', 'digtiali-contact-form' ); ?></div>
				</details>
			</div>
		</div>

		<!-- CTA banner -->
		<div class="dcp-cta-wrap">
			<div class="dcp-cta">
				<div class="dcp-cta-inner">
					<div class="dcp-cta-ico"><span class="material-symbols-outlined" aria-hidden="true">support_agent</span></div>
					<div class="dcp-cta-body">
						<h2 class="dcp-cta-title"><?php esc_html_e( 'هل تحتاج مساعدة فورية؟', 'digtiali-contact-form' ); ?></h2>
						<p class="dcp-cta-sub"><?php esc_html_e( 'تحدث مع فريق Digtiali الآن عبر واتساب وسنساعدك في اختيار المنتج المناسب.', 'digtiali-contact-form' ); ?></p>
					</div>
					<a href="https://wa.me/19294462772" class="dcp-btn dcp-btn--wa" target="_blank" rel="noopener">
						<span class="material-symbols-outlined" aria-hidden="true">chat</span>
						<?php esc_html_e( 'إبدأ المحادثة الآن', 'digtiali-contact-form' ); ?>
					</a>
				</div>
			</div>
		</div>

	</div>
	<?php
	return (string) ob_get_clean();
}

function digtiali_contact_submit_ajax(): void {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'digtiali_contact_nonce' ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid request.', 'digtiali-contact-form' ) ), 403 );
	}
	$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
	$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	$phone = digtiali_contact_normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
	$subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
	$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
	$errors = array();
	if ( '' === $name ) { $errors['name'] = __( 'Name is required.', 'digtiali-contact-form' ); }
	if ( ! is_email( $email ) ) { $errors['email'] = __( 'Valid email is required.', 'digtiali-contact-form' ); }
	// phone is optional
	if ( '' === $subject ) { $errors['subject'] = __( 'Subject is required.', 'digtiali-contact-form' ); }
	if ( '' === $message ) { $errors['message'] = __( 'Message is required.', 'digtiali-contact-form' ); }
	if ( $errors ) {
		wp_send_json_error( array( 'message' => __( 'Please fix the errors and try again.', 'digtiali-contact-form' ), 'errors' => $errors ), 400 );
	}
	$recent = new WP_Query( array( 'post_type' => 'contact_submission', 'post_status' => array( 'unread', 'read', 'replied' ), 'posts_per_page' => 1, 'date_query' => array( array( 'after' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ) ), 'meta_query' => array( array( 'key' => '_cs_email', 'value' => $email ), array( 'key' => '_cs_subject', 'value' => $subject ) ), 'fields' => 'ids', 'no_found_rows' => true ) );
	if ( ! empty( $recent->posts ) ) {
		wp_send_json_error( array( 'message' => __( 'Duplicate submission detected. Please wait 10 minutes before resubmitting the same subject.', 'digtiali-contact-form' ) ), 409 );
	}
	$post_id = wp_insert_post( array( 'post_type' => 'contact_submission', 'post_status' => 'unread', 'post_title' => $name ), true );
	if ( is_wp_error( $post_id ) ) {
		wp_send_json_error( array( 'message' => $post_id->get_error_message() ), 500 );
	}
	update_post_meta( $post_id, '_cs_email', $email );
	update_post_meta( $post_id, '_cs_phone', $phone );
	update_post_meta( $post_id, '_cs_subject', $subject );
	update_post_meta( $post_id, '_cs_message', $message );
	update_post_meta( $post_id, '_cs_ip', sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) );
	wp_send_json_success( array( 'message' => __( 'تم إرسال رسالتك بنجاح، سنرد عليك قريباً', 'digtiali-contact-form' ) ) );
}

function digtiali_contact_reply_ajax(): void {
	if ( ! digtiali_contact_user_can_manage() ) { wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'digtiali-contact-form' ) ), 403 ); }
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'digtiali_reply_nonce' ) ) { wp_send_json_error( array( 'message' => __( 'Invalid request.', 'digtiali-contact-form' ) ), 403 ); }
	$post_id = absint( $_POST['submission_id'] ?? 0 );
	$reply = sanitize_textarea_field( wp_unslash( $_POST['reply'] ?? '' ) );
	$post = get_post( $post_id );
	if ( ! $post || 'contact_submission' !== $post->post_type ) { wp_send_json_error( array( 'message' => __( 'Submission not found.', 'digtiali-contact-form' ) ), 404 ); }
	if ( '' === $reply ) { wp_send_json_error( array( 'message' => __( 'Reply text is required.', 'digtiali-contact-form' ) ), 400 ); }
	$email = (string) get_post_meta( $post_id, '_cs_email', true );
	$subject = (string) get_post_meta( $post_id, '_cs_subject', true );
	$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>', 'Reply-To: ' . get_option( 'admin_email' ) );
	if ( ! wp_mail( $email, 'Re: ' . $subject, nl2br( esc_html( $reply ) ), $headers ) ) { wp_send_json_error( array( 'message' => __( 'Email could not be sent.', 'digtiali-contact-form' ) ), 500 ); }
	update_post_meta( $post_id, '_cs_reply', $reply );
	update_post_meta( $post_id, '_cs_replied_at', time() );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'replied' ) );
	wp_send_json_success( array( 'message' => __( 'Reply sent.', 'digtiali-contact-form' ) ) );
}

function digtiali_contact_mark_read_ajax(): void {
	if ( ! digtiali_contact_user_can_manage() ) { wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'digtiali-contact-form' ) ), 403 ); }
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'digtiali_mark_read_nonce' ) ) { wp_send_json_error( array( 'message' => __( 'Invalid request.', 'digtiali-contact-form' ) ), 403 ); }
	$post_id = absint( $_POST['submission_id'] ?? 0 );
	$post = get_post( $post_id );
	if ( ! $post || 'contact_submission' !== $post->post_type ) { wp_send_json_error( array( 'message' => __( 'Submission not found.', 'digtiali-contact-form' ) ), 404 ); }
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'read' ) );
	wp_send_json_success( array( 'message' => __( 'Marked as read.', 'digtiali-contact-form' ) ) );
}

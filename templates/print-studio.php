<?php
defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() || ! current_user_can( 'hbc_admin' ) ) {
	status_header( 403 );
	wp_die( esc_html__( 'Organization Admin access is required for Postcard Studio.', 'hbc-prod' ) );
}

$host_uuid = class_exists( 'HBC_Prod_Store' ) ? HBC_Prod_Store::host_uuid() : '';
$host_name = '';
$org_code  = '';
if ( $host_uuid && class_exists( 'HBC_Prod_Store' ) ) {
	$events    = HBC_Prod_Store::list_host_events( $host_uuid );
	$host_name = (string) ( $events['host_name'] ?? '' );
}
if ( $host_uuid && class_exists( 'HBC_Prod_Orgs' ) ) {
	$org_code = HBC_Prod_Orgs::public_code_for_host( $host_uuid );
}

$scan = $org_code !== ''
	? home_url( '/r?org=' . rawurlencode( $org_code ) )
	: home_url( '/r' );
$door_qr = home_url( '/door/rsvp' );
$studio  = HBC_PROD_DIR . 'assets/postcard-studio/postcard-studio.html';

if ( ! is_readable( $studio ) ) {
	status_header( 500 );
	wp_die( esc_html__( 'Postcard Studio assets are missing. Re-upload hbc-production-integration.', 'hbc-prod' ) );
}

$html = (string) file_get_contents( $studio );

// Human Gold branding + return path (client studio was titled Be the Good).
$html = str_replace(
	array(
		'Be the Good Postcard Studio',
		'Be the <span>Good</span> Postcard Studio',
		'admin-dashboard.html',
		'creator:"Be the Good Postcard Studio"',
	),
	array(
		'Human Gold Postcard Studio',
		'Human <span>Gold</span> Postcard Studio',
		esc_url( home_url( '/organize' ) ),
		'creator:"Human Gold Postcard Studio"',
	),
	$html
);
$html = str_replace(
	'&#8592; Back to Admin Dashboard',
	'&#8592; Back to Organize',
	$html
);

$config = wp_json_encode(
	array(
		'scanUrl'  => $scan,
		'doorQr'   => $door_qr,
		'orgName'  => $host_name,
		'orgCode'  => $org_code,
		'materials'=> home_url( '/print-studio?view=materials' ),
	)
);

$boot = '<script>window.HBC_POSTCARD=' . $config . ';</script>'
	. '<script>(function(){var c=window.HBC_POSTCARD||{};'
	. 'document.addEventListener("DOMContentLoaded",function(){'
	. 'try{if(c.orgName&&document.getElementById("orgName")&&!document.getElementById("orgName").value){document.getElementById("orgName").value=c.orgName;document.getElementById("orgName").dispatchEvent(new Event("input",{bubbles:true}));}}catch(e){}'
	. 'try{if(c.doorQr&&typeof loadImg==="function"&&typeof state!=="undefined"){loadImg(c.doorQr).then(function(img){if(!img)return;imgs.box=img;state.boxImg=c.doorQr;if(typeof setThumb==="function")setThumb("boxThumb",c.doorQr,"Empty");if(typeof schedule==="function")schedule();});}}catch(e){}'
	. '});})();</script>';

// Optional materials hub (door QRs + fixture) kept for Super Admin inventory.
$view = isset( $_GET['view'] ) ? sanitize_key( (string) wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( $view === 'materials' ) {
	get_header();
	$fixture_svg = '';
	$theme_svg   = get_stylesheet_directory() . '/assets/images/postcard/Human_Gold_RSVP.svg';
	$theme_png   = get_stylesheet_directory() . '/assets/images/postcard/Human_Gold_RSVP.png';
	if ( is_readable( $theme_svg ) ) {
		$fixture_svg = trailingslashit( get_stylesheet_directory_uri() ) . 'assets/images/postcard/Human_Gold_RSVP.svg';
	} elseif ( is_readable( $theme_png ) ) {
		$fixture_svg = trailingslashit( get_stylesheet_directory_uri() ) . 'assets/images/postcard/Human_Gold_RSVP.png';
	}
	$doors = class_exists( 'HBC_Prod_Orgs' ) ? HBC_Prod_Orgs::door_targets() : array();
	echo '<main class="hbc-print-studio" style="max-width:960px;margin:40px auto;padding:0 20px 80px;color:#12253D;font:16px/1.5 system-ui,sans-serif">';
	echo '<p style="letter-spacing:.12em;text-transform:uppercase;font-weight:800;color:#B98A2E;margin:0 0 8px">Human Gold · Print Materials</p>';
	echo '<h1 style="margin:0 0 12px">Door QR inventory</h1>';
	echo '<p><a class="button button-primary" href="' . esc_url( home_url( '/print-studio' ) ) . '">Open Postcard Studio</a> ';
	echo '<a class="button" href="' . esc_url( home_url( '/organize' ) ) . '">Back to Organize</a></p>';
	echo '<p>Encoded RSVP URL: <a href="' . esc_url( $scan ) . '">' . esc_html( $scan ) . '</a></p>';
	if ( $fixture_svg ) {
		echo '<p><img src="' . esc_url( $fixture_svg ) . '" alt="Human Gold RSVP fixture" style="max-width:220px;height:auto;background:#fff;padding:8px;border:1px solid #DED5BF"></p>';
	}
	echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-top:20px">';
	foreach ( $doors as $key => $door ) {
		echo '<figure style="margin:0;text-align:center"><img src="' . esc_url( home_url( '/door/' . $key ) ) . '" alt="" width="160" height="160" style="background:#fff;border:1px solid #DED5BF">';
		echo '<figcaption style="font-size:13px;margin-top:8px"><strong>' . esc_html( ucfirst( $key ) ) . '</strong><br>' . esc_html( $door['url'] ) . '</figcaption></figure>';
	}
	echo '</div></main>';
	get_footer();
	return;
}

// Studio file is a fragment (meta/style/markup/script). Serve as a full page.
nocache_headers();
header( 'Content-Type: text/html; charset=UTF-8' );
echo '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="hbc-postcard-studio-body">';
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed plugin asset with escaped string replaces.
echo $html;
echo $boot;
echo '</body></html>';
exit;

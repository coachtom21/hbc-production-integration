<?php
defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() || ! current_user_can( 'hbc_super_admin' ) ) {
	status_header( 403 );
	wp_die( esc_html__( 'Protected Super Admin access is required.', 'hbc-prod' ) );
}

$mfa_verified = (bool) apply_filters( 'hbc_privileged_mfa_verified', false, get_current_user_id() );
if ( ! $mfa_verified ) {
	status_header( 403 );
	wp_die( esc_html__( 'MFA verification is required before opening the Super Admin Console.', 'hbc-prod' ) );
}

$status = class_exists( 'HBC_Prod_Store' ) ? HBC_Prod_Store::trade_status() : array();
$report = class_exists( 'HBC_Laugh_Readiness' ) ? HBC_Laugh_Readiness::report() : array();
$mirror = class_exists( 'HBC_Prod_Store' ) ? HBC_Prod_Store::smallstreet_mirror_tail( 8 ) : array();
$vols   = class_exists( 'HBC_Prod_Store' ) ? HBC_Prod_Store::list_all_volunteers() : array( 'volunteers' => array() );
$on     = ! empty( $status['platform_on'] );
$rest   = esc_url_raw( rest_url( 'hbc-laugh/v1/' ) );
$nonce  = wp_create_nonce( 'wp_rest' );

get_header();
?>
<main class="hbc-super-admin-root" style="max-width:920px;margin:48px auto;padding:0 20px 80px;color:#172433;font:16px/1.5 system-ui,sans-serif">
	<p style="color:#b64242;letter-spacing:.14em;text-transform:uppercase;font-weight:800;margin:0 0 8px">Human Gold · Super Admin</p>
	<h1 style="margin:0 0 12px">Platform control</h1>
	<p>Trade stays deny-by-default. Flipping ON requires no deploy. Fundraising stays Tom-only.</p>

	<section style="background:#fff;border:1px solid #d9e2e9;border-radius:12px;padding:20px;margin:20px 0">
		<h2 style="margin-top:0">Volunteer requests</h2>
		<p>Participate RSVPs that asked to volunteer. Accept or decline here. Identity UUID can be copied into Seller authorization below.</p>
		<?php $vitems = $vols['volunteers'] ?? array(); ?>
		<?php if ( ! $vitems ) : ?>
			<p>No volunteer requests yet.</p>
		<?php else : ?>
			<table style="width:100%;border-collapse:collapse;font-size:14px">
				<thead>
					<tr style="text-align:left;border-bottom:1px solid #d9e2e9">
						<th style="padding:8px 4px">Org / Event</th>
						<th style="padding:8px 4px">Identity UUID</th>
						<th style="padding:8px 4px">State</th>
						<th style="padding:8px 4px">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $vitems as $v ) : ?>
						<tr style="border-bottom:1px solid #eef2f6">
							<td style="padding:10px 4px;vertical-align:top">
								<strong><?php echo esc_html( (string) ( $v['host_name'] ?? '' ) ); ?></strong><br>
								<?php echo esc_html( (string) ( $v['event_name'] ?? '' ) ); ?>
							</td>
							<td style="padding:10px 4px;vertical-align:top">
								<code style="font-size:12px;word-break:break-all"><?php echo esc_html( (string) ( $v['identity_uuid'] ?? '' ) ); ?></code>
								<?php if ( ! empty( $v['identity_uuid'] ) ) : ?>
									<br><button type="button" class="button hbc-sa-use-seller" data-uuid="<?php echo esc_attr( (string) $v['identity_uuid'] ); ?>" style="margin-top:6px">Use as seller</button>
								<?php endif; ?>
							</td>
							<td style="padding:10px 4px;vertical-align:top"><?php echo esc_html( (string) ( $v['state'] ?? '' ) ); ?></td>
							<td style="padding:10px 4px;vertical-align:top">
								<?php if ( ( $v['state'] ?? '' ) === 'requested' ) : ?>
									<button type="button" class="button button-primary hbc-sa-vol-accept" data-uuid="<?php echo esc_attr( (string) $v['volunteer_request_uuid'] ); ?>">Accept</button>
									<button type="button" class="button hbc-sa-vol-decline" data-uuid="<?php echo esc_attr( (string) $v['volunteer_request_uuid'] ); ?>">Decline</button>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p id="hbc-sa-vol-msg" role="status"></p>
		<?php endif; ?>
	</section>

	<section style="background:#fff;border:1px solid #d9e2e9;border-radius:12px;padding:20px;margin:20px 0">
		<h2 style="margin-top:0">Trade Controls</h2>
		<p>Status: <strong id="hbc-sa-trade-status"><?php echo $on ? 'LIVE' : 'OFF'; ?></strong>
			<?php if ( ! empty( $status['emergency_locked'] ) ) : ?>
				· Emergency locked until <?php echo esc_html( (string) $status['emergency_lock_until'] ); ?>
			<?php endif; ?>
		</p>
		<p>
			<label>Reason <input type="text" id="hbc-sa-reason" style="width:min(100%,360px)" placeholder="Required for every change"></label>
		</p>
		<p>
			<button type="button" class="button button-primary" id="hbc-sa-trade-on" <?php disabled( $on ); ?>>Turn Trade ON</button>
			<button type="button" class="button" id="hbc-sa-trade-off" <?php disabled( ! $on ); ?>>Turn Trade OFF</button>
			<button type="button" class="button" style="color:#b64242" id="hbc-sa-trade-emergency">Emergency shutdown</button>
		</p>
		<p id="hbc-sa-msg" role="status"></p>
	</section>

	<section style="background:#fff;border:1px solid #d9e2e9;border-radius:12px;padding:20px;margin:20px 0">
		<h2 style="margin-top:0">Seller authorization</h2>
		<p>Both platform ON and seller authorization are required before a Trade offer is accepted.</p>
		<p>
			<label>Seller identity UUID <input type="text" id="hbc-sa-seller" style="width:min(100%,420px)" placeholder="identity UUID"></label>
		</p>
		<p>
			<button type="button" class="button button-primary" id="hbc-sa-seller-auth">Authorize seller</button>
			<button type="button" class="button" id="hbc-sa-seller-revoke">Revoke seller</button>
		</p>
		<ul>
			<?php foreach ( ( $status['seller_authorizations'] ?? array() ) as $id => $ok ) : ?>
				<?php if ( $ok ) : ?>
					<li><code><?php echo esc_html( (string) $id ); ?></code></li>
				<?php endif; ?>
			<?php endforeach; ?>
		</ul>
	</section>

	<section style="background:#fff;border:1px solid #d9e2e9;border-radius:12px;padding:20px;margin:20px 0">
		<h2 style="margin-top:0">Trade audit</h2>
		<ul>
			<?php foreach ( ( $status['audit'] ?? array() ) as $row ) : ?>
				<li><?php echo esc_html( ( $row['when'] ?? '' ) . ' — ' . ( $row['actor'] ?? '' ) . ' — ' . ( $row['action'] ?? '' ) . ' — ' . ( $row['prev'] ?? '' ) . ' → ' . ( $row['next'] ?? '' ) . ' — ' . ( $row['reason'] ?? '' ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	</section>

	<section style="background:#fff;border:1px solid #d9e2e9;border-radius:12px;padding:20px;margin:20px 0">
		<h2 style="margin-top:0">SmallStreet XP mirror (read-only)</h2>
		<p>HBC authorizes XP; this list is the downstream mirror only.</p>
		<ul>
			<?php foreach ( $mirror as $row ) : ?>
				<li><code><?php echo esc_html( (string) ( $row['confirmation_id'] ?? '' ) ); ?></code> — <?php echo esc_html( (string) ( $row['amount'] ?? '' ) ); ?> — <?php echo esc_html( (string) ( $row['lifecycle'] ?? '' ) ); ?></li>
			<?php endforeach; ?>
			<?php if ( ! $mirror ) : ?>
				<li>No mirrored recognition yet.</li>
			<?php endif; ?>
		</ul>
	</section>

	<p>
		<a href="<?php echo esc_url( home_url( '/r' ) ); ?>">/r</a> ·
		<a href="<?php echo esc_url( home_url( '/organize' ) ); ?>">/organize</a> ·
		<a href="<?php echo esc_url( home_url( '/trade' ) ); ?>">/trade</a> ·
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=hbc-human-gold' ) ); ?>">WP Human Gold</a>
	</p>

	<details>
		<summary>Readiness</summary>
		<ul>
			<?php foreach ( ( $report['checks'] ?? array() ) as $name => $check ) : ?>
				<li><?php echo ! empty( $check['ok'] ) ? 'OK' : 'WAIT'; ?> — <?php echo esc_html( $name ); ?> — <?php echo esc_html( (string) ( $check['message'] ?? '' ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	</details>
</main>
<script>
(function(){
  const rest = <?php echo wp_json_encode( $rest ); ?>;
  const nonce = <?php echo wp_json_encode( $nonce ); ?>;
  const msg = document.getElementById('hbc-sa-msg');
  const statusEl = document.getElementById('hbc-sa-trade-status');
  const volMsg = document.getElementById('hbc-sa-vol-msg');
  async function post(action) {
    const reason = (document.getElementById('hbc-sa-reason').value || '').trim();
    if (!reason) { msg.textContent = 'Reason is required.'; return; }
    const body = { action, reason };
    if (action === 'authorize_seller' || action === 'revoke_seller') {
      body.identity_uuid = (document.getElementById('hbc-sa-seller').value || '').trim();
    }
    msg.textContent = 'Saving…';
    const res = await fetch(rest + 'super/trade', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      credentials: 'same-origin',
      body: JSON.stringify(body)
    });
    const json = await res.json();
    if (!res.ok) {
      msg.textContent = (json.error && json.error.message) ? json.error.message : 'Request failed.';
      return;
    }
    msg.textContent = 'Saved.';
    statusEl.textContent = json.data && json.data.platform_on ? 'LIVE' : 'OFF';
    window.setTimeout(function(){ location.reload(); }, 600);
  }
  async function reviewVol(uuid, decision) {
    if (volMsg) volMsg.textContent = 'Saving…';
    const res = await fetch(rest + 'super/volunteers/' + encodeURIComponent(uuid) + '/' + decision, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
      credentials: 'same-origin'
    });
    const json = await res.json().catch(function(){ return {}; });
    if (!res.ok) {
      if (volMsg) volMsg.textContent = (json.error && json.error.message) ? json.error.message : 'Could not update volunteer.';
      return;
    }
    if (volMsg) volMsg.textContent = 'Saved.';
    window.setTimeout(function(){ location.reload(); }, 400);
  }
  document.getElementById('hbc-sa-trade-on').onclick = () => post('on');
  document.getElementById('hbc-sa-trade-off').onclick = () => post('off');
  document.getElementById('hbc-sa-trade-emergency').onclick = () => {
    if (confirm('Emergency shutdown Trade for 24 hours?')) post('emergency');
  };
  document.getElementById('hbc-sa-seller-auth').onclick = () => post('authorize_seller');
  document.getElementById('hbc-sa-seller-revoke').onclick = () => post('revoke_seller');
  document.querySelectorAll('.hbc-sa-vol-accept').forEach(function(btn){
    btn.onclick = function(){ reviewVol(btn.getAttribute('data-uuid'), 'accept'); };
  });
  document.querySelectorAll('.hbc-sa-vol-decline').forEach(function(btn){
    btn.onclick = function(){ reviewVol(btn.getAttribute('data-uuid'), 'decline'); };
  });
  document.querySelectorAll('.hbc-sa-use-seller').forEach(function(btn){
    btn.onclick = function(){
      var input = document.getElementById('hbc-sa-seller');
      if (input) {
        input.value = btn.getAttribute('data-uuid') || '';
        input.focus();
        input.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    };
  });
})();
</script>
<?php
get_footer();

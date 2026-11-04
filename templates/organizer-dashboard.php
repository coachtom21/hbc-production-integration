<?php
defined( 'ABSPATH' ) || exit;

$rest  = esc_url_raw( rest_url( 'hbc-laugh/v1/' ) );
$nonce = wp_create_nonce( 'wp_rest' );

get_header();
?>
<style>
	#hbc-org-dialog::backdrop{background:rgba(0,0,0,.55)}
	#hbc-org-dialog[open]{color:#172433}
	.hbc-organizer-dashboard h1,
	.hbc-organizer-dashboard h2,
	.hbc-organizer-dashboard h3,
	.hbc-organizer-dashboard h4{font-family:system-ui,sans-serif;color:#172433;letter-spacing:0;font-weight:700}
	.hbc-organizer-dashboard h1{font-size:2rem;line-height:1.2}
	.hbc-organizer-dashboard h2{font-size:1.25rem;line-height:1.3}
	.hbc-organizer-dashboard h3{font-size:1.05rem;line-height:1.35}
	.hbc-organizer-dashboard h4{font-size:.95rem;line-height:1.3}
	.hbc-organizer-dashboard .hbc-event-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
	.hbc-organizer-dashboard a.hbc-start-encounter{display:inline-block;background:#172433;color:#fff !important;text-decoration:none !important;border-radius:6px;padding:10px 14px;font:700 14px/1.2 system-ui,sans-serif;white-space:nowrap}
</style>
<main class="hbc-organizer-dashboard" style="max-width:760px;margin:48px auto;padding:0 20px 80px;color:#172433;font:16px/1.5 system-ui,sans-serif">
	<p style="letter-spacing:.14em;text-transform:uppercase;font-weight:800;margin:0 0 8px">Human Gold · Organizer</p>
	<h1 style="margin:0 0 12px">Organizer dashboard</h1>
	<p id="hbc-org-status">Checking this phone…</p>
	<section id="hbc-org-events" hidden style="background:#fff;border:1px solid #d9e2e9;border-radius:12px;padding:20px;margin:20px 0">
		<h2 style="margin-top:0">Your events</h2>
		<p>Each event lists its volunteer requests and encounters. Start encounter opens the short-lived code on this phone. Another registered phone scans that code.</p>
		<p><button type="button" id="hbc-org-open" style="background:#172433;color:#fff;border:0;border-radius:6px;padding:10px 16px;cursor:pointer">Create event</button></p>
		<p id="hbc-org-create-msg" role="status"></p>
		<dialog id="hbc-org-dialog" style="border:0;border-radius:12px;padding:0;max-width:480px;width:calc(100% - 32px);box-shadow:0 8px 32px rgba(0,0,0,.25)">
			<form id="hbc-org-create" style="display:grid;gap:10px;margin:0;padding:24px">
				<div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
					<h2 id="hbc-org-dialog-title" style="margin:0">Create event</h2>
					<button type="button" id="hbc-org-close" aria-label="Close" style="border:0;background:transparent;font-size:24px;line-height:1;cursor:pointer">&times;</button>
				</div>
				<label>Event name<br><input id="hbc-org-name" name="name" required maxlength="180" style="width:100%;padding:8px"></label>
				<label>Location<br><input id="hbc-org-location" name="location" maxlength="180" style="width:100%;padding:8px"></label>
				<label>Starts<br><input id="hbc-org-starts" name="starts_at" type="datetime-local" style="width:100%;padding:8px"></label>
				<label>Ends<br><input id="hbc-org-ends" name="ends_at" type="datetime-local" style="width:100%;padding:8px"></label>
				<label>Short code, optional<br><input id="hbc-org-code" name="event_code" maxlength="16" style="width:100%;padding:8px" placeholder="4–16 letters or numbers"></label>
				<p id="hbc-org-dialog-msg" role="status" style="margin:0"></p>
				<p style="margin:0"><button type="submit" style="background:#172433;color:#fff;border:0;border-radius:6px;padding:10px 16px;cursor:pointer">Save event</button></p>
			</form>
		</dialog>
		<div id="hbc-org-event-list"></div>
		<p id="hbc-org-msg" role="status"></p>
	</section>
	<p><a href="<?php echo esc_url( home_url( '/r' ) ); ?>">Back to RSVP</a></p>
</main>
<script>
(function () {
  const rest = <?php echo wp_json_encode( $rest ); ?>;
  const nonce = <?php echo wp_json_encode( $nonce ); ?>;
  const statusEl = document.getElementById('hbc-org-status');
  const eventsSection = document.getElementById('hbc-org-events');
  const eventList = document.getElementById('hbc-org-event-list');
  const createForm = document.getElementById('hbc-org-create');
  const createMsg = document.getElementById('hbc-org-create-msg');
  const dialog = document.getElementById('hbc-org-dialog');
  const dialogMsg = document.getElementById('hbc-org-dialog-msg');
  const openButton = document.getElementById('hbc-org-open');
  const msg = document.getElementById('hbc-org-msg');
  let token = '';
  try {
    token = sessionStorage.getItem('hbc_device_token_tab') || localStorage.getItem('hbc_device_token') || '';
  } catch (e) { token = ''; }

  const esc = (value) => String(value == null ? '' : value).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));

  const headers = () => ({
    'Content-Type': 'application/json',
    'X-WP-Nonce': nonce,
    'X-HBC-Device-Token': token
  });

  const whenLabel = (value) => {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
  };

  const encounterLabel = (type) => ({
    presence: 'Presence',
    gratitude: 'Gratitude',
    trade: 'Trade'
  }[type] || (type || 'Encounter'));

  const stateLabel = (state) => ({
    pending: 'Waiting',
    attendee_confirmed: 'Attendee confirmed',
    operator_confirmed: 'Host confirmed',
    proximity_verified: 'Confirmed',
    confirmed: 'Confirmed',
    pending_xp: 'Confirmed',
    expired: 'Expired',
    walked_away: 'Walked away',
    extinguished: 'Walked away',
    declined: 'Declined',
    requested: 'Requested',
    accepted: 'Accepted',
    completed: 'Completed',
    withdrawn: 'Withdrawn'
  }[state] || state || '—');

  const choiceLabel = (choice) => ({
    observe: 'Observe',
    participate: 'Participate',
    walk_away: 'Walk away'
  }[choice] || (choice || '—'));

  const drawRequests = (requests) => {
    if (!requests.length) return '<p style="margin:0 0 8px;color:#52606d">No requests for this event.</p>';
    return '<table style="width:100%;border-collapse:collapse;font-size:14px"><thead><tr style="text-align:left;border-bottom:1px solid #d9e2e9"><th style="padding:6px 4px">Request</th><th style="padding:6px 4px">State</th><th style="padding:6px 4px">When</th><th style="padding:6px 4px">Actions</th></tr></thead><tbody>'
      + requests.map((row) => {
        const open = row.state === 'requested' && row.volunteer_request_uuid;
        const actions = open
          ? '<button type="button" data-decision="accept" data-uuid="' + esc(row.volunteer_request_uuid) + '" style="background:#172433;color:#fff;border:0;border-radius:6px;padding:6px 10px;cursor:pointer">Accept</button> <button type="button" data-decision="decline" data-uuid="' + esc(row.volunteer_request_uuid) + '" style="background:#fff;color:#172433;border:1px solid #172433;border-radius:6px;padding:6px 10px;cursor:pointer">Decline</button>'
          : '—';
        const state = row.state ? stateLabel(row.state) : stateLabel(row.status);
        return '<tr style="border-bottom:1px solid #eef2f6"><td style="padding:8px 4px">' + esc(choiceLabel(row.choice)) + '</td><td style="padding:8px 4px">' + esc(state) + '</td><td style="padding:8px 4px">' + esc(whenLabel(row.created_at)) + '</td><td style="padding:8px 4px">' + actions + '</td></tr>';
      }).join('')
      + '</tbody></table>';
  };

  const drawEncounters = (encounters) => {
    if (!encounters.length) return '<p style="margin:0 0 8px;color:#52606d">No encounters for this event.</p>';
    return '<table style="width:100%;border-collapse:collapse;font-size:14px"><thead><tr style="text-align:left;border-bottom:1px solid #d9e2e9"><th style="padding:6px 4px">Type</th><th style="padding:6px 4px">State</th><th style="padding:6px 4px">When</th></tr></thead><tbody>'
      + encounters.map((row) => {
        const who = row.attendee ? '<br><span style="color:#52606d">' + esc(row.attendee) + '</span>' : '';
        return '<tr style="border-bottom:1px solid #eef2f6"><td style="padding:8px 4px">' + esc(encounterLabel(row.encounter_type)) + '</td><td style="padding:8px 4px">' + esc(stateLabel(row.state)) + who + '</td><td style="padding:8px 4px">' + esc(whenLabel(row.issued_at)) + '</td></tr>';
      }).join('')
      + '</tbody></table>';
  };

  const drawEvents = (events) => {
    if (!events.length) {
      eventList.innerHTML = '<p>No events yet.</p>';
      return;
    }
    eventList.innerHTML = events.map((row) => {
      const when = whenLabel(row.starts_at);
      const ends = row.ends_at ? ' – ' + esc(whenLabel(row.ends_at)) : '';
      const place = row.location ? ' · ' + esc(row.location) : '';
      const code = row.event_code ? ' · ' + esc(row.event_code) : '';
      const requests = Array.isArray(row.requests) ? row.requests : [];
      const encounters = Array.isArray(row.encounters) ? row.encounters : [];
      const encounterTotal = Number.isFinite(Number(row.encounter_total)) ? Number(row.encounter_total) : encounters.length;
      const start = row.status === 'published' && row.encounter_url
        ? '<a class="hbc-start-encounter" href="' + esc(row.encounter_url) + '">Start encounter</a>'
        : '';
      return '<article style="border:1px solid #e4ebf0;border-radius:10px;padding:14px;margin:0 0 14px">'
        + '<div class="hbc-event-head"><div><h3 style="margin:0">' + esc(row.name) + '</h3>'
        + '<p style="margin:4px 0 0;color:#52606d">' + esc(when) + ends + place + code + '</p></div>'
        + start
        + '</div>'
        + '<h4 style="margin:16px 0 8px;font-size:14px">Requests (' + requests.length + ')</h4>'
        + drawRequests(requests)
        + '<h4 style="margin:16px 0 8px;font-size:14px">Total encounters: ' + encounterTotal + '</h4>'
        + drawEncounters(encounters)
        + '</article>';
    }).join('');
    eventList.querySelectorAll('button[data-uuid]').forEach((button) => {
      button.onclick = () => review(button.getAttribute('data-uuid'), button.getAttribute('data-decision'));
    });
  };

  const draw = (data) => {
    const events = Array.isArray(data.events) ? data.events : [];
    const org = data.name ? data.name : 'your organization';
    statusEl.textContent = 'You are the organizer of ' + org + '.';
    eventsSection.hidden = false;
    drawEvents(events);
  };

  const review = async (uuid, decision) => {
    msg.textContent = 'Saving…';
    const res = await fetch(rest + 'public/host/requests/' + encodeURIComponent(uuid) + '/' + decision, {
      method: 'POST',
      headers: headers(),
      credentials: 'same-origin'
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      msg.textContent = (json.error && json.error.message) ? json.error.message : 'That request could not be updated.';
      return;
    }
    msg.textContent = 'Saved.';
    load();
  };

  const load = async () => {
    if (!token) {
      statusEl.textContent = 'This phone is not an organizer.';
      return;
    }
    const res = await fetch(rest + 'public/host/dashboard', { headers: headers(), credentials: 'same-origin' });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      statusEl.textContent = (json.error && json.error.message) ? json.error.message : 'This phone is not an organizer.';
      eventsSection.hidden = true;
      return;
    }
    draw(json.data || {});
  };

  const closeDialog = () => {
    if (dialog.open) dialog.close();
    openButton.focus();
  };
  openButton.addEventListener('click', () => {
    dialogMsg.textContent = '';
    dialog.showModal();
    document.getElementById('hbc-org-name').focus();
  });
  document.getElementById('hbc-org-close').addEventListener('click', closeDialog);
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) closeDialog();
  });
  dialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeDialog();
  });

  createForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    dialogMsg.textContent = 'Saving…';
    const body = {
      name: document.getElementById('hbc-org-name').value,
      location: document.getElementById('hbc-org-location').value,
      starts_at: document.getElementById('hbc-org-starts').value,
      ends_at: document.getElementById('hbc-org-ends').value,
      event_code: document.getElementById('hbc-org-code').value
    };
    const res = await fetch(rest + 'public/host/events', {
      method: 'POST',
      headers: headers(),
      credentials: 'same-origin',
      body: JSON.stringify(body)
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      dialogMsg.textContent = (json.error && json.error.message) ? json.error.message : 'That event could not be saved.';
      return;
    }
    createForm.reset();
    dialogMsg.textContent = '';
    createMsg.textContent = 'Event created. It is on the RSVP list, and this phone can start the encounter.';
    closeDialog();
    load();
  });

  load();
})();
</script>
<?php
get_footer();

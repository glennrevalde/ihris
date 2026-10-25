<?php
/**
 * Web / mobile time clock with GPS geofencing and live selfie (facial) capture.
 * Browsers only allow camera and GPS on HTTPS or http://localhost.
 */
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
$emp = employee_row($me);
if (is_post()) {
    $res = web_punch($me, (string)post('punch_type'), post('lat') !== '' && post('lat') !== null ? (float)post('lat') : null,
        post('lng') !== '' && post('lng') !== null ? (float)post('lng') : null, post('acc') !== '' && post('acc') !== null ? (float)post('acc') : null,
        post('photo') ?: null, post('face') === '' || post('face') === null ? null : post('face') === '1');
    audit('ams', 'web_punch', 'attendance_logs', null, ['type' => post('punch_type'), 'ok' => $res['ok'], 'msg' => $res['message']]);
    flash($res['ok'] ? 'success' : 'error', $res['message']);
    redirect('my_clock');
}
$today = rows('SELECT l.*, b.name AS branch_name FROM attendance_logs l LEFT JOIN branches b ON b.id = l.branch_id WHERE l.employee_id = ? AND l.log_time >= ? ORDER BY l.log_time', [$me, date('Y-m-d 00:00:00', strtotime('-12 hours'))]);
$branches = rows('SELECT id, name, latitude, longitude, geofence_radius_m FROM branches WHERE is_active = 1 AND latitude IS NOT NULL');
$allowed = in_array($emp['attendance_method'], ['web', 'both'], true);
$next = 'in';
if ($today) {
    $last = end($today)['punch_type'];
    $next = ['in' => 'break_out', 'break_out' => 'break_in', 'break_in' => 'out', 'out' => 'in', 'unknown' => 'out'][$last] ?? 'in';
}
page_header('Time Clock', ['subtitle' => h($emp['shift_name'] ?: 'No fixed shift') . ', Default site: ' . h($emp['branch_name'])]);
if (!$allowed): ?>
  <div class="alert alert-warning">Web check-in is not enabled for your account (time capture: <?= h(label($emp['attendance_method'])) ?>). Please use the biometric device at your work site.</div>
<?php endif; ?>
<div class="clock-wrap">
  <div class="card">
    <div class="muted" id="clockDate"><?= h(date('l, F j, Y')) ?></div>
    <div class="clock-time" id="clockTime"><?= date('h:i:s A') ?></div>
    <div class="geo-box" id="geoBox" style="margin-top:12px">Locating… please allow location access.</div>
    <form method="post" id="punchForm"><?= csrf_field() ?>
      <input type="hidden" name="lat" id="fLat"><input type="hidden" name="lng" id="fLng"><input type="hidden" name="acc" id="fAcc">
      <input type="hidden" name="photo" id="fPhoto"><input type="hidden" name="face" id="fFace"><input type="hidden" name="punch_type" id="fType">
      <div class="punch-buttons">
        <?php foreach (['in' => 'Time in', 'break_out' => 'Break out', 'break_in' => 'Break in', 'out' => 'Time out'] as $k => $l): ?>
          <button type="button" class="btn btn-lg <?= $k === $next ? 'btn-primary' : '' ?>" data-punch="<?= $k ?>"<?= $allowed ? '' : ' disabled' ?>><?= $l ?></button>
        <?php endforeach; ?>
      </div>
    </form>
    <p class="small muted" style="margin-top:10px">Your GPS position is checked against the authorized radius of the nearest work site, and a live photo is captured to confirm your identity.
      Check-ins outside the radius<?= setting('geofence_mode') === 'reject' ? ' are rejected.' : ' are accepted but flagged for HR verification.' ?></p>
  </div>
  <div class="card">
    <div class="camera" id="cam"><div class="cam-msg" id="camMsg">Starting camera…</div><video id="video" autoplay playsinline muted></video></div>
    <canvas id="canvas" width="480" height="360" hidden></canvas>
    <p class="small muted" id="faceMsg" style="margin-top:8px"></p>
  </div>
</div>
<div class="card"><h3>Punches in the last 12+ hours</h3>
  <table class="table compact"><thead><tr><th>Time</th><th>Type</th><th>Source</th><th>Site</th><th>Location</th><th>Verification</th></tr></thead><tbody>
  <?php if (!$today) echo empty_row(6, 'No punches yet.'); foreach ($today as $p): ?>
    <tr><td><?= h(fmt_dt($p['log_time'], 'M d h:i:s A')) ?></td><td><?= h(label($p['punch_type'])) ?></td><td><?= h(label($p['source'])) ?></td><td><?= h($p['branch_name']) ?></td>
      <td class="small"><?= $p['latitude'] ? ($p['within_geofence'] ? 'Inside geofence' : 'Outside geofence') . ' (' . number_format((float)$p['distance_m']) . ' m)' : '' ?></td><td><?= $p['source'] === 'web' ? badge($p['verify_status']) : '' ?></td></tr>
  <?php endforeach; ?></tbody></table>
</div>
<script>
(function () {
  var sites = <?= json_encode(array_map(fn($b) => ['name' => $b['name'], 'lat' => (float)$b['latitude'], 'lng' => (float)$b['longitude'], 'r' => (int)$b['geofence_radius_m']], $branches)) ?>;
  var selfieRequired = <?= setting('selfie_required', '1') === '1' ? 'true' : 'false' ?>;
  var tEl = document.getElementById('clockTime');
  setInterval(function () { tEl.textContent = new Date().toLocaleTimeString('en-US', {hour: '2-digit', minute: '2-digit', second: '2-digit'}); }, 1000);

  function dist(a, b, c, d) {
    var R = 6371000, r = Math.PI / 180, x = (c - a) * r, y = (d - b) * r;
    var h = Math.sin(x / 2) * Math.sin(x / 2) + Math.cos(a * r) * Math.cos(c * r) * Math.sin(y / 2) * Math.sin(y / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
  }
  var geo = document.getElementById('geoBox');
  if (!navigator.geolocation) { geo.textContent = 'This browser does not support GPS location.'; }
  else if (!window.isSecureContext) { geo.textContent = 'Location and camera require HTTPS (or http://localhost).'; }
  else {
    navigator.geolocation.watchPosition(function (p) {
      var lat = p.coords.latitude, lng = p.coords.longitude, acc = p.coords.accuracy;
      document.getElementById('fLat').value = lat; document.getElementById('fLng').value = lng; document.getElementById('fAcc').value = acc;
      var best = null;
      sites.forEach(function (s) { var dd = dist(lat, lng, s.lat, s.lng); if (!best || dd < best.d) best = {s: s, d: dd}; });
      var html = 'Your location: ' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ' (±' + Math.round(acc) + ' m)';
      if (best) {
        var inside = best.d <= best.s.r;
        html += '<br>Nearest site: <strong>' + best.s.name + '</strong> — ' + Math.round(best.d) + ' m away ' +
          (inside ? '<span class="badge badge-green">inside geofence</span>' : '<span class="badge badge-red">outside geofence (' + best.s.r + ' m)</span>');
      }
      geo.innerHTML = html;
    }, function (err) { geo.textContent = 'Unable to get your location: ' + err.message; }, {enableHighAccuracy: true, maximumAge: 10000, timeout: 20000});
  }

  var video = document.getElementById('video'), camMsg = document.getElementById('camMsg'), faceMsg = document.getElementById('faceMsg');
  var camReady = false;
  if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
    navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 640}}, audio: false}).then(function (stream) {
      video.srcObject = stream; camReady = true; camMsg.style.display = 'none';
    }).catch(function (e) { camMsg.textContent = 'Camera unavailable: ' + e.message; });
  } else { camMsg.textContent = 'Camera not supported by this browser.'; }

  function capture() {
    if (!camReady) return Promise.resolve({photo: '', face: ''});
    var c = document.getElementById('canvas'), ctx = c.getContext('2d');
    c.width = video.videoWidth || 480; c.height = video.videoHeight || 360;
    ctx.drawImage(video, 0, 0, c.width, c.height);
    var data = c.toDataURL('image/jpeg', 0.8);
    if ('FaceDetector' in window) {
      try {
        return new window.FaceDetector({fastMode: true}).detect(c).then(function (faces) {
          return {photo: data, face: faces.length ? '1' : '0'};
        }).catch(function () { return {photo: data, face: ''}; });
      } catch (e) { /* not available */ }
    }
    return Promise.resolve({photo: data, face: ''});
  }
  document.querySelectorAll('[data-punch]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!document.getElementById('fLat').value) { alert('Waiting for your GPS location. Please allow location access.'); return; }
      if (selfieRequired && !camReady) { alert('A live selfie is required. Please allow camera access.'); return; }
      b.disabled = true;
      capture().then(function (r) {
        if (r.face === '0') { faceMsg.textContent = 'No face was detected in the photo — the check-in will be sent for HR verification.'; }
        document.getElementById('fPhoto').value = r.photo; document.getElementById('fFace').value = r.face;
        document.getElementById('fType').value = b.getAttribute('data-punch');
        document.getElementById('punchForm').submit();
      });
    });
  });
})();
</script>
<?php page_footer();

<?php
if (current_user()) {
    redirect('dashboard');
}
if (super_admin()) {
    redirect('sa_dashboard');
}
$multi = master_db() !== null;
$error = null;
if (is_post()) {
    verify_csrf();
    $key = 'login_attempts_' . md5((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $att = $_SESSION[$key] ?? ['n' => 0, 't' => time()];
    if ($att['n'] >= 8 && time() - $att['t'] < 300) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } elseif (attempt_login((string)post('username'), (string)post('password'))) {
        unset($_SESSION[$key]);
        if (!empty($_SESSION['sa'])) {
            unset($_SESSION['after_login']);
            redirect('sa_dashboard');
        }
        $to = $_SESSION['after_login'] ?? null;
        unset($_SESSION['after_login']);
        if ($to && strpos($to, 'index.php') !== false && strpos($to, 'p=logout') === false) {
            header('Location: ' . $to);
            exit;
        }
        redirect('dashboard');
    } else {
        $_SESSION[$key] = ['n' => $att['n'] + 1, 't' => time()];
        $error = login_message() ?? 'Invalid username or password.';
    }
}
page_header('Sign in');
?>
<div class="auth">
  <div class="auth-brand">
    <div class="brand" style="padding:0">
      <div class="brand-mark"><?= h(app_mark()) ?></div>
      <div><div class="brand-name"><?= h(app_name()) ?></div><div class="brand-sub">Integrated HR, Payroll &amp; Attendance</div></div>
    </div>
    <h2>One system for people,<br>time and pay.</h2>
    <p>Personnel Information System, Payroll and the Attendance Monitoring System working from the same records — Philippine statutory tables, multi-company and multi-branch.</p>
    <div class="auth-art" aria-hidden="true">
      <svg viewBox="0 0 440 236" xmlns="http://www.w3.org/2000/svg" font-family="Inter Variable, Inter, Segoe UI, sans-serif">
        <g transform="rotate(5 340 120)">
          <rect x="262" y="18" width="164" height="196" rx="16" fill="#1a2757"/>
          <rect x="282" y="40" width="70" height="8" rx="4" fill="#ffffff" opacity=".85"/>
          <rect x="282" y="56" width="46" height="6" rx="3" fill="#ffffff" opacity=".3"/>
          <g fill="#ffffff" opacity=".18"><rect x="282" y="84" width="90" height="6" rx="3"/><rect x="282" y="102" width="74" height="6" rx="3"/><rect x="282" y="120" width="96" height="6" rx="3"/><rect x="282" y="138" width="60" height="6" rx="3"/></g>
          <g fill="#ffffff" opacity=".45"><rect x="388" y="84" width="20" height="6" rx="3"/><rect x="392" y="102" width="16" height="6" rx="3"/><rect x="386" y="120" width="22" height="6" rx="3"/><rect x="394" y="138" width="14" height="6" rx="3"/></g>
          <rect x="300" y="166" width="108" height="30" rx="9" fill="#7ea3ff"/>
          <text x="312" y="186" font-size="12" font-weight="600" fill="#0c1533">Net pay</text>
          <rect x="370" y="177" width="28" height="8" rx="4" fill="#0c1533" opacity=".75"/>
        </g>
        <rect x="14" y="34" width="290" height="190" rx="18" fill="#ffffff"/>
        <circle cx="46" cy="68" r="14" fill="#e4e9f4"/>
        <text x="40" y="73" font-size="12" font-weight="650" fill="#111b45">R</text>
        <text x="70" y="65" font-size="13" font-weight="650" fill="#0f1729">Daily time record</text>
        <text x="70" y="81" font-size="11" fill="#64708a">Week of Monday</text>
        <circle cx="270" cy="68" r="15" fill="none" stroke="#e5e8ee" stroke-width="4"/>
        <circle cx="270" cy="68" r="15" fill="none" stroke="#2f55d4" stroke-width="4" stroke-linecap="round" stroke-dasharray="70 100" transform="rotate(-90 270 68)"/>
        <g font-size="12" fill="#374152">
          <text x="34" y="122" fill="#64708a">Mon</text><text x="34" y="150" fill="#64708a">Tue</text><text x="34" y="178" fill="#64708a">Wed</text><text x="34" y="206" fill="#64708a">Thu</text>
          <rect x="72" y="109" width="54" height="19" rx="6" fill="#e9f6ef"/><text x="82" y="122" fill="#05603a" font-weight="550">08:02</text>
          <rect x="134" y="109" width="54" height="19" rx="6" fill="#edf2ff"/><text x="144" y="122" fill="#1d3a99" font-weight="550">17:05</text>
          <rect x="72" y="137" width="54" height="19" rx="6" fill="#e9f6ef"/><text x="82" y="150" fill="#05603a" font-weight="550">07:56</text>
          <rect x="134" y="137" width="54" height="19" rx="6" fill="#edf2ff"/><text x="144" y="150" fill="#1d3a99" font-weight="550">17:01</text>
          <rect x="72" y="165" width="54" height="19" rx="6" fill="#fdf4e7"/><text x="82" y="178" fill="#93370d" font-weight="550">08:14</text>
          <rect x="134" y="165" width="54" height="19" rx="6" fill="#edf2ff"/><text x="144" y="178" fill="#1d3a99" font-weight="550">17:00</text>
          <rect x="72" y="193" width="54" height="19" rx="6" fill="#e9f6ef"/><text x="82" y="206" fill="#05603a" font-weight="550">08:00</text>
          <rect x="134" y="193" width="54" height="19" rx="6" fill="#f1f3f7"/><text x="160" y="206" fill="#98a1b3" text-anchor="middle">—</text>
          <rect x="206" y="114" width="52" height="8" rx="4" fill="#111b45"/><rect x="206" y="142" width="54" height="8" rx="4" fill="#111b45"/>
          <rect x="206" y="170" width="46" height="8" rx="4" fill="#111b45"/><rect x="206" y="198" width="20" height="8" rx="4" fill="#d3d9e3"/>
          <text x="284" y="122" text-anchor="end" font-weight="600" fill="#0f1729">8.0</text><text x="284" y="150" text-anchor="end" font-weight="600" fill="#0f1729">8.0</text>
          <text x="284" y="178" text-anchor="end" font-weight="600" fill="#0f1729">7.7</text><text x="284" y="206" text-anchor="end" fill="#98a1b3">…</text>
        </g>
      </svg>
    </div>
    <ul class="auth-feat">
      <li><?= icon('users') ?><span><strong>Personnel</strong> — 201 files, leaves with email approval, evaluations, salary grades.</span></li>
      <li><?= icon('clock') ?><span><strong>Attendance</strong> — biometric and file imports, shifts, day-by-day entry, period transactions.</span></li>
      <li><?= icon('money') ?><span><strong>Payroll</strong> — hours-based computation, SSS / PhilHealth / Pag-IBIG / BIR, payslips and registers.</span></li>
    </ul>
  </div>
  <div class="auth-form">
    <h1>Sign in</h1>
    <p class="muted"><?= $multi ? 'Sign in with the account your company gave you.' : h(setting('company_name')) ?></p>
    <?php if ($error): ?><div class="alert alert-error"><span><?= h($error) ?></span></div><?php endif; ?>
    <?php foreach (flashes() as $f): ?><div class="alert alert-<?= h($f['type']) ?>"><span><?= h($f['msg']) ?></span></div><?php endforeach; ?>
    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <div class="field"><label for="u"><?= $multi ? 'Username or e-mail' : 'Username' ?></label><input id="u" name="username" required autofocus value="<?= h(post('username', '')) ?>" autocomplete="username"></div>
      <div class="field"><label for="pw">Password</label><input id="pw" type="password" name="password" required autocomplete="current-password"></div>
      <button class="btn btn-primary btn-block btn-lg">Sign in</button>
    </form>
    <p class="auth-alt">Forgot your password? Ask your HR or system administrator to reset it. You will receive a temporary password and choose a new one when you sign in.</p>
    <?php if ($multi): ?>
      <p class="auth-alt">New employee? <a href="<?= url('register') ?>">Join your company with its Company Code</a></p>
    <?php endif; ?>
    <p class="small muted" style="margin-top:18px">Camera and GPS check-in need HTTPS or localhost. Change every password before using the system with real data.</p>
  </div>
</div>
<?php page_footer();

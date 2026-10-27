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
$demo = (bool)val("SELECT COUNT(*) FROM users WHERE username IN ('hr','payroll','employee')");
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
    <?php if ($demo): ?>
      <div class="demo-accounts">
        <strong>Demo accounts</strong><br>
        Admin <code>admin / admin123</code>, HR <code>hr / hr123</code><br>
        Payroll <code>payroll / payroll123</code>, Supervisor <code>supervisor / super123</code>, Employee <code>employee / employee123</code>
      </div>
    <?php endif; ?>
    <p class="small muted" style="margin-top:18px">Camera and GPS check-in need HTTPS or localhost. Change every password before using the system with real data.</p>
  </div>
</div>
<?php page_footer();

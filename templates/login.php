<div style="max-width:420px;margin:60px auto 0;">
  <div class="card">
    <h1 style="margin-top:0;">AHPC BOM & Quotation System</h1>
    <p style="color:var(--muted);font-size:0.9rem;">Sign in with your user name (e.g. <b>praveen</b>) or e-mail, and your password.</p>
    <form method="post">
      <div class="field"><label>User name or e-mail</label>
        <input name="email" value="<?= e($email) ?>" placeholder="praveen" required autofocus autocapitalize="none"></div>
      <div class="field"><label>Password</label><input type="password" name="password" required></div>
      <button class="btn" type="submit">Log In</button>
    </form>
  </div>
</div>

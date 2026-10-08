<div style="max-width:420px;margin:60px auto 0;">
  <div class="card">
    <h1 style="margin-top:0;">AHPC BOM & Quotation System</h1>
    <p style="color:var(--muted);font-size:0.9rem;">Sign in with your company e-mail and password.</p>
    <form method="post">
      <div class="field"><label>E-mail</label>
        <input type="email" name="email" value="<?= e($email) ?>" placeholder="name@allwayhpc.com" required autofocus></div>
      <div class="field"><label>Password</label><input type="password" name="password" required></div>
      <button class="btn" type="submit">Log In</button>
    </form>
  </div>
</div>

<div class="card" style="padding:32px 30px;">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
    <div class="brand" style="padding:0;"><div class="mark" style="width:46px;height:46px;font-size:1.05rem;">AH</div></div>
    <div><div style="font-weight:800;font-size:1.25rem;color:var(--heading);">Allway HPC</div>
      <div style="color:var(--muted);font-size:.85rem;">BOM, Quotation &amp; Work Tracker</div></div>
  </div>
  <p style="color:var(--muted);font-size:0.9rem;margin:0 0 18px;">Sign in with your user name (e.g. <b>praveen</b>) or e-mail, and your password.</p>
  <form method="post">
    <div class="field"><label>User name or e-mail</label>
      <input name="email" value="<?= e($email) ?>" placeholder="praveen" required autofocus autocapitalize="none"></div>
    <div class="field"><label>Password</label><input type="password" name="password" required></div>
    <button class="btn" type="submit" style="width:100%;padding:11px;">Log in</button>
  </form>
</div>

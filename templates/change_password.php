<div class="page-header"><h1>Change password</h1></div>
<div class="card" style="max-width:420px;">
  <form method="post">
    <div class="field"><label>Current password</label><input type="password" name="old_password" required></div>
    <div class="field"><label>New password (min 8 characters)</label><input type="password" name="new_password" required minlength="8"></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="confirm_password" required></div>
    <button class="btn" type="submit">Change password</button>
  </form>
</div>

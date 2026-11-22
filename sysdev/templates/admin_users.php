<?php $title = 'Staff accounts · SafeGate'; ?>
<section class="page-heading"><div><p class="eyebrow">Administration / user accounts</p><h1>The right access, for every role.</h1><p class="muted">Manage staff who verify visitors and maintain campus records.</p></div></section>
<div class="notice"><strong>Staff accounts only</strong><p>Visitors do not need staff accounts. Give every guard and administrator an individual account; never share sign-in credentials.</p></div>
<?php if ($invitation_url): ?>
  <section class="flash flash-success"><strong>One-time invitation link</strong><p>Share this link with the staff member. It expires in 48 hours.</p><code class="invite-link"><?= h($invitation_url) ?></code></section>
<?php endif; ?>
<div class="two-panel">
  <section class="card form-card">
    <h2>Invite staff member</h2>
    <form method="post" action="/admin/users" class="stack-form">
      <?php csrf_field(); ?>
      <label>Full name<input name="full_name" maxlength="120" required></label>
      <label>Email<input name="email" type="email" maxlength="254" required></label>
      <label>Role<select name="role" required><?php foreach ($staff_roles as $role): ?><option value="<?= h($role) ?>"><?= h($role) ?></option><?php endforeach; ?></select></label>
      <button class="button button-primary" type="submit">Create invitation</button>
    </form>
  </section>
  <section class="card table-card">
    <div class="section-heading"><div><h2>Staff directory</h2><p class="muted"><?= count($accounts) ?> account(s)</p></div></div>
    <?php if ($accounts): ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Staff member</th><th>Role</th><th>Status</th><th>Update</th></tr></thead>
        <tbody><?php foreach ($accounts as $account): ?>
          <tr>
            <td><strong><?= h($account['full_name']) ?></strong><small><?= h($account['email']) ?></small></td>
            <td><?= h($account['role']) ?></td>
            <td><span class="badge badge-<?= h(status_class($account['account_status'])) ?>"><?= h($account['account_status']) ?></span></td>
            <td>
              <?php if ($account['account_status'] !== 'Invited' && (int) $account['staff_id'] !== (int) $user['staff_id']): ?>
                <form method="post" action="/admin/users/<?= h($account['staff_id']) ?>/update" class="staff-update">
                  <?php csrf_field(); ?>
                  <select name="role" aria-label="Role for <?= h($account['full_name']) ?>"><?php foreach ($staff_roles as $role): ?><option value="<?= h($role) ?>" <?= $account['role'] === $role ? 'selected' : '' ?>><?= h($role) ?></option><?php endforeach; ?></select>
                  <select name="account_status" aria-label="Status for <?= h($account['full_name']) ?>"><option <?= $account['account_status'] === 'Active' ? 'selected' : '' ?>>Active</option><option <?= $account['account_status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option></select>
                  <button class="button button-secondary" type="submit">Save</button>
                </form>
              <?php elseif ($account['account_status'] === 'Invited'): ?>
                Invitation sent · activation link expires after 48 hours
              <?php else: ?>Your account<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php else: ?><p class="empty-state">No staff accounts yet.</p><?php endif; ?>
  </section>
</div>

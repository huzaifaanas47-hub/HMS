<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = clip($_POST['name'] ?? '', 120);
    $url = normalize_url($_POST['url'] ?? '');
    $exp = parse_local_input($_POST['expires_at'] ?? '');
    if ($name === '') flash('Enter a clinic name.', 'err');
    elseif ($url === null) flash('That site URL is not valid.', 'err');
    elseif (trim((string)($_POST['expires_at'] ?? '')) !== '' && $exp === null) flash('That expiry date is not valid.', 'err');
    else {
        try {
            db()->prepare('INSERT INTO clinics (name, url, expires_at, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$name, $url === '' ? null : $url, $exp, gmdate('Y-m-d H:i:s')]);
            flash('Clinic created. Now add its PCs on the clinic page.');
            redirect('clinic.php?id=' . (int)db()->lastInsertId());
        } catch (PDOException $e) {
            flash('A clinic with that name already exists.', 'err');
        }
    }
    redirect('clinics.php');
}

$now = time();
$s = settings();
$stats = [];
foreach (db()->query('SELECT clinic_id, COUNT(*) n, SUM(last_seen > (UTC_TIMESTAMP() - INTERVAL ' . HMS_ONLINE_MINUTES . ' MINUTE)) online FROM installs GROUP BY clinic_id') as $r) {
    $stats[(int)$r['clinic_id']] = $r;
}
$unassigned = (int)($stats[0]['n'] ?? 0);

page_header('Clinics', 'clinics.php');
?>
<h1>Clinics &amp; hospitals</h1>
<p class="muted">A clinic is a batch of PCs. Set the site URL and expiry once on the clinic and every PC in it follows.</p>
<?php if ($unassigned): ?><div class="msg err" style="background:#fff1d6;color:#92600a"><b><?= $unassigned ?></b> PC(s) are not in any clinic yet. <a href="index.php?clinic=0">Assign them</a>.</div><?php endif; ?>
<table>
<tr><th>Clinic</th><th>PCs</th><th>Access until</th><th>Site URL pushed</th><th></th></tr>
<?php foreach (clinics_all(true) as $c):
    $id = (int)$c['id'];
    $e = !empty($c['expires_at']) ? utc_ts($c['expires_at']) : null;
    $g = $s['global_expires_at'] !== '' ? utc_ts($s['global_expires_at']) : null;
    if ($g !== null && ($e === null || $g < $e)) $e = $g;
?>
<tr>
  <td><b><?= h($c['name']) ?></b> <?php if ($c['status'] === 'blocked'): ?><span class="tag bad">blocked</span><?php endif; ?></td>
  <td><?= (int)($stats[$id]['n'] ?? 0) ?> <span class="muted">(<?= (int)($stats[$id]['online'] ?? 0) ?> online)</span></td>
  <td><?= exp_tag($e, $now) ?></td>
  <td class="muted"><?= h($c['url'] ?: ($s['default_url'] ?: 'installer default')) ?></td>
  <td><a class="btn alt" href="clinic.php?id=<?= $id ?>">Manage</a></td>
</tr>
<?php endforeach; if (!clinics_all()): ?><tr><td colspan="5" class="muted">No clinics yet. Create the first one below.</td></tr><?php endif; ?>
</table>

<form method="post" class="box" style="margin-top:18px"><?= csrf_field() ?>
  <h2>New clinic</h2>
  <div class="grid">
    <div><label>Clinic / hospital name</label><input type="text" name="name" maxlength="120" required></div>
    <div><label>Site URL for all its PCs (empty = global default)</label><input type="text" name="url" placeholder="https://clinic1.example.com"></div>
    <div><label>Access expires (<?= h(cfg()['timezone'] ?? 'UTC') ?>) - empty = never</label><input type="datetime-local" name="expires_at"></div>
  </div>
  <div class="row"><button class="btn">Create clinic</button></div>
</form>
<?php page_footer();

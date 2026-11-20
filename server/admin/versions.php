<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

/* Which app version is every device on, compared with the newest build you uploaded under Downloads?
   Devices report process.platform ('win32', 'darwin', 'linux') or 'android'. */
$groups = [
    'Windows' => ['windows'],
    'Mac'     => ['mac-arm64', 'mac-x64'],
    'Linux'   => ['linux-x64', 'linux-arm64'],
    'Android' => ['android'],
];
$groupOf = ['win32' => 'Windows', 'windows' => 'Windows', 'darwin' => 'Mac', 'mac' => 'Mac', 'linux' => 'Linux', 'android' => 'Android'];

$latest = []; // newest uploaded version per platform group
foreach ($groups as $g => $keys) {
    $best = null;
    foreach ($keys as $k) {
        $r = release_latest($k);
        if ($r && ($best === null || version_compare((string)$r['version'], $best, '>'))) $best = (string)$r['version'];
    }
    $latest[$g] = $best;
}

$now = time();
$staleDays = max(1, (int)($_GET['stale'] ?? 30)); // devices silent for longer are probably uninstalled
$rows = db()->query('SELECT id, name, device, platform, app_version, clinic_id, last_seen, status FROM installs ORDER BY last_seen DESC')->fetchAll();

$counts = [];      // group => version => number of devices
$old = [];         // devices that are behind the newest upload
$perClinic = [];   // clinic name => number of devices behind
$total = 0; $upToDate = 0; $unknown = 0; $silent = 0;
foreach ($rows as $r) {
    $total++;
    $g = $groupOf[strtolower((string)$r['platform'])] ?? 'Other';
    $v = trim((string)$r['app_version']);
    $counts[$g][$v === '' ? 'unknown' : $v] = ($counts[$g][$v === '' ? 'unknown' : $v] ?? 0) + 1;
    $lv = $latest[$g] ?? null;
    if ($lv === null) { $unknown++; continue; } // nothing uploaded for this platform, so nothing to compare with
    if ($v !== '' && version_compare($v, $lv, '>=')) { $upToDate++; continue; }
    $r['group'] = $g; $r['latest'] = $lv;
    $t = utc_ts($r['last_seen']);
    $r['silent'] = $t !== null && $now - $t > $staleDays * 86400;
    if ($r['silent']) $silent++;
    $old[] = $r;
    $cl = clinic_get($r['clinic_id']);
    $name = $cl ? $cl['name'] : '(no clinic)';
    $perClinic[$name] = ($perClinic[$name] ?? 0) + 1;
}
arsort($perClinic);

page_header('Versions', 'versions.php');
?>
<h1>App versions</h1>
<p class="muted">Compares every device with the newest build you uploaded under <a href="releases.php">Downloads</a>. Devices that are behind get the update notice automatically; the ones listed below are the people to remind.
Apps older than 2.3.0 cannot show the notice: they need one manual install.</p>

<div class="cards">
  <div class="card"><b><?= $total ?></b><span>Total devices</span></div>
  <div class="card"><b><?= $upToDate ?></b><span>Up to date</span></div>
  <div class="card"><b><?= count($old) ?></b><span>Behind the newest build</span></div>
  <div class="card"><b><?= $silent ?></b><span>Behind and silent over <?= (int)$staleDays ?> days</span></div>
  <?php if ($unknown): ?><div class="card"><b><?= $unknown ?></b><span>No build uploaded to compare</span></div><?php endif; ?>
</div>

<div class="box"><h2>Newest build uploaded</h2>
  <?php foreach ($groups as $g => $_): ?><span class="tag<?= $latest[$g] === null ? '' : ' ok' ?>"><?= h($g) ?>: <?= $latest[$g] === null ? 'none uploaded' : h($latest[$g]) ?></span> <?php endforeach; ?>
</div>

<h2>Devices by version</h2>
<table>
<tr><th>Platform</th><th>Version</th><th>Devices</th><th></th></tr>
<?php foreach ($counts as $g => $vers): uksort($vers, function ($a, $b) { return version_compare((string)$b, (string)$a); });
    foreach ($vers as $v => $n):
        $lv = $latest[$g] ?? null;
        $isNew = $lv !== null && $v !== 'unknown' && version_compare((string)$v, $lv, '>=');
?>
<tr><td><?= h($g) ?></td><td class="mono"><?= h($v) ?></td><td><?= (int)$n ?></td>
  <td><?php if ($lv === null): ?><span class="muted">nothing to compare with</span><?php elseif ($isNew): ?><span class="tag ok">newest</span><?php else: ?><span class="tag warn">behind <?= h($lv) ?></span><?php endif; ?></td></tr>
<?php endforeach; endforeach; if (!$counts): ?><tr><td colspan="4" class="muted">No devices yet.</td></tr><?php endif; ?>
</table>

<?php if ($perClinic): ?>
<h2 style="margin-top:22px">Who to chase (by clinic)</h2>
<table>
<tr><th>Clinic</th><th>Devices behind</th></tr>
<?php foreach ($perClinic as $name => $n): ?><tr><td><?= h($name) ?></td><td><?= (int)$n ?></td></tr><?php endforeach; ?>
</table>

<h2 style="margin-top:22px">Devices that are behind</h2>
<table>
<tr><th>Device</th><th>Clinic</th><th>Platform</th><th>Version</th><th>Last seen</th><th></th></tr>
<?php foreach (array_slice($old, 0, 500) as $r): $cl = clinic_get($r['clinic_id']); ?>
<tr>
  <td><b><?= h($r['name'] ?: ($r['device'] ?: 'Unnamed')) ?></b></td>
  <td><?= $cl ? h($cl['name']) : '<span class="muted">none</span>' ?></td>
  <td><?= h($r['group']) ?></td>
  <td class="mono"><?= h($r['app_version'] !== '' && $r['app_version'] !== null ? $r['app_version'] : 'unknown') ?> &rarr; <?= h($r['latest']) ?></td>
  <td><?= h(ago($r['last_seen'])) ?><?= $r['silent'] ? ' <span class="tag warn">maybe uninstalled</span>' : '' ?></td>
  <td><a class="btn alt" href="install.php?id=<?= (int)$r['id'] ?>">Manage</a></td>
</tr>
<?php endforeach; ?>
</table>
<p class="muted">"Maybe uninstalled" means the device has not checked in for over <?= (int)$staleDays ?> days. Delete such devices to free their slots, or set "Remove devices not seen for N days" in <a href="settings.php">Settings</a>.</p>
<?php else: ?>
<p class="msg ok" style="margin-top:18px">Every device with a comparable build is up to date.</p>
<?php endif; ?>
<?php page_footer();

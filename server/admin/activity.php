<?php
declare(strict_types=1);
require __DIR__ . '/../lib/common.php';
require_admin();

/* Who changed what and when. Useful when more than one person uses the panel. */
$q = clip($_GET['q'] ?? '', 80);
$sql = 'SELECT * FROM activity_log';
$args = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql .= ' WHERE actor LIKE ? OR action LIKE ? OR detail LIKE ?';
    array_push($args, $like, $like, $like);
}
$st = db()->prepare($sql . ' ORDER BY id DESC LIMIT 300');
$st->execute($args);
$rows = $st->fetchAll();

page_header('Activity', 'activity.php');
?>
<h1>Activity log</h1>
<p class="muted">The last 300 changes made in this panel: a code extended, a device locked, a notice sent. Entries older than 13 months are removed automatically.</p>
<form method="get" class="row" style="margin:0 0 12px"><input type="text" name="q" value="<?= h($q) ?>" placeholder="Search person, action or detail" style="max-width:340px"><button class="btn alt">Search</button></form>
<table class="rt">
<tr class="hd"><th>When (<?= h(cfg()['timezone'] ?? 'UTC') ?>)</th><th>Who</th><th>Action</th><th>Details</th><th>From IP</th></tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td data-l="When"><?= h(fmt_local($r['at'], 'Y-m-d H:i:s')) ?><br><span class="muted"><?= h(ago($r['at'])) ?></span></td>
  <td data-l="Who"><b><?= h($r['actor']) ?></b></td>
  <td data-l="Action"><?= h($r['action']) ?></td>
  <td data-l="Details"><?= h($r['detail']) ?></td>
  <td data-l="IP" class="muted"><?= h($r['ip']) ?></td>
</tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5" class="muted">Nothing logged yet. Changes you make from now on appear here.</td></tr><?php endif; ?>
</table>
<?php page_footer();

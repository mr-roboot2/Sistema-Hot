<?php
require_once __DIR__ . '/../includes/auth.php';
requireLoginAlways();
if (!isAdmin()) { header('Location: ' . SITE_URL . '/index'); exit; }
session_write_close(); // libera lock de sessão

$db        = getDB();
$pageTitle = 'Estatísticas';

// ── Conteúdo — uma query em vez de 6 ──────────
$statsRow = $db->query('
    SELECT
        SUM(status="published")  AS posts,
        SUM(status="draft")      AS drafts,
        COALESCE(SUM(views),0)   AS views
    FROM posts
')->fetch();
$mediaRow = $db->query('
    SELECT
        SUM(file_type="image")     AS images,
        SUM(file_type="video")     AS videos,
        COALESCE(SUM(file_size),0) AS size
    FROM media
')->fetch();
$stats = [
    'posts'  => (int)$statsRow['posts'],
    'drafts' => (int)$statsRow['drafts'],
    'views'  => (int)$statsRow['views'],
    'images' => (int)$mediaRow['images'],
    'videos' => (int)$mediaRow['videos'],
    'size'   => (int)$mediaRow['size'],
];

// ── Usuários — uma query em vez de 6 ──────────
$usersRow = $db->query('
    SELECT
        COUNT(*)                                                               AS total,
        SUM(role!="admin" AND (expires_at IS NULL OR expires_at > NOW()))     AS active,
        SUM(role!="admin" AND expires_at IS NOT NULL AND expires_at <= NOW()) AS expired,
        SUM(role!="admin" AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 3 DAY)) AS expiring,
        SUM(role!="admin" AND plan_id IS NULL)                                AS no_plan,
        SUM(webhook_source IS NOT NULL)                                       AS webhook
    FROM users WHERE role!="admin"
')->fetch();
$users = [
    'total'    => (int)$usersRow['total'],
    'active'   => (int)$usersRow['active'],
    'expired'  => (int)$usersRow['expired'],
    'expiring' => (int)$usersRow['expiring'],
    'no_plan'  => (int)$usersRow['no_plan'],
    'webhook'  => (int)$usersRow['webhook'],
];


// ── Usuários por plano ────────────────────────
$usersByPlan = $db->query('
    SELECT p.name, p.color, p.duration_days,
           COALESCE(p.price,0) AS price,
           COUNT(u.id) AS total,
           SUM(CASE WHEN u.expires_at > NOW() OR u.expires_at IS NULL THEN 1 ELSE 0 END) AS active
    FROM plans p
    LEFT JOIN users u ON u.plan_id = p.id AND u.role != "admin"
    GROUP BY p.id ORDER BY total DESC
')->fetchAll();

// ── Novos usuários por mês ────────────────────
$newUsersByMonth = $db->query('
    SELECT DATE_FORMAT(created_at,"%Y-%m") AS month,
           DATE_FORMAT(created_at,"%b/%Y") AS label,
           COUNT(*) AS total
    FROM users WHERE role!="admin"
      AND created_at >= DATE_SUB(NOW(),INTERVAL 6 MONTH)
    GROUP BY month ORDER BY month ASC
')->fetchAll();

// ── Top posts ─────────────────────────────────
$topPosts = $db->query('
    SELECT p.id, p.title, p.views, p.created_at,
           c.name AS cat_name, c.color AS cat_color,
           COALESCE(p.thumbnail,(SELECT file_path FROM media WHERE post_id=p.id AND file_type="image" ORDER BY id LIMIT 1)) AS thumb
    FROM posts p LEFT JOIN categories c ON p.category_id=c.id
    WHERE p.status="published" ORDER BY p.views DESC LIMIT 8
')->fetchAll();

// ── Posts por mês ─────────────────────────────
$postsByMonth = $db->query('
    SELECT DATE_FORMAT(created_at,"%Y-%m") AS month,
           DATE_FORMAT(created_at,"%b/%Y") AS label,
           COUNT(*) AS total
    FROM posts WHERE created_at >= DATE_SUB(NOW(),INTERVAL 6 MONTH)
    GROUP BY month ORDER BY month ASC
')->fetchAll();

// ── Webhooks recentes ─────────────────────────
$recentWebhooks = [];
try {
    $recentWebhooks = $db->query('
        SELECT l.event, l.telegram_id, l.plan_name, l.amount, l.status, l.user_created, l.created_at, u.name AS user_name
        FROM webhook_logs l LEFT JOIN users u ON l.user_id=u.id
        ORDER BY l.created_at DESC LIMIT 6
    ')->fetchAll();
} catch(Exception $e) {}

// ── Expirando em breve ────────────────────────
$expiringSoon = $db->query('
    SELECT u.name, u.email, u.expires_at, p.name AS plan_name, p.color AS plan_color
    FROM users u LEFT JOIN plans p ON u.plan_id=p.id
    WHERE u.role!="admin" AND u.expires_at BETWEEN NOW() AND DATE_ADD(NOW(),INTERVAL 7 DAY)
    ORDER BY u.expires_at ASC LIMIT 8
')->fetchAll();

// Cache de 30s para stats — queries pesadas que não precisam ser real-time
if (function_exists('apcu_fetch') && !isset($_GET['nocache'])) {
    $ck = 'admin_stats_' . md5(serialize([]));
    $cached = apcu_fetch($ck, $ok);
    if ($ok) { require __DIR__ . '/../includes/header.php'; echo $cached; require __DIR__ . '/../includes/footer.php'; exit; }
    ob_start();
}
require __DIR__ . '/../includes/header.php';

function bar(int $val, int $max, string $color='var(--accent)'): string {
    $pct = $max > 0 ? min(100,round($val/$max*100)) : 0;
    return "<div style='height:6px;background:var(--surface3);border-radius:3px;margin-top:5px;overflow:hidden'>
              <div style='height:100%;width:{$pct}%;background:{$color};border-radius:3px'></div></div>";
}
function fmt(float $v): string { return 'R$ '.number_format($v,2,',','.'); }
function barH(array $data, string $key, string $color, int $h=22): string {
    $max = $data ? max(array_column($data,$key)) : 1;
    $out = "<div style='display:flex;flex-direction:column;gap:8px'>";
    foreach ($data as $d) {
        $pct = $max>0 ? round((float)$d[$key]/$max*100) : 0;
        $out .= "<div style='display:flex;align-items:center;gap:10px'>
            <div style='width:64px;font-size:11px;color:var(--muted);text-align:right;flex-shrink:0'>{$d['label']}</div>
            <div style='flex:1;height:{$h}px;background:var(--surface2);border-radius:4px;overflow:hidden'>
              <div style='height:100%;width:{$pct}%;background:{$color};border-radius:4px;display:flex;align-items:center;padding-left:8px'>
                <span style='font-size:11px;font-weight:700;color:#fff;white-space:nowrap'>{$d[$key]}</span>
              </div>
            </div></div>";
    }
    return $out.'</div>';
}
?>
<style>
.sg{display:grid;gap:14px}
.sg-4{grid-template-columns:repeat(4,1fr)}
.sg-3{grid-template-columns:repeat(3,1fr)}
.sg-2{grid-template-columns:1fr 1fr}
@media(max-width:900px){.sg-4{grid-template-columns:repeat(2,1fr)}.sg-3,.sg-2{grid-template-columns:1fr}}
.kpi{background:var(--surface);border:1px solid var(--border);border-radius:13px;padding:18px}
.kpi-val{font-family:'Roboto',sans-serif;font-size:26px;font-weight:800;line-height:1;margin-bottom:5px}
.kpi-lbl{font-size:12px;color:var(--muted)}
.kpi-sub{font-size:11px;color:var(--muted);margin-top:4px}
.sec{margin-bottom:28px}
.sec-title{font-family:'Roboto',sans-serif;font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:7px}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:13px;padding:20px}
.rank-row{display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--border)}
.rank-row:last-child{border:none}
.rank-num{font-family:'Roboto',sans-serif;font-size:16px;font-weight:800;color:var(--muted);width:22px;text-align:center;flex-shrink:0}
.rank-thumb{width:38px;height:38px;border-radius:6px;object-fit:cover;background:var(--surface2);flex-shrink:0}
.rank-info{flex:1;min-width:0}
.rank-title{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rank-sub{font-size:11px;color:var(--muted)}
.tag{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700}
</style>


<!-- ── KPIs Usuários ── -->
<div class="sec">
  <div class="sec-title">👥 Usuários</div>
  <div class="sg sg-4" style="margin-bottom:0">
    <div class="kpi">
      <div class="kpi-val" style="color:var(--accent)"><?= number_format($users['total']) ?></div>
      <div class="kpi-lbl">Total de usuários</div>
      <?= bar((int)$users['active'], (int)$users['total'], 'var(--success)') ?>
    </div>
    <div class="kpi">
      <div class="kpi-val" style="color:var(--success)"><?= number_format($users['active']) ?></div>
      <div class="kpi-lbl">Com acesso ativo</div>
    </div>
    <div class="kpi">
      <div class="kpi-val" style="color:var(--danger)"><?= number_format($users['expired']) ?></div>
      <div class="kpi-lbl">Expirados</div>
    </div>
    <div class="kpi">
      <div class="kpi-val" style="color:var(--warning)"><?= number_format($users['expiring']) ?></div>
      <div class="kpi-lbl">Expiram em 3 dias</div>
    </div>
  </div>
</div>

<!-- ── KPIs Conteúdo ── -->
<div class="sec">
  <div class="sec-title">📦 Conteúdo</div>
  <div class="sg sg-4" style="margin-bottom:0">
    <div class="kpi">
      <div class="kpi-val" style="color:var(--accent)"><?= number_format($stats['posts']) ?></div>
      <div class="kpi-lbl">Posts publicados</div>
    </div>
    <div class="kpi">
      <div class="kpi-val" style="color:var(--accent2)"><?= number_format($stats['views']) ?></div>
      <div class="kpi-lbl">Visualizações totais</div>
    </div>
    <div class="kpi">
      <div class="kpi-val" style="color:var(--accent3,#00d4aa)"><?= number_format($stats['videos']) ?></div>
      <div class="kpi-lbl">Vídeos</div>
    </div>
    <div class="kpi">
      <div class="kpi-val" style="color:var(--muted2)"><?= formatFileSize((int)$stats['size']) ?></div>
      <div class="kpi-lbl">Espaço usado</div>
    </div>
  </div>
</div>

<!-- ── Gráficos ── -->
<div class="sg sg-2 sec">

  <?php if ($hasTx && $revenueByMonth): ?>
  <!-- Receita por mês -->
  <div class="panel">
    <div class="sec-title">💰 Receita por Mês</div>
    <?php
    $maxRev = max(array_column($revenueByMonth,'total'));
    echo "<div style='display:flex;flex-direction:column;gap:10px'>";
    foreach ($revenueByMonth as $m) {
        $pct = $maxRev>0 ? round($m['total']/$maxRev*100) : 0;
        echo "<div style='display:flex;align-items:center;gap:10px'>
            <div style='width:64px;font-size:11px;color:var(--muted);text-align:right;flex-shrink:0'>{$m['label']}</div>
            <div style='flex:1;height:22px;background:var(--surface2);border-radius:4px;overflow:hidden'>
              <div style='height:100%;width:{$pct}%;background:var(--success);border-radius:4px;display:flex;align-items:center;padding-left:8px'>
                <span style='font-size:11px;font-weight:700;color:#fff;white-space:nowrap'>".fmt((float)$m['total'])."</span>
              </div>
            </div>
            <div style='font-size:11px;color:var(--muted);width:30px;text-align:right;flex-shrink:0'>{$m['count']}x</div>
          </div>";
    }
    echo "</div>";
    ?>
  </div>
  <?php endif; ?>

  <!-- Novos usuários por mês -->
  <div class="panel">
    <div class="sec-title">👤 Novos Usuários por Mês</div>
    <?php if ($newUsersByMonth): ?>
    <?= barH($newUsersByMonth,'total','var(--accent)') ?>
    <?php else: ?><div style="color:var(--muted);font-size:13px">Sem dados.</div><?php endif; ?>
  </div>

  <!-- Posts por mês -->
  <div class="panel">
    <div class="sec-title">📝 Posts por Mês</div>
    <?php if ($postsByMonth): ?>
    <?= barH($postsByMonth,'total','var(--accent2)') ?>
    <?php else: ?><div style="color:var(--muted);font-size:13px">Sem dados.</div><?php endif; ?>
  </div>

  <!-- Usuários por plano -->
  <div class="panel">
    <div class="sec-title">📦 Usuários por Plano</div>
    <?php if ($usersByPlan): ?>
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php foreach ($usersByPlan as $pl):
        $maxPl = max(array_column($usersByPlan,'total')) ?: 1;
        $pct   = round($pl['total']/$maxPl*100);
      ?>
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
          <div style="display:flex;align-items:center;gap:7px">
            <div style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($pl['color']) ?>;flex-shrink:0"></div>
            <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($pl['name']) ?></span>
            <?php if ($pl['price'] > 0): ?>
            <span style="font-size:11px;color:var(--success)">R$ <?= number_format((float)$pl['price'],2,',','.') ?></span>
            <?php endif; ?>
          </div>
          <div class="txt-muted-sm"><?= $pl['total'] ?> total · <?= $pl['active'] ?> ativos</div>
        </div>
        <div style="height:8px;background:var(--surface2);border-radius:4px;overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= htmlspecialchars($pl['color']) ?>;border-radius:4px"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?><div style="color:var(--muted);font-size:13px">Sem dados.</div><?php endif; ?>
  </div>
</div>

<!-- ── Expirando em breve ── -->
<?php if ($expiringSoon): ?>
<div class="sec">
  <div class="sec-title">⚠️ Expirando em 7 Dias</div>
  <div class="panel">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
      <?php foreach ($expiringSoon as $u):
        $days = ceil((strtotime($u['expires_at'])-time())/86400);
        $c    = $days<=1?'var(--danger)':($days<=3?'var(--warning)':'var(--muted2)');
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--surface2);border-radius:9px;border:1px solid var(--border)">
        <div style="width:34px;height:34px;border-radius:50%;background:<?= htmlspecialchars($u['plan_color']??'var(--accent)') ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;flex-shrink:0">
          <?= strtoupper(substr($u['name'],0,1)) ?>
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($u['name']) ?></div>
          <div class="txt-muted-xs"><?= htmlspecialchars($u['plan_name']??'—') ?></div>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <div style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:700;color:<?= $c ?>">
            <?= $days ?>d
          </div>
          <div style="font-size:10px;color:var(--muted)"><?= date('d/m',strtotime($u['expires_at'])) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Top Posts + Webhooks ── -->
<div class="sg sg-2 sec">
  <div class="panel">
    <div class="sec-title">🏆 Posts Mais Vistos</div>
    <?php foreach ($topPosts as $i=>$p): ?>
    <div class="rank-row">
      <div class="rank-num"><?= $i+1 ?></div>
      <?php if ($p['thumb']): ?>
      <img class="rank-thumb" src="<?= UPLOAD_URL.htmlspecialchars($p['thumb']) ?>" loading="lazy">
      <?php else: ?>
      <div class="rank-thumb" style="display:flex;align-items:center;justify-content:center">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--border2)" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
      </div>
      <?php endif; ?>
      <div class="rank-info">
        <a href="<?= SITE_URL ?>/post/<?= $p['id'] ?>" class="rank-title" style="display:block"><?= htmlspecialchars($p['title']) ?></a>
        <?php if ($p['cat_name']): ?>
        <div class="rank-sub" style="color:<?= htmlspecialchars($p['cat_color']) ?>"><?= htmlspecialchars($p['cat_name']) ?></div>
        <?php endif; ?>
      </div>
      <div style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:700;color:var(--accent);flex-shrink:0"><?= number_format($p['views']) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if(!$topPosts): ?><div style="color:var(--muted);font-size:13px">Sem dados.</div><?php endif; ?>
  </div>

  <?php if ($recentWebhooks): ?>
  <div class="panel">
    <div class="sec-title">📡 Webhooks Recentes</div>
    <?php foreach ($recentWebhooks as $w): ?>
    <div class="rank-row">
      <div style="flex:1;min-width:0">
        <div style="display:flex;align-items:center;gap:7px;margin-bottom:3px">
          <span style="font-size:12px;font-weight:600"><?= htmlspecialchars($w['user_name']??('tg:'.$w['telegram_id'])) ?></span>
          <?php if ($w['user_created']): ?><span class="tag" style="background:rgba(124,106,255,.15);color:var(--accent);font-size:10px">NOVO</span><?php endif; ?>
          <span class="tag" style="background:<?= $w['status']==='ok'?'rgba(16,185,129,.15)':'rgba(255,77,106,.15)' ?>;color:<?= $w['status']==='ok'?'var(--success)':'var(--danger)' ?>">
            <?= strtoupper($w['status']) ?>
          </span>
        </div>
        <div class="txt-muted-xs">
          <?= htmlspecialchars($w['plan_name']??'—') ?>
          <?php if ($w['amount']): ?> · R$ <?= number_format($w['amount'],2,',','.') ?><?php endif; ?>
          · <?= timeAgo($w['created_at']) ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <a href="<?= SITE_URL ?>/admin/webhook.php" style="display:block;text-align:center;margin-top:14px;font-size:12px;color:var(--accent)">Ver todos os logs →</a>
  </div>
  <?php endif; ?>
</div>

<?php
if (function_exists('apcu_store') && !isset($_GET['nocache'])) {
    $ck = 'admin_stats_' . md5(serialize([]));
    $html = ob_get_contents(); ob_end_flush();
    if ($html) apcu_store($ck, $html, 30);
}
require __DIR__ . '/../includes/footer.php';
?>

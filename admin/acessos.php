<?php
/**
 * admin/acessos.php — Geração e gerenciamento de códigos de acesso (pré-pagos).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
requireLoginAlways();
if ((currentUser()['role'] ?? '') !== 'admin') { header('Location: ' . SITE_URL . '/index'); exit; }

$db        = getDB();
$pageTitle = 'Códigos de acesso';
$message   = '';
$error     = '';
$generated = [];
$schemaError = '';

try {
    ensureAccessCodeColumn();
} catch (Exception $e) {
    $schemaError = 'Não foi possível preparar o esquema do banco (colunas access_code/is_anonymous/first_login_at/created_by). '
                 . 'Verifique se o usuário do MySQL tem permissão ALTER TABLE. Detalhe: ' . $e->getMessage();
}

$plans = $db->query('SELECT id,name,color,duration_days,price FROM plans WHERE active=1 ORDER BY price ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $qty    = max(1, min(200, (int)($_POST['qty'] ?? 1)));
        $mode   = ($_POST['activation'] ?? 'now') === 'first_use' ? 'first_use' : 'now';
        $prefix = trim($_POST['prefix'] ?? ''); // rótulo opcional no nome

        $plan = $db->prepare('SELECT id,name,duration_days FROM plans WHERE id=? AND active=1');
        $plan->execute([$planId]); $plan = $plan->fetch();
        if (!$plan) {
            $error = 'Selecione um plano válido.';
        } else {
            $adminId = (int)($_SESSION['user_id'] ?? 0);
            $dbFailures = 0;
            $lastDbError = '';
            for ($i = 0; $i < $qty; $i++) {
                try {
                    $code = generateAccessCode();
                    $name = 'Anônimo' . ($prefix ? ' · ' . $prefix : '');
                    $email = 'anon_' . strtolower(str_replace('-', '', $code)) . '@local.cms';
                    $expiresAt = ($mode === 'now')
                        ? date('Y-m-d H:i:s', time() + (int)$plan['duration_days'] * 86400)
                        : null;
                    $db->prepare('INSERT INTO users
                        (name,email,password,role,plan_id,expires_at,access_code,is_anonymous,created_by)
                        VALUES (?,?,?,?,?,?,?,1,?)')
                       ->execute([
                           $name, $email,
                           password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                           'viewer', (int)$plan['id'], $expiresAt,
                           $code, $adminId ?: null,
                       ]);
                    $generated[] = [
                        'code'       => $code,
                        'plan'       => $plan['name'],
                        'expires_at' => $expiresAt,
                        'mode'       => $mode,
                    ];
                } catch(Exception $e) {
                    $dbFailures++;
                    $lastDbError = $e->getMessage();
                }
            }
            if ($generated) {
                auditLog('generate_access_codes', 'plan:'.$plan['id'].' qty:'.count($generated), $plan['name']);
            }
            if (!$generated && $dbFailures) {
                $error = 'Falha ao gerar códigos: ' . $lastDbError;
            } else {
                $message = count($generated) . ' código' . (count($generated)!==1?'s':'') . ' gerado' . (count($generated)!==1?'s':'') . ' com sucesso'
                         . ($dbFailures ? " ({$dbFailures} falha(s): " . htmlspecialchars($lastDbError) . ")" : '') . '.';
            }
        }
    }

    if ($action === 'delete') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid) {
            $chk = $db->prepare('SELECT is_anonymous,first_login_at FROM users WHERE id=?');
            $chk->execute([$uid]); $chk = $chk->fetch();
            if (!$chk || empty($chk['is_anonymous'])) {
                $error = 'Só é possível remover usuários anônimos por aqui.';
            } elseif (!empty($chk['first_login_at'])) {
                $error = 'Código já foi utilizado — remova em Usuários se realmente quiser apagar.';
            } else {
                $db->prepare('DELETE FROM users WHERE id=? AND is_anonymous=1 AND first_login_at IS NULL')->execute([$uid]);
                auditLog('delete_access_code','user:'.$uid,'código avulso');
                $message = 'Código removido.';
            }
        }
    }

    if ($action === 'regen') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid) {
            $newCode = generateAccessCode();
            $db->prepare('UPDATE users SET access_code=? WHERE id=? AND is_anonymous=1')->execute([$newCode, $uid]);
            auditLog('regen_access_code','user:'.$uid,'código avulso');
            $message = 'Novo código: <code style="background:var(--surface2);padding:3px 10px;border-radius:6px;font-weight:700">'.$newCode.'</code>';
        }
    }
}

// ── Filtro e busca ─────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$whereParts = ['u.is_anonymous = 1'];
$params = [];
if ($filter === 'unused')  { $whereParts[] = 'u.first_login_at IS NULL'; }
if ($filter === 'used')    { $whereParts[] = 'u.first_login_at IS NOT NULL'; }
if ($filter === 'expired') { $whereParts[] = '(u.expires_at IS NOT NULL AND u.expires_at < NOW())'; }
if ($filter === 'active')  { $whereParts[] = '(u.expires_at IS NULL OR u.expires_at > NOW())'; }
if ($search) {
    $whereParts[] = '(u.access_code LIKE ? OR u.name LIKE ?)';
    array_push($params, "%$search%", "%$search%");
}
$where = 'WHERE ' . implode(' AND ', $whereParts);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$totalCount = $db->prepare("SELECT COUNT(*) FROM users u $where");
$totalCount->execute($params); $totalCount = (int)$totalCount->fetchColumn();
$totalPages = (int)ceil($totalCount / $perPage);

$rows = $db->prepare("
    SELECT u.id,u.name,u.access_code,u.plan_id,u.expires_at,u.first_login_at,u.created_at,
           p.name AS plan_name, p.color AS plan_color, p.duration_days
    FROM users u
    LEFT JOIN plans p ON p.id = u.plan_id
    $where
    ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$rows->execute($params);
$codes = $rows->fetchAll();

// Stats
$stats = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(first_login_at IS NULL) AS unused,
        SUM(first_login_at IS NOT NULL) AS used,
        SUM(expires_at IS NOT NULL AND expires_at < NOW()) AS expired
    FROM users WHERE is_anonymous=1
")->fetch();

require __DIR__ . '/../includes/header.php';
?>
<style>
.ac-grid{display:grid;grid-template-columns:1fr 330px;gap:20px;align-items:start}
@media(max-width:960px){.ac-grid{grid-template-columns:1fr}}
.ac-stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;flex:1;min-width:120px}
.ac-stat-v{font-family:'Roboto',sans-serif;font-size:22px;font-weight:800}
.ac-stat-l{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.ac-tbl{width:100%;border-collapse:collapse}
.ac-tbl td,.ac-tbl th{padding:10px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
.ac-tbl th{font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;text-align:left}
.ac-tbl tbody tr:hover td{background:var(--surface2)}
.ac-code{font-family:'Courier New',monospace;font-size:13px;font-weight:800;letter-spacing:1.5px;color:var(--accent)}
.ac-pill{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700}
.ac-pill-unused{background:rgba(124,106,255,.15);color:var(--accent)}
.ac-pill-used{background:rgba(16,185,129,.15);color:var(--success)}
.ac-pill-expired{background:rgba(255,77,106,.15);color:var(--danger)}
.gen-box{background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.35);border-radius:12px;padding:18px;margin-bottom:20px}
.gen-code-row{display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--surface2);border-radius:7px;margin-top:6px;font-family:'Courier New',monospace;font-size:14px;font-weight:700;letter-spacing:2px}
.ftab{display:inline-block;padding:6px 14px;margin-right:6px;border-radius:20px;font-size:12px;font-weight:700;text-decoration:none;background:var(--surface2);color:var(--muted2);border:1px solid var(--border)}
.ftab.on{background:var(--accent);color:#fff;border-color:var(--accent)}
</style>

<?php if ($schemaError): ?><div class="alert alert-danger" style="margin-bottom:16px">⚠️ <?= htmlspecialchars($schemaError) ?></div><?php endif; ?>
<?php if ($message): ?><div class="alert alert-success" style="margin-bottom:16px">✅ <?= $message ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"  style="margin-bottom:16px">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if ($generated): ?>
<div class="gen-box">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:800;color:var(--success)">🎁 <?= count($generated) ?> código<?= count($generated)!==1?'s':'' ?> gerado<?= count($generated)!==1?'s':'' ?></div>
    <div style="display:flex;gap:8px">
      <button type="button" onclick="copyAll()" class="btn btn-primary" style="padding:6px 14px;font-size:12px">📋 Copiar todos</button>
      <a href="?export_last=1" onclick="return false" id="dl-csv" class="btn btn-secondary" style="padding:6px 14px;font-size:12px">📥 CSV</a>
    </div>
  </div>
  <div id="gen-list">
  <?php foreach ($generated as $g): ?>
    <div class="gen-code-row">
      <span style="flex:1"><?= htmlspecialchars($g['code']) ?></span>
      <span style="font-size:11px;color:var(--muted2);font-family:'Roboto',sans-serif;font-weight:500;letter-spacing:0">
        <?= htmlspecialchars($g['plan']) ?> ·
        <?= $g['mode']==='first_use' ? 'ativa no 1º uso' : 'válido até '.date('d/m/Y', strtotime($g['expires_at'])) ?>
      </span>
      <button type="button" onclick="copyText(this,'<?= htmlspecialchars($g['code']) ?>')" style="background:none;border:none;color:var(--accent);cursor:pointer;padding:4px 8px;font-size:14px">📋</button>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<script>
var GEN_CODES = <?= json_encode(array_map(function($g){return $g['code'];}, $generated)) ?>;
var GEN_META  = <?= json_encode($generated) ?>;
function copyAll() {
  var text = GEN_CODES.join('\n');
  navigator.clipboard?.writeText(text).then(function(){ alert('✅ ' + GEN_CODES.length + ' códigos copiados!'); }).catch(function(){
    var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); alert('Códigos copiados.');
  });
}
document.getElementById('dl-csv').addEventListener('click', function(e){
  e.preventDefault();
  var csv = 'codigo;plano;validade\n';
  GEN_META.forEach(function(g){
    csv += g.code + ';' + g.plan + ';' + (g.expires_at || 'primeiro uso') + '\n';
  });
  var blob = new Blob(["﻿"+csv], {type:'text/csv;charset=utf-8'});
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a'); a.href = url; a.download = 'codigos_' + Date.now() + '.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
});
</script>
<?php endif; ?>

<!-- Stats -->
<div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap">
  <div class="ac-stat"><div class="ac-stat-v"><?= (int)$stats['total'] ?></div><div class="ac-stat-l">Total</div></div>
  <div class="ac-stat"><div class="ac-stat-v" style="color:var(--accent)"><?= (int)$stats['unused'] ?></div><div class="ac-stat-l">Não usados</div></div>
  <div class="ac-stat"><div class="ac-stat-v" style="color:var(--success)"><?= (int)$stats['used'] ?></div><div class="ac-stat-l">Usados</div></div>
  <div class="ac-stat"><div class="ac-stat-v" style="color:var(--danger)"><?= (int)$stats['expired'] ?></div><div class="ac-stat-l">Expirados</div></div>
</div>

<div class="ac-grid">

<!-- Lista -->
<div class="card" style="overflow:hidden">
  <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700">Códigos (<?= $totalCount ?>)</span>
    <div style="margin-left:auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <a href="?filter=all<?= $search?'&q='.urlencode($search):'' ?>"     class="ftab <?= $filter==='all'?'on':'' ?>">Todos</a>
      <a href="?filter=unused<?= $search?'&q='.urlencode($search):'' ?>"  class="ftab <?= $filter==='unused'?'on':'' ?>">Não usados</a>
      <a href="?filter=used<?= $search?'&q='.urlencode($search):'' ?>"    class="ftab <?= $filter==='used'?'on':'' ?>">Usados</a>
      <a href="?filter=active<?= $search?'&q='.urlencode($search):'' ?>"  class="ftab <?= $filter==='active'?'on':'' ?>">Válidos</a>
      <a href="?filter=expired<?= $search?'&q='.urlencode($search):'' ?>" class="ftab <?= $filter==='expired'?'on':'' ?>">Expirados</a>
      <form method="GET" style="display:flex;gap:6px;margin-left:8px">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar código..." class="form-control" style="width:180px;padding:6px 10px;font-size:12px">
      </form>
    </div>
  </div>
  <?php if (!$codes): ?>
  <div style="padding:40px;text-align:center;color:var(--muted);font-size:13px">
    <?= $totalCount===0 ? 'Nenhum código gerado ainda. Use o painel ao lado →' : 'Nenhum resultado para os filtros atuais.' ?>
  </div>
  <?php else: ?>
  <table class="ac-tbl">
    <thead><tr>
      <th>CÓDIGO</th><th>PLANO</th><th>VALIDADE</th><th>STATUS</th><th>CRIADO</th><th style="text-align:right">AÇÕES</th>
    </tr></thead>
    <tbody>
    <?php foreach ($codes as $c):
      $isUsed    = !empty($c['first_login_at']);
      $expTs     = $c['expires_at'] ? strtotime($c['expires_at']) : null;
      $isExpired = $expTs && $expTs < time();
    ?>
    <tr>
      <td>
        <span class="ac-code"><?= htmlspecialchars($c['access_code']) ?></span>
        <button type="button" onclick="copyText(this,'<?= htmlspecialchars($c['access_code']) ?>')"
                style="background:none;border:none;color:var(--muted);cursor:pointer;padding:0 6px;font-size:12px" title="Copiar">📋</button>
      </td>
      <td>
        <?php if ($c['plan_name']): ?>
        <div style="font-size:12px;font-weight:600;color:<?= htmlspecialchars($c['plan_color'] ?: '#7c6aff') ?>"><?= htmlspecialchars($c['plan_name']) ?></div>
        <div style="font-size:11px;color:var(--muted)"><?= (int)$c['duration_days'] ?> dia<?= $c['duration_days']>1?'s':'' ?></div>
        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
      </td>
      <td style="font-size:12px">
        <?php if (!$expTs): ?>
          <span style="color:var(--accent2)">Ativa no 1º uso</span>
        <?php elseif ($isExpired): ?>
          <span style="color:var(--danger)">Expirou em <?= date('d/m/Y', $expTs) ?></span>
        <?php else: ?>
          <span style="color:var(--muted2)">Até <?= date('d/m/Y', $expTs) ?></span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($isExpired): ?>
          <span class="ac-pill ac-pill-expired">Expirado</span>
        <?php elseif ($isUsed): ?>
          <span class="ac-pill ac-pill-used">✓ Usado</span>
          <div style="font-size:10px;color:var(--muted);margin-top:2px">em <?= date('d/m H:i', strtotime($c['first_login_at'])) ?></div>
        <?php else: ?>
          <span class="ac-pill ac-pill-unused">Não usado</span>
        <?php endif; ?>
      </td>
      <td style="font-size:11px;color:var(--muted)"><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
      <td style="text-align:right;white-space:nowrap">
        <form method="POST" style="display:inline" onsubmit="return confirm('Regenerar código?\nO atual vai parar de funcionar.')">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="regen">
          <input type="hidden" name="uid" value="<?= $c['id'] ?>">
          <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:11px" title="Gerar novo">🔄</button>
        </form>
        <?php if (!$isUsed): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Remover este código?')">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="uid" value="<?= $c['id'] ?>">
          <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:11px">🗑️</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  $baseUrl = '?' . http_build_query(array_diff_key($_GET, ['page'=>'']));
  echo renderPagination($page, $totalPages, rtrim($baseUrl,'?&'));
  ?>
  <?php endif; ?>
</div>

<!-- Form de geração -->
<div class="card" style="padding:22px;position:sticky;top:20px">
  <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;margin-bottom:6px">🎁 Gerar códigos</div>
  <div style="font-size:12px;color:var(--muted);margin-bottom:18px;line-height:1.55">
    Cria códigos que ativam acesso anônimo ao plano escolhido, sem cadastro.
  </div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="generate">

    <div class="form-group">
      <label class="form-label">Plano</label>
      <select name="plan_id" class="form-control" required>
        <option value="">— selecione —</option>
        <?php foreach ($plans as $pl): ?>
        <option value="<?= $pl['id'] ?>" <?= (isset($_POST['plan_id'])&&(int)$_POST['plan_id']===(int)$pl['id'])?'selected':'' ?>>
          <?= htmlspecialchars($pl['name']) ?> · <?= (int)$pl['duration_days'] ?>d · R$ <?= number_format((float)$pl['price'],2,',','.') ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Quantidade</label>
      <input type="number" name="qty" class="form-control" required min="1" max="200" value="<?= htmlspecialchars($_POST['qty'] ?? '1') ?>">
      <div class="txt-muted-mt">Máximo 200 por vez.</div>
    </div>

    <div class="form-group">
      <label class="form-label">Início da validade</label>
      <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px 12px;background:var(--surface2);border-radius:8px;margin-bottom:6px">
        <input type="radio" name="activation" value="first_use" checked style="margin-top:3px;accent-color:var(--accent)">
        <div>
          <div style="font-size:13px;font-weight:600">No primeiro uso</div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">Validade começa quando o usuário fizer login com o código.</div>
        </div>
      </label>
      <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:10px 12px;background:var(--surface2);border-radius:8px">
        <input type="radio" name="activation" value="now" style="margin-top:3px;accent-color:var(--accent)">
        <div>
          <div style="font-size:13px;font-weight:600">Agora</div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px">Validade começa a contar no momento da geração.</div>
        </div>
      </label>
    </div>

    <div class="form-group">
      <label class="form-label">Rótulo <span style="color:var(--muted);font-weight:400;font-size:11px">(opcional)</span></label>
      <input type="text" name="prefix" class="form-control" maxlength="40" value="<?= htmlspecialchars($_POST['prefix'] ?? '') ?>" placeholder="Ex: Campanha Instagram, Lote-15">
      <div class="txt-muted-mt">Ajuda a identificar lotes na lista de usuários.</div>
    </div>

    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">🎯 Gerar códigos</button>
  </form>
</div>

</div>

<script>
function copyText(btn, text) {
  function done(){ var o=btn.textContent; btn.textContent='✅'; setTimeout(function(){ btn.textContent=o; },1500); }
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(function(){
      var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');done();}catch(e){} document.body.removeChild(ta);
    });
  } else {
    var ta=document.createElement('textarea'); ta.value=text; ta.style.cssText='position:fixed;top:-9999px'; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');done();}catch(e){} document.body.removeChild(ta);
  }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

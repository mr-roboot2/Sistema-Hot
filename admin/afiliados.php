<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/affiliate.php';
requireLoginAlways();
if (!isAdmin()) { header('Location: ' . SITE_URL . '/index'); exit; }
session_write_close(); // libera lock de sessão

$db        = getDB();
$pageTitle = 'Afiliados';

affiliateEnsureTables();

$message = $error = '';

// ── Ações POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Criar afiliado manualmente
    if ($action === 'create') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $code   = strtoupper(trim($_POST['code'] ?? ''));
        if (!$userId) { $error = 'Selecione um usuário.'; }
        elseif (!$code) { $code = affiliateGenerateCode('AFF'); }

        if (!$error) {
            try {
                $db->prepare('INSERT INTO affiliates (user_id, code) VALUES (?,?)')->execute([$userId, $code]);
                $message = "✅ Afiliado criado com código <b>{$code}</b>.";
            } catch(Exception $e) {
                $error = 'Código já existe ou usuário já é afiliado.';
            }
        }
    }

    // Ativar/desativar afiliado
    if ($action === 'toggle') {
        $affId = (int)($_POST['aff_id'] ?? 0);
        $db->prepare('UPDATE affiliates SET active = 1 - active WHERE id=?')->execute([$affId]);
        header('Location: ' . SITE_URL . '/admin/afiliados.php?saved=1'); exit;
    }


}

if (isset($_GET['saved'])) $message = '✅ Alteração salva.';

// ── Dados ─────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'afiliados';

// Lista afiliados
$affiliates = $db->query("
    SELECT a.*, u.name AS user_name, u.phone AS user_phone,
           COUNT(DISTINCT r.id) AS total_clicks,
           COUNT(DISTINCT CASE WHEN r.status='registered' THEN r.id END) AS total_regs,
           COUNT(DISTINCT CASE WHEN r.status='converted'  THEN r.id END) AS total_conv,
           COALESCE(SUM(CASE WHEN r.status='converted' THEN r.commission_earned END), 0) AS total_earned,
           /* Saldo = comissões ganhas + créditos adicionados - créditos usados */
           GREATEST(0, ROUND(
               COALESCE(SUM(CASE WHEN r.status='converted' THEN r.commission_earned END), 0)
               + COALESCE((SELECT SUM(ca.amount) FROM affiliate_credits_added ca WHERE ca.affiliate_id=a.id), 0)
               - COALESCE((SELECT SUM(cu.amount) FROM affiliate_credits_used cu WHERE cu.affiliate_id=a.id), 0)
           , 2)) AS balance_cached
    FROM affiliates a
    JOIN users u ON u.id = a.user_id
    LEFT JOIN referrals r ON r.affiliate_id = a.id
    GROUP BY a.id
    ORDER BY total_earned DESC, a.created_at DESC
")->fetchAll();

// Últimas conversões
$conversions = $db->query("
    SELECT r.*, a.code, u_aff.name AS aff_name, u_ref.name AS ref_name, u_ref.phone AS ref_phone,
           p.name AS plan_name
    FROM referrals r
    JOIN affiliates a ON a.id = r.affiliate_id
    JOIN users u_aff ON u_aff.id = a.user_id
    LEFT JOIN users u_ref ON u_ref.id = r.referred_user_id
    LEFT JOIN transactions tx ON tx.id = r.transaction_id
    LEFT JOIN plans p ON p.id = tx.plan_id
    WHERE r.status IN ('registered','converted')
    ORDER BY r.created_at DESC
    LIMIT 100
")->fetchAll();

// Histórico de créditos usados
$creditsUsed = $db->query("
    SELECT cu.*, a.code, u.name AS aff_name, cu.note
    FROM affiliate_credits_used cu
    JOIN affiliates a ON a.id = cu.affiliate_id
    JOIN users u ON u.id = a.user_id
    ORDER BY cu.created_at DESC
    LIMIT 100
")->fetchAll() ?: [];

// Usuários sem afiliado para o select
$users = $db->query("
    SELECT u.id, u.name FROM users u
    WHERE u.role != 'admin'
    AND u.id NOT IN (SELECT user_id FROM affiliates)
    ORDER BY u.name
")->fetchAll();

// Stats gerais
$stats = $db->query("
    SELECT
        COUNT(DISTINCT a.id) AS total_affiliates,
        COUNT(DISTINCT r.id) AS total_referrals,
        COUNT(DISTINCT CASE WHEN r.status='converted' THEN r.id END) AS total_conversions,
        COALESCE(SUM(CASE WHEN r.status='converted' THEN r.commission_earned END), 0) AS total_commissions
    FROM affiliates a
    LEFT JOIN referrals r ON r.affiliate_id = a.id
")->fetch();

// Comissão configurável
$commType  = getSetting('affiliate_commission_type', 'percent');
$commValue = getSetting('affiliate_commission_value', '10');

require __DIR__ . '/../includes/header.php';
?>
<style>
.aff-tab{padding:10px 18px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;color:var(--muted);white-space:nowrap}
.aff-tab.active{color:var(--accent);border-color:var(--accent)}
.stat-mini{background:var(--surface2);border-radius:8px;padding:14px 18px;text-align:center}
.stat-mini-val{font-size:22px;font-weight:800;font-family:'Roboto',sans-serif}
.stat-mini-lbl{font-size:11px;color:var(--muted);margin-top:2px}
</style>

<?php if($message): ?><div class="alert alert-success" style="margin-bottom:14px"><?= $message ?></div><?php endif; ?>
<?php if($error): ?><div class="alert alert-danger" style="margin-bottom:14px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Stats ───────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
  <div class="stat-mini">
    <div class="stat-mini-val" style="color:var(--accent)"><?= $stats['total_affiliates'] ?></div>
    <div class="stat-mini-lbl">Afiliados ativos</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini-val" style="color:var(--accent2)"><?= $stats['total_referrals'] ?></div>
    <div class="stat-mini-lbl">Cadastros indicados</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini-val" style="color:var(--success)"><?= $stats['total_conversions'] ?></div>
    <div class="stat-mini-lbl">Conversões pagas</div>
  </div>
  <div class="stat-mini">
    <div class="stat-mini-val" style="color:var(--warning)">R$ <?= number_format($stats['total_commissions'],2,',','.') ?></div>
    <div class="stat-mini-lbl">Total em comissões</div>
  </div>
</div>

<!-- Tabs ─────────────────────────────────────────────────── -->
<div style="display:flex;border-bottom:1px solid var(--border);margin-bottom:18px">
  <a href="?tab=afiliados" class="aff-tab <?= $tab==='afiliados'?'active':'' ?>">👥 Afiliados</a>
  <a href="?tab=conversoes" class="aff-tab <?= $tab==='conversoes'?'active':'' ?>">📊 Indicações
    <?php $pendConv = count(array_filter($conversions, fn($r) => $r['status']==='registered')); ?>
    <?php if($pendConv): ?><span style="background:var(--accent);color:#fff;font-size:10px;padding:1px 5px;border-radius:10px;margin-left:4px"><?= $pendConv ?></span><?php endif; ?>
  </a>
  <a href="?tab=creditos" class="aff-tab <?= $tab==='creditos'?'active':'' ?>">💳 Créditos usados</a>
  <a href="?tab=config" class="aff-tab <?= $tab==='config'?'active':'' ?>">⚙️ Configuração</a>
</div>

<?php if ($tab === 'afiliados'): ?>
<!-- ── Criar afiliado ──────────────────────────────────── -->
<div class="card" style="padding:18px;margin-bottom:16px">
  <div style="font-weight:700;font-size:14px;margin-bottom:12px">➕ Adicionar afiliado</div>
  <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="create">
    <div>
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Usuário</label>
      <select name="user_id" class="form-control" style="width:220px" required>
        <option value="">Selecione...</option>
        <?php foreach($users as $u): ?>
        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Código (opcional)</label>
      <input type="text" name="code" class="form-control" style="width:140px;font-family:monospace;text-transform:uppercase"
             placeholder="Gerado automático" maxlength="20" pattern="[A-Za-z0-9]+">
    </div>
    <button type="submit" class="btn btn-primary" style="padding:9px 18px">Criar afiliado</button>
  </form>
</div>

<!-- ── Lista afiliados ────────────────────────────────── -->
<div class="card" style="overflow:hidden">
  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th class="th-cell">AFILIADO</th>
        <th class="th-cell">CÓDIGO / LINK</th>
        <th class="th-cell" style="text-align:right">CADASTROS</th>
        <th class="th-cell" style="text-align:right">CONVERSÕES</th>
        <th class="th-cell" style="text-align:right">COMISSÃO TOTAL</th>
        <th class="th-cell" style="text-align:right">SALDO</th>
        <th class="th-cell" style="text-align:center">STATUS</th>
        <th class="th-cell"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($affiliates as $a): $balance = (float)($a['balance_cached'] ?? 0); ?>
    <tr style="border-bottom:1px solid var(--border)" onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">
      <td style="padding:12px 16px">
        <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($a['user_name']) ?></div>
        <div style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($a['user_phone'] ?? '') ?></div>
      </td>
      <td style="padding:12px 16px">
        <div style="display:flex;align-items:center;gap:6px">
          <code style="font-size:13px;font-weight:700;color:var(--accent);background:rgba(124,106,255,.1);padding:2px 8px;border-radius:5px"><?= htmlspecialchars($a['code']) ?></code>
          <button onclick="navigator.clipboard.writeText('<?= SITE_URL ?>/?ref=<?= $a['code'] ?>').then(function(){this.textContent='✓';setTimeout(()=>this.textContent='📋',1500)}.bind(this))"
                  style="background:none;border:none;cursor:pointer;font-size:14px;padding:2px" title="Copiar link">📋</button>
        </div>
        <div style="font-size:10px;color:var(--muted);margin-top:2px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?= SITE_URL ?>/?ref=<?= htmlspecialchars($a['code']) ?>
        </div>
      </td>
      <td style="padding:12px 16px;text-align:right;font-size:13px"><?= $a['total_regs'] ?></td>
      <td style="padding:12px 16px;text-align:right">
        <span style="font-size:13px;font-weight:600;color:var(--success)"><?= $a['total_conv'] ?></span>
      </td>
      <td style="padding:12px 16px;text-align:right;font-size:13px">R$ <?= number_format($a['total_earned'],2,',','.') ?></td>
      <td style="padding:12px 16px;text-align:right">
        <span style="font-size:13px;font-weight:700;color:<?= $balance > 0 ? 'var(--warning)' : 'var(--muted)' ?>">
          R$ <?= number_format($balance,2,',','.') ?>
        </span>
      </td>
      <td style="padding:12px 16px;text-align:center">
        <span class="badge <?= $a['active'] ? 'badge-success' : 'badge-danger' ?>" style="font-size:11px">
          <?= $a['active'] ? 'Ativo' : 'Inativo' ?>
        </span>
      </td>
      <td style="padding:12px 16px;text-align:right">
        <form method="POST" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="aff_id" value="<?= $a['id'] ?>">
          <button type="submit" class="btn btn-secondary" style="padding:4px 10px;font-size:11px">
            <?= $a['active'] ? 'Desativar' : 'Ativar' ?>
          </button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(!$affiliates): ?>
    <tr><td colspan="8" style="padding:40px;text-align:center;color:var(--muted)">Nenhum afiliado cadastrado.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'conversoes'): ?>
<!-- ── Indicações e conversões ────────────────────────── -->
<div class="card" style="overflow:hidden">
  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th class="th-cell">DATA</th>
        <th class="th-cell">AFILIADO</th>
        <th class="th-cell">INDICADO</th>
        <th class="th-cell">PLANO</th>
        <th class="th-cell" style="text-align:right">VENDA</th>
        <th class="th-cell" style="text-align:right">COMISSÃO</th>
        <th class="th-cell" style="text-align:center">STATUS</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($conversions as $r): ?>
    <tr style="border-bottom:1px solid var(--border)" onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">
      <td style="padding:11px 16px;font-size:11px;color:var(--muted)"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
      <td style="padding:11px 16px">
        <div style="font-size:12px;font-weight:500"><?= htmlspecialchars($r['aff_name']) ?></div>
        <code style="font-size:10px;color:var(--accent)"><?= htmlspecialchars($r['code']) ?></code>
      </td>
      <td style="padding:11px 16px;font-size:12px">
        <?= htmlspecialchars($r['ref_name'] ?? '—') ?>
        <?php if($r['ref_phone']): ?><div style="font-size:10px;color:var(--muted)"><?= htmlspecialchars($r['ref_phone']) ?></div><?php endif; ?>
      </td>
      <td style="padding:11px 16px;font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['plan_name'] ?? '—') ?></td>
      <td style="padding:11px 16px;text-align:right;font-size:12px">
        <?= $r['sale_amount'] > 0 ? 'R$ '.number_format($r['sale_amount'],2,',','.') : '—' ?>
      </td>
      <td style="padding:11px 16px;text-align:right">
        <?php if($r['commission_earned'] > 0): ?>
        <span style="font-size:12px;font-weight:700;color:var(--success)">R$ <?= number_format($r['commission_earned'],2,',','.') ?></span>
        <div style="font-size:10px;color:var(--muted)">
          <?php if($r['commission_type']==='plan'): ?>🎁 Plano grátis<?php
          elseif($r['commission_type']==='percent'): ?><?= $r['commission_value'] ?>% da venda<?php
          else: ?>R$ <?= number_format($r['commission_value'],2,',','.') ?> fixo<?php endif; ?>
        </div>
        <?php else: ?>
        <span style="font-size:11px;color:var(--muted)">—</span>
        <?php endif; ?>
      </td>
      <td style="padding:11px 16px;text-align:center">
        <?php $badges = ['registered'=>['badge-warning','Cadastrado'],'converted'=>['badge-success','Convertido'],'click'=>['badge-secondary','Clique'],'paid'=>['badge-success','Pago']]; ?>
        <?php [$cls,$lbl] = $badges[$r['status']] ?? ['badge-secondary',$r['status']]; ?>
        <span class="badge <?= $cls ?>" style="font-size:10px"><?= $lbl ?></span>
        <?php if(isset($r['reward_plan_granted']) && $r['reward_plan_granted']): ?>
        <div style="font-size:10px;color:var(--success);margin-top:2px">🎁 Plano concedido</div>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if(!$conversions): ?>
    <tr><td colspan="7" style="padding:40px;text-align:center;color:var(--muted)">Nenhuma indicação ainda.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'creditos'): ?>
<!-- ── Créditos usados ─────────────────────────────────── -->
<div style="padding:10px 0 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
  <span style="font-size:13px;color:var(--muted2)">Histórico de créditos usados para comprar planos na plataforma.</span>
  <a href="<?= SITE_URL ?>/admin/users.php" class="btn btn-secondary" style="font-size:12px;padding:5px 12px">
    ➕ Adicionar crédito a um usuário
  </a>
</div>
<div class="card" style="overflow:hidden">
  <table style="width:100%;border-collapse:collapse">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th class="th-cell">DATA</th>
        <th class="th-cell">AFILIADO</th>
        <th class="th-cell" style="text-align:right">CRÉDITO USADO</th>
        <th class="th-cell">DESCRIÇÃO</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach($creditsUsed as $cu): ?>
    <tr style="border-bottom:1px solid var(--border)" onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">
      <td style="padding:11px 16px;font-size:11px;color:var(--muted)"><?= date('d/m/Y H:i', strtotime($cu['created_at'])) ?></td>
      <td style="padding:11px 16px">
        <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($cu['aff_name']) ?></div>
        <code style="font-size:10px;color:var(--accent)"><?= htmlspecialchars($cu['code']) ?></code>
      </td>
      <td style="padding:11px 16px;text-align:right;font-size:13px;font-weight:700;color:var(--accent)">
        − R$ <?= number_format($cu['amount'],2,',','.') ?>
      </td>
      <td style="padding:11px 16px;font-size:12px;color:var(--muted)"><?= htmlspecialchars($cu['note'] ?? 'Compra de plano') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php if(!$creditsUsed): ?>
    <tr><td colspan="4" style="padding:40px;text-align:center;color:var(--muted)">Nenhum crédito utilizado ainda.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($tab === 'config'): ?>
<!-- ── Configuração ────────────────────────────────────── -->
<div class="card" style="padding:24px;max-width:560px;text-align:center">
  <div style="font-size:32px;margin-bottom:12px">⚙️</div>
  <div style="font-weight:700;font-size:15px;margin-bottom:8px">Configurações do programa</div>
  <p style="font-size:13px;color:var(--muted);margin-bottom:18px">
    As configurações do programa de afiliados ficam nas Configurações gerais do sistema.
  </p>
  <a href="<?= SITE_URL ?>/admin/settings.php#afiliados" class="btn btn-primary" style="padding:9px 20px">
    Ir para Configurações → Afiliados
  </a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>

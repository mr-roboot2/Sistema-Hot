<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/affiliate.php';
requireLogin();
session_write_close(); // libera lock de sessão imediatamente

$db        = getDB();
$user      = currentUser();
$userId    = (int)$user['id'];
$pageTitle = 'Minha Indicação';

affiliateEnsureTables();

$message = $error = '';

// ── Solicitar participação no programa ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '')) {
    if (!getSetting('affiliate_enabled', '0') === '1') {
        $error = 'Programa de indicações não está disponível no momento.';
    } else {
        $exists = $db->prepare('SELECT id FROM affiliates WHERE user_id=?');
        $exists->execute([$userId]);
        if (!$exists->fetch()) {
            $code = affiliateGenerateCode($user['name']);
            $db->prepare('INSERT INTO affiliates (user_id, code, active) VALUES (?,?,1)')
               ->execute([$userId, $code]);
            $message = '✅ Seu link de indicação foi gerado com sucesso!';
        }
    }
}

// ── Dados do afiliado ─────────────────────────────────────────
$aff = $db->prepare('SELECT * FROM affiliates WHERE user_id=?');
$aff->execute([$userId]);
$aff = $aff->fetch();

$affEnabled = getSetting('affiliate_enabled', '0') === '1';
$commType   = getSetting('affiliate_commission_type', 'percent');
$commValue  = (float)getSetting('affiliate_commission_value', '10');

if ($aff) {
    $affId   = (int)$aff['id'];
    $balance = affiliateBalance($affId);

    // Stats
    $stats = $db->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(status='registered') AS registered,
            SUM(status='converted')  AS converted,
            COALESCE(SUM(CASE WHEN status='converted' THEN commission_earned END), 0) AS earned
        FROM referrals WHERE affiliate_id=?
    ");
    $stats->execute([$affId]);
    $stats = $stats->fetch();

    // Últimas indicações
    $refs = $db->prepare("
        SELECT r.status, r.commission_earned, r.sale_amount, r.created_at, r.converted_at,
               u.name AS ref_name
        FROM referrals r
        LEFT JOIN users u ON u.id = r.referred_user_id
        WHERE r.affiliate_id=? AND r.status != 'click'
        ORDER BY r.created_at DESC LIMIT 20
    ");
    $refs->execute([$affId]);
    $refs = $refs->fetchAll();

    // Histórico de créditos usados
    $creditsUsed = $db->prepare("
        SELECT amount, note, created_at, 'used' AS type FROM affiliate_credits_used
        WHERE affiliate_id=? ORDER BY created_at DESC LIMIT 10
    ");
    $creditsUsed->execute([$affId]);
    $creditsUsed = $creditsUsed->fetchAll();

    // Créditos recebidos por comissão de indicação
    $creditsEarned = $db->prepare("
        SELECT commission_earned AS amount,
               CONCAT('Comissão — indicação de ', COALESCE(u.name,'usuário')) AS note,
               r.converted_at AS created_at, 'earned' AS type
        FROM referrals r
        LEFT JOIN users u ON u.id = r.referred_user_id
        WHERE r.affiliate_id=? AND r.status='converted' AND r.commission_earned > 0
        ORDER BY r.converted_at DESC LIMIT 20
    ");
    $creditsEarned->execute([$affId]);
    $creditsEarned = $creditsEarned->fetchAll();

    // Créditos adicionados/removidos pelo admin
    $creditsAdmin = [];
    try {
        $ca = $db->prepare("
            SELECT amount, note, created_at,
                   IF(amount >= 0, 'admin_add', 'admin_remove') AS type
            FROM affiliate_credits_added
            WHERE affiliate_id=? ORDER BY created_at DESC LIMIT 20
        ");
        $ca->execute([$affId]);
        $creditsAdmin = $ca->fetchAll();
    } catch(Exception $e) {}

    // Unifica e ordena por data desc
    $allMovements = array_merge($creditsEarned, $creditsAdmin, $creditsUsed);
    usort($allMovements, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    $allMovements = array_slice($allMovements, 0, 30);
}

$refLink = SITE_URL . '/?ref=' . ($aff['code'] ?? '');

require __DIR__ . '/includes/header.php';
?>
<style>
.ref-stat{background:var(--surface2);border-radius:10px;padding:16px;text-align:center}
.ref-stat-val{font-size:26px;font-weight:800;font-family:'Roboto',sans-serif}
.ref-stat-lbl{font-size:11px;color:var(--muted);margin-top:3px}
.ref-row{display:flex;align-items:center;gap:10px;padding:11px 16px;border-bottom:.5px solid var(--border)}
.ref-row:last-child{border-bottom:none}
</style>

<div style="max-width:680px;margin:0 auto">

<?php if (!$affEnabled): ?>
<!-- Programa desativado -->
<div class="card" style="padding:40px;text-align:center">
  <div style="font-size:40px;margin-bottom:14px">🔒</div>
  <div style="font-size:17px;font-weight:700;margin-bottom:8px">Programa de indicações indisponível</div>
  <div style="font-size:13px;color:var(--muted)">O programa de indicações não está disponível no momento.</div>
</div>

<?php elseif (!$aff): ?>
<!-- Não é afiliado ainda — convidar para participar -->
<div class="card" style="padding:32px;text-align:center;margin-bottom:16px">
  <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
  </div>
  <div style="font-family:'Roboto',sans-serif;font-size:20px;font-weight:800;margin-bottom:8px">Programa de Indicações</div>
  <div style="font-size:14px;color:var(--muted2);max-width:420px;margin:0 auto 20px;line-height:1.7">
    Indique amigos e ganhe créditos para usar na plataforma.
    <?php if ($commType === 'plan'): ?>
    Quando seu indicado assinar, você recebe <b>acesso grátis</b> ao mesmo plano!
    <?php elseif ($commType === 'percent'): ?>
    Você ganha <b><?= $commValue ?>%</b> do valor de cada assinatura paga pelo seu indicado.
    <?php else: ?>
    Você ganha <b>R$ <?= number_format($commValue,2,',','.') ?></b> por cada indicado que assinar.
    <?php endif; ?>
    Os créditos ficam na sua carteira e podem ser usados para renovar seu plano.
  </div>

  <?php if($message): ?>
  <div class="alert alert-success" style="margin-bottom:16px;text-align:left"><?= $message ?></div>
  <?php endif; ?>
  <?php if($error): ?>
  <div class="alert alert-danger" style="margin-bottom:16px;text-align:left"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <button type="submit" class="btn btn-primary" style="padding:12px 28px;font-size:14px">
      🎯 Quero meu link de indicação
    </button>
  </form>
</div>

<!-- Como funciona -->
<div class="card" style="padding:24px">
  <div style="font-weight:700;font-size:14px;margin-bottom:16px">Como funciona</div>
  <div style="display:flex;flex-direction:column;gap:14px">
    <?php foreach([
      ['🔗','Receba seu link','Gere seu link único de indicação com um clique.'],
      ['📣','Compartilhe','Envie para amigos pelo WhatsApp, Instagram ou onde quiser.'],
      ['✅','Indicado se cadastra','Quando seu amigo se cadastrar pelo seu link, o sistema registra automaticamente.'],
      ['💳','Ganhe créditos','Assim que ele pagar o plano, os créditos entram na sua carteira.'],
      ['🛒','Use na plataforma','Use seus créditos como desconto na próxima renovação.'],
    ] as [$icon,$title,$desc]): ?>
    <div style="display:flex;gap:12px;align-items:flex-start">
      <div style="width:36px;height:36px;border-radius:50%;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0"><?= $icon ?></div>
      <div>
        <div style="font-size:13px;font-weight:600"><?= $title ?></div>
        <div style="font-size:12px;color:var(--muted2)"><?= $desc ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php else: ?>
<!-- Já é afiliado — mostra painel completo -->

<?php if($message): ?><div class="alert alert-success" style="margin-bottom:14px"><?= $message ?></div><?php endif; ?>
<?php if(!$aff['active']): ?>
<div class="alert alert-danger" style="margin-bottom:14px">⚠️ Seu programa de indicações está temporariamente suspenso. Entre em contato com o suporte.</div>
<?php endif; ?>

<!-- Link de indicação -->
<div class="card" style="padding:20px;margin-bottom:14px">
  <div style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:10px">Seu link de indicação</div>
  <div style="display:flex;gap:8px;align-items:stretch">
    <input type="text" id="ref-link-input" value="<?= htmlspecialchars($refLink) ?>" readonly
           class="form-control" style="flex:1;font-size:13px;font-family:monospace;background:var(--surface2)">
    <button onclick="copyLink()" class="btn btn-primary" style="padding:9px 18px;white-space:nowrap" id="copy-btn">
      📋 Copiar
    </button>
  </div>
  <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
    <a href="https://wa.me/?text=<?= urlencode('Olá! Me cadastrei em '.getSetting('site_name',SITE_NAME).' e estou adorando. Acesse pelo meu link: '.$refLink) ?>"
       target="_blank" rel="noopener"
       class="btn btn-secondary" style="font-size:12px;padding:6px 12px;color:#25D366;border-color:rgba(37,211,102,.3)">
      💬 Compartilhar no WhatsApp
    </a>
    <button onclick="copyLink()" class="btn btn-secondary" style="font-size:12px;padding:6px 12px">
      🔗 Copiar link
    </button>
  </div>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
  <div class="ref-stat">
    <div class="ref-stat-val" style="color:var(--accent)"><?= (int)$stats['total'] ?></div>
    <div class="ref-stat-lbl">Cadastros</div>
  </div>
  <div class="ref-stat">
    <div class="ref-stat-val" style="color:var(--success)"><?= (int)$stats['converted'] ?></div>
    <div class="ref-stat-lbl">Convertidos</div>
  </div>
  <div class="ref-stat">
    <?php if($commType === 'plan'): ?>
    <div class="ref-stat-val" style="color:var(--warning)"><?= (int)$stats['converted'] ?></div>
    <div class="ref-stat-lbl">Planos ganhos</div>
    <?php else: ?>
    <div class="ref-stat-val" style="color:var(--warning)" style="font-size:18px">R$ <?= number_format($stats['earned'],2,',','.') ?></div>
    <div class="ref-stat-lbl">Total ganho</div>
    <?php endif; ?>
  </div>
  <div class="ref-stat" style="border:1px solid rgba(124,106,255,.3)">
    <div class="ref-stat-val" style="color:var(--accent)">R$ <?= number_format($balance,2,',','.') ?></div>
    <div class="ref-stat-lbl">Saldo disponível</div>
  </div>
</div>

<?php if($balance > 0): ?>
<div style="padding:10px 14px;background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:8px;font-size:13px;margin-bottom:14px">
  🎉 Você tem <b>R$ <?= number_format($balance,2,',','.') ?></b> de crédito disponível!
  Use como desconto na sua próxima renovação em
  <a href="<?= SITE_URL ?>/renovar" style="color:var(--success);font-weight:600">Renovar plano</a>.
</div>
<?php endif; ?>

<!-- Como funciona a recompensa -->
<div class="card" style="padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:12px">
  <div style="width:38px;height:38px;border-radius:50%;background:rgba(124,106,255,.12);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">
    <?= $commType==='plan' ? '🎁' : ($commType==='percent' ? '📊' : '💵') ?>
  </div>
  <div>
    <div style="font-size:13px;font-weight:600">Sua recompensa por indicação</div>
    <div style="font-size:12px;color:var(--muted2)">
      <?php if($commType === 'plan'): ?>
      Você recebe o mesmo plano que seu indicado comprar (dias somados ao seu acesso atual)
      <?php elseif($commType === 'percent'): ?>
      <?= $commValue ?>% do valor de cada assinatura paga pelo seu indicado, em créditos
      <?php else: ?>
      R$ <?= number_format($commValue,2,',','.') ?> em créditos por cada indicado que assinar
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Histórico de indicações -->
<?php if($refs): ?>
<div class="card" style="overflow:hidden;margin-bottom:14px">
  <div style="padding:14px 18px;border-bottom:.5px solid var(--border);font-size:13px;font-weight:700">
    Minhas indicações
  </div>
  <?php foreach($refs as $r): ?>
  <div class="ref-row">
    <div style="width:34px;height:34px;border-radius:50%;background:var(--surface2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:var(--accent);flex-shrink:0">
      <?= strtoupper(mb_substr($r['ref_name'] ?? '?', 0, 1)) ?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($r['ref_name'] ?? 'Usuário') ?></div>
      <div style="font-size:11px;color:var(--muted)"><?= timeAgo($r['created_at']) ?></div>
    </div>
    <div style="text-align:right">
      <?php if($r['status'] === 'converted'): ?>
        <?php if($commType === 'plan'): ?>
        <span class="badge badge-success" style="font-size:11px">🎁 Plano ganho</span>
        <?php else: ?>
        <div style="font-size:13px;font-weight:700;color:var(--success)">+R$ <?= number_format($r['commission_earned'],2,',','.') ?></div>
        <span class="badge badge-success" style="font-size:10px">Convertido</span>
        <?php endif; ?>
      <?php elseif($r['status'] === 'registered'): ?>
        <span class="badge badge-warning" style="font-size:11px">⏳ Aguardando pagamento</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Extrato de créditos -->
<?php if($allMovements): ?>
<div class="card" style="overflow:hidden">
  <div style="padding:14px 18px;border-bottom:.5px solid var(--border);display:flex;align-items:center;justify-content:space-between">
    <div style="font-size:13px;font-weight:700">💳 Extrato da carteira</div>
    <div style="font-size:12px;color:var(--muted)">Saldo: <b style="color:var(--accent)">R$ <?= number_format($balance,2,',','.') ?></b></div>
  </div>
  <?php foreach($allMovements as $mov):
    $isCredit = in_array($mov['type'], ['earned','admin_add']);
    $icon  = match($mov['type']) {
      'earned'       => '🎯',
      'admin_add'    => '🎁',
      'admin_remove' => '↩️',
      'used'         => '🛒',
      default        => '•'
    };
    $label = match($mov['type']) {
      'earned'       => 'Comissão',
      'admin_add'    => 'Crédito admin',
      'admin_remove' => 'Ajuste admin',
      'used'         => 'Usado',
      default        => ''
    };
  ?>
  <div class="ref-row">
    <div style="width:32px;height:32px;border-radius:50%;background:<?= $isCredit ? 'rgba(16,185,129,.12)' : 'rgba(255,77,106,.1)' ?>;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0">
      <?= $icon ?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= htmlspecialchars($mov['note'] ?? $label) ?>
      </div>
      <div style="font-size:11px;color:var(--muted);margin-top:1px">
        <span style="background:var(--surface2);border-radius:4px;padding:1px 6px;font-size:10px;margin-right:4px"><?= $label ?></span>
        <?= $mov['created_at'] ? date('d/m/Y H:i', strtotime($mov['created_at'])) : '' ?>
      </div>
    </div>
    <div style="font-size:13px;font-weight:700;color:<?= $isCredit ? 'var(--success)' : 'var(--danger)' ?>;white-space:nowrap">
      <?= $isCredit ? '+' : '−' ?>R$ <?= number_format(abs((float)$mov['amount']),2,',','.') ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; // aff exists ?>
</div>

<script>
function copyLink() {
  var inp = document.getElementById('ref-link-input');
  var btn = document.getElementById('copy-btn');
  if (!inp) return;
  navigator.clipboard.writeText(inp.value).then(function() {
    btn.textContent = '✅ Copiado!';
    inp.select();
    setTimeout(function(){ btn.textContent = '📋 Copiar'; }, 2000);
  }).catch(function() {
    inp.select();
    document.execCommand('copy');
    btn.textContent = '✅ Copiado!';
    setTimeout(function(){ btn.textContent = '📋 Copiar'; }, 2000);
  });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

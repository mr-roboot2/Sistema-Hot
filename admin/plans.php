<?php
require_once __DIR__ . '/../includes/auth.php';
requireLoginAlways();
if ((currentUser()['role'] ?? '') !== 'admin') { header('Location: ' . SITE_URL . '/index'); exit; }

$db        = getDB();
$pageTitle = 'Planos de Acesso';
$message   = '';
$error     = '';

// ── Garante tabela plans ──────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL,
        duration_days INT NOT NULL DEFAULT 30,
        color VARCHAR(7) DEFAULT '#7c6aff',
        active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $ec = [];
    foreach ($db->query("SHOW COLUMNS FROM plans") as $c) $ec[] = $c['Field'];
    if (!in_array('description',  $ec)) $db->exec("ALTER TABLE plans ADD COLUMN description TEXT DEFAULT NULL AFTER name");
    if (!in_array('color',        $ec)) $db->exec("ALTER TABLE plans ADD COLUMN color VARCHAR(7) DEFAULT '#7c6aff'");
    if (!in_array('active',       $ec)) $db->exec("ALTER TABLE plans ADD COLUMN active TINYINT(1) DEFAULT 1");
    if (!in_array('price',        $ec)) $db->exec("ALTER TABLE plans ADD COLUMN price DECIMAL(10,2) DEFAULT 0.00 AFTER duration_days");
    if (!in_array('checkout_url', $ec)) $db->exec("ALTER TABLE plans ADD COLUMN checkout_url VARCHAR(500) DEFAULT NULL");
    if (!in_array('sort_order',   $ec)) $db->exec("ALTER TABLE plans ADD COLUMN sort_order INT DEFAULT 0");
    if (!in_array('visible',      $ec)) $db->exec("ALTER TABLE plans ADD COLUMN visible TINYINT(1) DEFAULT 1");
    $ins = $db->prepare("INSERT IGNORE INTO plans (id,name,description,duration_days,color) VALUES (?,?,?,?,?)");
    // Só insere defaults se a tabela estiver vazia
    if ((int)$db->query('SELECT COUNT(*) FROM plans')->fetchColumn() === 0) {
        $ins->execute([1,'Mensal',  '', 30,  '#7c6aff']);
        $ins->execute([2,'Semanal', '',  7,  '#10b981']);
        $ins->execute([3,'Diário',  '',  1,  '#f59e0b']);
        $ins->execute([4,'Anual',   '', 365, '#ff6a9e']);
    }
} catch(Exception $e) {}

// ── Garante colunas em users ──────────────────
foreach (['plan_id INT DEFAULT NULL','expires_at DATETIME DEFAULT NULL','expired_notified TINYINT(1) DEFAULT 0'] as $col) {
    try { $db->exec("ALTER TABLE users ADD COLUMN $col"); } catch(Exception $e) {}
}

// ── Ações ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    // Salvar/criar plano
    if ($action === 'save_plan') {
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $days  = max(1, (int)($_POST['duration_days'] ?? 30));
        $price = max(0, (float)str_replace(',','.',($_POST['price'] ?? '0')));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#7c6aff';
        $pid   = (int)($_POST['plan_id'] ?? 0);
        if ($name) {
            if ($pid) {
                $db->prepare('UPDATE plans SET name=?,description=?,duration_days=?,price=?,color=? WHERE id=?')
                   ->execute([$name, $desc, $days, $price, $color, $pid]);
                auditLog('edit_plan', 'plan', $_POST['name'] ?? '');
                $message = 'Plano atualizado!';
            } else {
                $db->prepare('INSERT INTO plans (name,description,duration_days,price,color) VALUES (?,?,?,?,?)')
                   ->execute([$name, $desc, $days, $price, $color]);
                auditLog('create_plan', 'plan', $_POST['name'] ?? '');
                $message = 'Plano criado!';
            }
        }
    }

    // Ativar/desativar visibilidade no bem-vindo
    if ($action === 'toggle_visible') {
        $pid = (int)($_POST['plan_id'] ?? 0);
        $db->prepare('UPDATE plans SET visible = 1 - visible WHERE id=?')->execute([$pid]);
        header('Location: ' . SITE_URL . '/admin/plans.php?saved=1'); exit;
    }

    // Reordenar planos (ajax)
    if ($action === 'reorder') {
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($order)) {
            foreach ($order as $pos => $pid) {
                $db->prepare('UPDATE plans SET sort_order=? WHERE id=?')->execute([$pos, (int)$pid]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]); exit;
    }

    // Deletar plano
    if ($action === 'delete_plan') {
        $pid = (int)($_POST['plan_id'] ?? 0);
        $db->prepare('UPDATE users SET plan_id=NULL WHERE plan_id=?')->execute([$pid]);
        $db->prepare('DELETE FROM plans WHERE id=?')->execute([$pid]);
        auditLog('delete_plan', 'plan', (string)$planId);
        $message = 'Plano removido.';
    }

    // Atribuir plano a usuário
    if ($action === 'assign_plan') {
        $uid   = (int)($_POST['uid'] ?? 0);
        $pid   = (int)($_POST['pid'] ?? 0) ?: null;
        $days  = (int)($_POST['custom_days'] ?? 0);

        if ($uid) {
            if ($pid) {
                // Busca duração do plano
                $p = $db->prepare('SELECT duration_days FROM plans WHERE id=?');
                $p->execute([$pid]);
                $plan = $p->fetch();
                $days = $plan ? (int)$plan['duration_days'] : $days;
            }
            if ($days > 0) {
                $expires = date('Y-m-d H:i:s', time() + $days * 86400);
                $db->prepare('UPDATE users SET plan_id=?, expires_at=?, expired_notified=0 WHERE id=?')
                   ->execute([$pid, $expires, $uid]);
                $message = "Plano atribuído! Acesso até " . date('d/m/Y H:i', strtotime($expires));
            } else {
                // Remove expiração
                $db->prepare('UPDATE users SET plan_id=NULL, expires_at=NULL, expired_notified=0 WHERE id=?')
                   ->execute([$uid]);
                $message = 'Expiração removida — acesso ilimitado.';
            }
        }
    }

    // Renovar (estende a partir de agora)
    if ($action === 'renew') {
        $uid  = (int)($_POST['uid'] ?? 0);
        $days = (int)($_POST['days'] ?? 0);
        if ($uid && $days > 0) {
            $u = $db->prepare('SELECT plan_id, expires_at FROM users WHERE id=?');
            $u->execute([$uid]); $u = $u->fetch();
            // Se ainda não expirou, estende a partir da data atual de expiração
            $base = (!empty($u['expires_at']) && strtotime($u['expires_at']) > time())
                ? strtotime($u['expires_at'])
                : time();
            $expires = date('Y-m-d H:i:s', $base + $days * 86400);
            $db->prepare('UPDATE users SET expires_at=?, expired_notified=0 WHERE id=?')
               ->execute([$expires, $uid]);
            $message = "Renovado! Acesso até " . date('d/m/Y H:i', strtotime($expires));
        }
    }

    // Revogar — força expiração imediata
    if ($action === 'revoke') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid) {
            $expired = date('Y-m-d H:i:s', time() - 1);
            $db->prepare('UPDATE users SET expires_at=?, expired_notified=0 WHERE id=?')
               ->execute([$expired, $uid]);
            $message = '🚫 Acesso revogado. Usuário bloqueado no próximo acesso.';
        }
    }
}

// Dados
$plans = $db->query('SELECT *, (SELECT COUNT(*) FROM users WHERE plan_id=plans.id) AS user_count FROM plans ORDER BY sort_order ASC, id ASC')->fetchAll();
$users = $db->query('
    SELECT u.id, u.name, u.email, u.phone, u.role, u.plan_id,
           u.expires_at, u.expired_notified,
           p.name AS plan_name, p.color AS plan_color, p.duration_days,
           (SELECT COUNT(*) FROM transactions t
            WHERE t.user_id=u.id AND t.status="pending"
              AND (t.pix_expires_at IS NULL OR t.pix_expires_at > NOW())) AS pending_pix
    FROM users u
    LEFT JOIN plans p ON u.plan_id = p.id
    WHERE u.role != "admin"
    ORDER BY u.name
')->fetchAll();

$editPlan = null;
if (isset($_GET['edit_plan'])) {
    $ep = $db->prepare('SELECT * FROM plans WHERE id=?');
    $ep->execute([(int)$_GET['edit_plan']]);
    $editPlan = $ep->fetch();
}

require __DIR__ . '/../includes/header.php';

function expiryBadge(array $u): string {
    // Sem plano atribuído
    if (empty($u['plan_id']) && empty($u['expires_at'])) {
        return '<span style="font-size:11px;background:rgba(107,107,128,.12);color:var(--muted);padding:2px 9px;border-radius:20px">Sem plano</span>';
    }
    if (empty($u['expires_at'])) {
        return '<span class="txt-muted-xs">Sem expiração</span>';
    }
    $ts   = strtotime($u['expires_at']);
    $now  = time();
    $days = ceil(($ts - $now) / 86400);
    if ($ts < $now) {
        return '<span style="background:rgba(255,77,106,.15);color:var(--danger);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700">⛔ Expirado</span>';
    }
    if ($days <= 2) {
        return '<span style="background:rgba(245,158,11,.15);color:var(--warning);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700">⚠️ '.$days.'d</span>';
    }
    return '<span style="background:rgba(16,185,129,.12);color:var(--success);border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700">✓ '.$days.'d</span>';
}
?>
<style>
.plan-card{background:var(--surface);border:2px solid var(--border);border-radius:14px;padding:20px;transition:border-color .2s;cursor:pointer}
.plan-card:hover{border-color:var(--border2)}
.plan-card.selected{border-color:var(--accent)}
.plans-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:24px}
.user-row{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border);transition:background .15s}
.user-row:last-child{border:none}
.user-row:hover{background:var(--surface2)}
.avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;flex-shrink:0}
</style>

<?php if ($message): ?>
<div class="alert alert-success" style="margin-bottom:20px">✅ <?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start">

<!-- COLUNA ESQUERDA: usuários -->
<div>
  <div class="section-header" style="margin-bottom:16px">
    <div>
      <div class="section-title">👥 Usuários e Planos</div>
      <div class="section-sub"><?= count($users) ?> usuário<?= count($users)!=1?'s':'' ?> (excluindo admins)</div>
    </div>
  </div>

  <div class="card" style="overflow:hidden">
    <?php foreach ($users as $u):
      $bg = $u['plan_color'] ? $u['plan_color'].'22' : 'var(--surface2)';
    ?>
    <div class="user-row">
      <div class="avatar" style="background:<?= htmlspecialchars($u['plan_color'] ?? 'var(--accent)') ?>">
        <?= strtoupper(substr($u['name'],0,1)) ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          <?= htmlspecialchars($u['name']) ?>
        </div>
        <div class="txt-muted-xs">
          <?php if (!empty($u['phone'])): ?>
          📱 <?= htmlspecialchars($u['phone']) ?>
          <?php elseif (!strpos($u['email'], 'user_') === 0 && !(substr($u['email'], -strlen('@local.cms')) === '@local.cms')): ?>
          <?= htmlspecialchars($u['email']) ?>
          <?php endif; ?></div>
        <div style="margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
          <?php if ($u['plan_name']): ?>
          <span style="background:<?= $bg ?>;color:<?= htmlspecialchars($u['plan_color']) ?>;border-radius:20px;padding:1px 8px;font-size:11px;font-weight:700"><?= htmlspecialchars($u['plan_name']) ?></span>
          <?php endif; ?>
          <?= expiryBadge($u) ?>
          <?php if (!empty($u['expires_at'])): ?>
          <span class="txt-muted-xs"><?= date('d/m/Y H:i', strtotime($u['expires_at'])) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <!-- Ações rápidas -->
      <div style="display:flex;gap:6px;flex-shrink:0">
        <!-- Renovar -->
        <form method="POST" style="display:inline" onsubmit="return confirm('Renovar acesso?')">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="renew">
          <input type="hidden" name="uid" value="<?= $u['id'] ?>">
          <input type="hidden" name="days" id="renew-days-<?= $u['id'] ?>" value="<?= $u['duration_days'] ?? 30 ?>">
          <button type="submit" class="btn btn-secondary" style="padding:5px 10px;font-size:11px" title="Renovar pelo mesmo período">
            🔄 Renovar
          </button>
        </form>
        <!-- Atribuir plano -->
        <button type="button" class="btn btn-secondary" style="padding:5px 10px;font-size:11px"
                onclick="openAssign(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>')">
          ✏️ Editar
        </button>
        <!-- Revogar -->
        <?php $jaExpirado = !empty($u['expires_at']) && strtotime($u['expires_at']) < time(); ?>
        <?php if (!$jaExpirado && !empty($u['expires_at'])): ?>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Revogar acesso de <?= htmlspecialchars(addslashes($u['name'])) ?>? O usuário será bloqueado imediatamente.')">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="uid" value="<?= $u['id'] ?>">
          <button type="submit" class="btn btn-danger" style="padding:5px 10px;font-size:11px" title="Revogar acesso agora">
            🚫 Revogar
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (!$users): ?>
    <div style="padding:32px;text-align:center;color:var(--muted)">Nenhum usuário cadastrado além dos admins.</div>
    <?php endif; ?>
  </div>
</div>

<!-- COLUNA DIREITA: planos + form -->
<div>

  <!-- Planos existentes -->
  <div class="section-title" style="margin-bottom:14px;font-family:'Roboto',sans-serif;font-size:15px;font-weight:700">📦 Planos Disponíveis</div>
  <div class="plans-grid">
    <?php foreach ($plans as $pl): ?>
    <div class="plan-card" id="plan-<?= $pl['id'] ?>"
         style="border-color:<?= htmlspecialchars($pl['color']) ?>33;<?= empty($pl['visible']) ? 'opacity:.5' : '' ?>;cursor:grab;position:relative"
         draggable="true"
         ondragstart="dragStart(event,<?= $pl['id'] ?>)"
         ondragover="dragOver(event)"
         ondrop="dragDrop(event,<?= $pl['id'] ?>)"
         ondragend="dragEnd(event)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
        <div style="display:flex;align-items:center;gap:6px">
          <!-- Handle de arrastar -->
          <span style="color:var(--muted);font-size:14px;cursor:grab;line-height:1" title="Arraste para reordenar">⠿</span>
          <div style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($pl['color']) ?>"></div>
          <span style="font-size:10px;font-weight:700;color:var(--muted);background:var(--surface2);border:1px solid var(--border);border-radius:5px;padding:1px 6px;font-family:monospace">
            #<?= $pl['id'] ?>
          </span>
        </div>
        <div style="display:flex;gap:4px;align-items:center">
          <!-- Toggle visibilidade -->
          <form method="POST" style="display:inline">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="toggle_visible">
            <input type="hidden" name="plan_id" value="<?= $pl['id'] ?>">
            <button type="submit" title="<?= empty($pl['visible']) ? 'Exibir no bem-vindo' : 'Ocultar do bem-vindo' ?>"
                    style="background:none;border:none;cursor:pointer;font-size:14px;padding:2px 3px;opacity:<?= empty($pl['visible']) ? '.4' : '1' ?>">
              <?= empty($pl['visible']) ? '🙈' : '👁️' ?>
            </button>
          </form>
          <button onclick="copyPlanLink(<?= $pl['id'] ?>, this)" title="Copiar link de assinatura"
                  style="background:none;border:none;cursor:pointer;font-size:11px;color:var(--muted);padding:2px 4px">🔗</button>
          <a href="?edit_plan=<?= $pl['id'] ?>" class="txt-muted-xs">✏️</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Excluir plano?')">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete_plan">
            <input type="hidden" name="plan_id" value="<?= $pl['id'] ?>">
            <button type="submit" style="background:none;border:none;cursor:pointer;font-size:11px;color:var(--muted)">🗑️</button>
          </form>
        </div>
      </div>
      <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;color:<?= htmlspecialchars($pl['color']) ?>">
        <?= htmlspecialchars($pl['name']) ?>
        <?php if (empty($pl['visible'])): ?>
        <span style="font-size:10px;font-weight:500;color:var(--muted);background:var(--surface2);border-radius:4px;padding:1px 5px;margin-left:4px">oculto</span>
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:3px"><?= $pl['duration_days'] ?> dia<?= $pl['duration_days']>1?'s':'' ?></div>
      <?php if (!empty($pl['price']) && $pl['price'] > 0): ?>
      <div style="font-family:'Roboto',sans-serif;font-size:16px;font-weight:800;color:var(--success);margin-top:6px">R$ <?= number_format((float)$pl['price'],2,',','.') ?></div>
      <?php else: ?>
      <div class="txt-muted-mt">Manual / sem preço</div>
      <?php endif; ?>
      <div class="txt-muted-mt"><?= $pl['user_count'] ?> usuário<?= $pl['user_count']!=1?'s':'' ?></div>
      <!-- Link de assinatura — clique para copiar -->
      <div onclick="copyPlanLink(<?= $pl['id'] ?>, this)"
           title="Clique para copiar"
           style="margin-top:10px;padding:6px 8px;background:var(--surface2);border-radius:6px;font-size:10px;color:var(--muted);font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;transition:background .15s"
           onmouseover="this.style.background='var(--surface3)';this.style.color='var(--accent)'"
           onmouseout="this.style.background='var(--surface2)';this.style.color='var(--muted)'">
        <?= SITE_URL ?>/assinar/<?= $pl['id'] ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Form criar/editar plano -->
  <div class="card" style="padding:20px;margin-bottom:16px">
    <div style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:700;margin-bottom:16px">
      <?= $editPlan ? '✏️ Editar: '.htmlspecialchars($editPlan['name']) : '➕ Novo Plano' ?>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save_plan">
      <input type="hidden" name="plan_id" value="<?= $editPlan['id'] ?? 0 ?>">
      <div class="form-group">
        <label class="form-label">Nome do plano</label>
        <input type="text" name="name" class="form-control" required placeholder="Ex: Mensal"
               value="<?= htmlspecialchars($editPlan['name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Duração (dias)</label>
        <input type="number" name="duration_days" class="form-control" min="1" max="3650"
               value="<?= $editPlan['duration_days'] ?? 30 ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Preço (R$) <span style="color:var(--muted);font-weight:400">— 0 = manual/gratuito</span></label>
        <div style="position:relative">
          <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px">R$</span>
          <input type="text" name="price" class="form-control" style="padding-left:32px"
                 placeholder="0,00" value="<?= $editPlan ? number_format((float)($editPlan['price']??0),2,',','.') : '0,00' ?>">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Cor</label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" name="color" value="<?= htmlspecialchars($editPlan['color'] ?? '#7c6aff') ?>"
                 style="width:44px;height:36px;border-radius:7px;border:1px solid var(--border);background:none;cursor:pointer;padding:2px">
          <input type="text" id="color-hex" class="form-control" style="flex:1"
                 value="<?= htmlspecialchars($editPlan['color'] ?? '#7c6aff') ?>" placeholder="#7c6aff">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Descrição <span style="color:var(--muted)">(opcional)</span></label>
        <input type="text" name="description" class="form-control" placeholder="Breve descrição"
               value="<?= htmlspecialchars($editPlan['description'] ?? '') ?>">
      </div>
      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">
          <?= $editPlan ? 'Salvar' : 'Criar plano' ?>
        </button>
        <?php if ($editPlan): ?>
        <a href="<?= SITE_URL ?>/admin/plans.php" class="btn btn-secondary">Cancelar</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
</div>

<!-- Modal atribuir plano -->
<div id="assign-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:1000;align-items:center;justify-content:center;padding:20px">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;width:100%;max-width:400px">
    <div style="font-family:'Roboto',sans-serif;font-size:16px;font-weight:700;margin-bottom:4px">Atribuir plano</div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:20px" id="assign-name"></div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="assign_plan">
      <input type="hidden" name="uid" id="assign-uid" value="">

      <div class="form-group">
        <label class="form-label">Plano</label>
        <select name="pid" id="assign-pid" class="form-control" onchange="updateDays(this)">
          <option value="">— Sem plano (acesso ilimitado) —</option>
          <?php foreach ($plans as $pl): ?>
          <option value="<?= $pl['id'] ?>" data-days="<?= $pl['duration_days'] ?>"><?= htmlspecialchars($pl['name']) ?> (<?= $pl['duration_days'] ?>d)</option>
          <?php endforeach; ?>
          <option value="" data-days="0">Personalizado...</option>
        </select>
      </div>

      <div class="form-group" id="custom-days-wrap">
        <label class="form-label">Duração em dias <span style="color:var(--muted)">(0 = sem expiração)</span></label>
        <input type="number" name="custom_days" id="assign-days" class="form-control" min="0" value="30">
      </div>

      <div style="background:var(--surface2);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--muted);margin-bottom:16px" id="assign-preview">
        Acesso por <b id="preview-days">30</b> dias — expira em <b id="preview-date"></b>
      </div>

      <div style="display:flex;gap:8px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Atribuir</button>
        <button type="button" class="btn btn-secondary" onclick="closeAssign()">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
const colorPicker = document.querySelector('input[type=color]');
const colorHex    = document.getElementById('color-hex');
if (colorPicker && colorHex) {
  colorPicker.addEventListener('input', () => colorHex.value = colorPicker.value);
  colorHex.addEventListener('input', () => { if(/^#[0-9a-fA-F]{6}$/.test(colorHex.value)) colorPicker.value = colorHex.value; });
}

function openAssign(uid, name) {
  document.getElementById('assign-uid').value = uid;
  document.getElementById('assign-name').textContent = 'Usuário: ' + name;
  document.getElementById('assign-modal').style.display = 'flex';
  updatePreview();
}
function closeAssign() {
  document.getElementById('assign-modal').style.display = 'none';
}

function updateDays(sel) {
  const days = parseInt(sel.options[sel.selectedIndex]?.dataset.days ?? 0);
  if (!isNaN(days) && days > 0) document.getElementById('assign-days').value = days;
  updatePreview();
}

function updatePreview() {
  const days = parseInt(document.getElementById('assign-days').value) || 0;
  document.getElementById('preview-days').textContent = days;
  if (days > 0) {
    const d = new Date(Date.now() + days * 86400000);
    document.getElementById('preview-date').textContent = d.toLocaleDateString('pt-BR');
    document.getElementById('assign-preview').style.display = '';
  } else {
    document.getElementById('assign-preview').style.display = 'none';
  }
}

document.getElementById('assign-days')?.addEventListener('input', updatePreview);
document.getElementById('assign-modal')?.addEventListener('click', e => { if(e.target === e.currentTarget) closeAssign(); });
updatePreview();
</script>

<script>
function copyPlanLink(id, el) {
  var url = <?= json_encode(SITE_URL) ?> + '/assinar/' + id;
  navigator.clipboard.writeText(url).then(function() {
    var orig = el.textContent.trim();
    var isDiv = el.tagName === 'DIV';
    if (isDiv) {
      el.textContent = '✅ Link copiado!';
      el.style.color = 'var(--success)';
      setTimeout(function(){
        el.textContent = url;
        el.style.color = '';
      }, 1800);
    } else {
      el.textContent = '✅';
      setTimeout(function(){ el.textContent = orig; }, 1500);
    }
  });
}

// ── Drag-and-drop para reordenar planos ──────────────────
var dragSrcId = null;
var CSRF = <?= json_encode(csrf_token()) ?>;
var BASE = <?= json_encode(SITE_URL) ?>;

function dragStart(e, id) {
  dragSrcId = id;
  e.dataTransfer.effectAllowed = 'move';
  e.currentTarget.style.opacity = '.4';
}
function dragEnd(e) {
  e.currentTarget.style.opacity = '';
  document.querySelectorAll('.plan-card').forEach(function(c) {
    c.style.border = '';
  });
}
function dragOver(e) {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  e.currentTarget.style.borderStyle = 'dashed';
}
function dragDrop(e, targetId) {
  e.preventDefault();
  if (dragSrcId === targetId) return;

  // Reordena no DOM
  var src    = document.getElementById('plan-' + dragSrcId);
  var target = document.getElementById('plan-' + targetId);
  var grid   = src.parentNode;

  // Insere antes ou depois dependendo da posição
  var cards  = Array.from(grid.querySelectorAll('.plan-card'));
  var srcIdx = cards.indexOf(src);
  var tgtIdx = cards.indexOf(target);

  if (srcIdx < tgtIdx) {
    grid.insertBefore(src, target.nextSibling);
  } else {
    grid.insertBefore(src, target);
  }

  // Salva nova ordem via AJAX
  var newOrder = Array.from(grid.querySelectorAll('.plan-card')).map(function(c) {
    return c.id.replace('plan-', '');
  });

  var fd = new FormData();
  fd.append('csrf_token', CSRF);
  fd.append('action', 'reorder');
  fd.append('order', JSON.stringify(newOrder));

  fetch(BASE + '/admin/plans.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.ok) console.error('Reorder failed');
    });
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
requireLoginAlways();
if ((currentUser()['role'] ?? '') !== 'admin') { header('Location: ' . SITE_URL . '/index'); exit; }

$db        = getDB();
$pageTitle = 'Usuários';
$message   = '';
$error     = '';

// Garante colunas
try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL"); } catch(Exception $e) {}
try { $db->exec("ALTER TABLE users ADD COLUMN suspended_at DATETIME DEFAULT NULL"); } catch(Exception $e) {}

// Exportar CSV de usuários
if (isset($_GET['export_csv']) && csrf_verify($_GET['csrf_token'] ?? '')) {
    $rows = $db->query('
        SELECT u.id, u.name, u.email, u.phone, u.role,
               p.name AS plano, u.expires_at, u.created_at
        FROM users u LEFT JOIN plans p ON p.id = u.plan_id
        WHERE u.role != "admin" ORDER BY u.created_at DESC
    ')->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="usuarios_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['ID','Nome','Email','Telefone','Perfil','Plano','Expira em','Cadastrado em'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'], $r['name'], $r['email'], $r['phone'] ?? '',
            $r['role'], $r['plano'] ?? '',
            $r['expires_at'] ? date('d/m/Y', strtotime($r['expires_at'])) : '',
            $r['created_at'] ? date('d/m/Y H:i', strtotime($r['created_at'])) : '',
        ], ';');
    }
    fclose($out); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['uid'] ?? 0);

    // ── Editar usuário ────────────────────────
    if ($action === 'edit_user' && $uid) {
        $name  = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        $role  = in_array($_POST['role']??'', ['admin','editor','viewer']) ? $_POST['role'] : 'viewer';
        $pass  = $_POST['password'] ?? '';

        if (!$name) {
            $error = 'Nome é obrigatório.';
        } else {
            if ($pass && strlen($pass) >= 6) {
                $db->prepare('UPDATE users SET name=?,email=?,phone=?,role=?,password=? WHERE id=?')
                   ->execute([$name, $email, $phone ?: null, $role, password_hash($pass, PASSWORD_DEFAULT), $uid]);
            } else {
                $db->prepare('UPDATE users SET name=?,email=?,phone=?,role=? WHERE id=?')
                   ->execute([$name, $email, $phone ?: null, $role, $uid]);
            }
            auditLog('edit_user', 'user:'.$uid, $name);
            $message = 'Usuário <b>'.htmlspecialchars($name).'</b> atualizado!';
        }
    }

    // ── Suspender / Reativar ─────────────────────
    if ($action === 'toggle_suspend' && $uid && $uid !== (int)$_SESSION['user_id']) {
        $cur = $db->prepare('SELECT suspended_at,name FROM users WHERE id=?'); $cur->execute([$uid]); $cur=$cur->fetch();
        if ($cur['suspended_at']) {
            $db->prepare('UPDATE users SET suspended_at=NULL WHERE id=?')->execute([$uid]);
            auditLog('unsuspend_user','user:'.$uid,$cur['name']);
            $message = 'Usuário <b>'.htmlspecialchars($cur['name']).'</b> reativado.';
        } else {
            $db->prepare('UPDATE users SET suspended_at=NOW() WHERE id=?')->execute([$uid]);
            auditLog('suspend_user','user:'.$uid,$cur['name']);
            $message = 'Usuário <b>'.htmlspecialchars($cur['name']).'</b> suspenso.';
        }
    }

    // ── Deletar ───────────────────────────────
    if ($action === 'delete' && $uid && $uid !== (int)$_SESSION['user_id']) {
        $nm = $db->prepare('SELECT name FROM users WHERE id=?'); $nm->execute([$uid]);
        $nm = $nm->fetchColumn() ?: 'Usuário';
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
        auditLog('delete_user', 'user:'.$uid, $nm);
        $message = 'Usuário <b>'.htmlspecialchars($nm).'</b> removido.';
    }

    // ── Adicionar crédito de afiliado ─────────────────────
    if ($action === 'add_credit' && $uid) {
        if (!function_exists('affiliateEnsureTables')) require_once __DIR__ . '/../includes/affiliate.php';
        affiliateEnsureTables();

        $rawAmount = round((float)($_POST['credit_amount'] ?? 0), 2);
        $note      = trim($_POST['credit_note'] ?? '') ?: ($rawAmount >= 0 ? 'Crédito adicionado pelo admin' : 'Saldo removido pelo admin');

        if ($rawAmount == 0) {
            $error = 'Informe um valor diferente de zero.';
        } else {
            // Garante que o usuário tem registro de afiliado (cria se não tiver)
            $affRow = $db->prepare('SELECT id FROM affiliates WHERE user_id=?');
            $affRow->execute([$uid]); $affRow = $affRow->fetch();

            if (!$affRow) {
                $uName = $db->prepare('SELECT name FROM users WHERE id=?');
                $uName->execute([$uid]);
                $uName = $uName->fetchColumn() ?: 'USR';
                $code  = affiliateGenerateCode($uName);
                $db->prepare('INSERT INTO affiliates (user_id, code, active) VALUES (?,?,1)')->execute([$uid, $code]);
                $affRow = ['id' => (int)$db->lastInsertId()];
            }

            $affId = (int)$affRow['id'];

            if ($rawAmount > 0) {
                // Adiciona crédito — insere em credits_added
                $db->exec("CREATE TABLE IF NOT EXISTS affiliate_credits_added (
                    id INT AUTO_INCREMENT PRIMARY KEY, affiliate_id INT NOT NULL,
                    amount DECIMAL(10,2) NOT NULL, note VARCHAR(200) DEFAULT NULL,
                    added_by INT DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                $db->prepare('INSERT INTO affiliate_credits_added (affiliate_id, amount, note, added_by) VALUES (?,?,?,?)')
                   ->execute([$affId, $rawAmount, $note, $_SESSION['user_id'] ?? null]);
                $message = '✅ R$ ' . number_format($rawAmount,2,',','.') . ' adicionado na carteira.';
            } else {
                // Remove crédito — limita ao saldo disponível
                $balance   = affiliateBalance($affId);
                $toDeduct  = min($balance, abs($rawAmount));
                if ($toDeduct <= 0) {
                    $error = 'Saldo insuficiente para remover esse valor.';
                } else {
                    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_credits_used (
                        id INT AUTO_INCREMENT PRIMARY KEY, affiliate_id INT NOT NULL,
                        amount DECIMAL(10,2) NOT NULL, transaction_id INT DEFAULT NULL,
                        note VARCHAR(200) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    )");
                    $db->prepare('INSERT INTO affiliate_credits_used (affiliate_id, amount, note) VALUES (?,?,?)')
                       ->execute([$affId, $toDeduct, $note]);
                    $message = '✅ R$ ' . number_format($toDeduct,2,',','.') . ' removido da carteira.';
                }
            }

            if (!$error) {
                $uNameLog = $db->prepare('SELECT name FROM users WHERE id=?');
                $uNameLog->execute([$uid]);
                auditLog('edit_credit', 'user:'.$uid.' amount:'.$rawAmount, $uNameLog->fetchColumn());
            }
        }
    }

    if ($action === 'create_user') {
        $name  = trim($_POST['new_name'] ?? '');
        $phone = preg_replace('/\D/', '', $_POST['new_phone'] ?? '');
        $email = strtolower(trim($_POST['new_email'] ?? ''));
        $pass  = $_POST['new_password'] ?? '';
        $role  = in_array($_POST['new_role']??'', ['admin','editor','viewer']) ? $_POST['new_role'] : 'viewer';
        if (!$name || strlen($pass) < 6) {
            $error = 'Preencha nome e senha (mín. 6 caracteres).';
        } else {
            // Email obrigatório para admin/editor, opcional para viewer (gera automático)
            if (!$email && $role !== 'viewer') { $error = 'E-mail obrigatório para admin/editor.'; }
            else {
                $email = $email ?: ('adm_'.time().'@local.cms');
                $db->prepare('INSERT INTO users (name,email,phone,password,role) VALUES (?,?,?,?,?)')
                   ->execute([$name, $email, $phone ?: null, password_hash($pass, PASSWORD_DEFAULT), $role]);
                $message = 'Usuário <b>'.htmlspecialchars($name).'</b> criado!';
            }
        }
    }
}

$search  = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = $search ? 'WHERE (u.name LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)' : '';
$params = $search ? ["%$search%", "%$search%", "%$search%"] : [];

$totalUsers = $db->prepare("SELECT COUNT(*) FROM users u $where");
$totalUsers->execute($params); $totalUsers = (int)$totalUsers->fetchColumn();
$totalPages = (int)ceil($totalUsers / $perPage);

$usersStmt = $db->prepare("
    SELECT u.*, COUNT(DISTINCT p.id) AS post_count,
           pl.name AS plan_name, pl.color AS plan_color
    FROM users u
    LEFT JOIN posts p  ON p.user_id = u.id
    LEFT JOIN plans pl ON pl.id     = u.plan_id
    $where
    GROUP BY u.id ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$usersStmt->execute($params);
$users = $usersStmt->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<style>
.ut td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
.ut tr:last-child td{border:none}
.ut tbody tr:hover td{background:var(--surface2)}
.av{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#fff;flex-shrink:0}
.rb{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700}
.rb-admin {background:rgba(255,106,158,.15);color:var(--accent2)}
.rb-editor{background:rgba(124,106,255,.15);color:var(--accent)}
.rb-viewer{background:rgba(107,107,128,.15);color:var(--muted2)}
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.78);z-index:200;display:none;align-items:center;justify-content:center;padding:20px}
.modal-bg.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:18px;padding:28px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto}
</style>

<?php if ($message): ?><div class="alert alert-success" style="margin-bottom:18px">✅ <?= $message ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"  style="margin-bottom:18px">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 310px;gap:20px;align-items:start">

<!-- Tabela -->
<div class="card" style="overflow:hidden">
  <div style="padding:15px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <span style="font-family:'Roboto',sans-serif;font-size:16px;font-weight:700">Usuários (<?= $totalUsers ?>)</span>
    <form method="GET" style="display:flex;gap:8px;margin-left:auto;flex-wrap:wrap">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
             placeholder="Buscar por nome, telefone ou email..."
             class="form-control" style="width:260px;padding:7px 12px;font-size:13px">
      <button type="submit" class="btn btn-secondary" style="padding:7px 14px;font-size:13px">🔍</button>
      <?php if ($search): ?><a href="?" class="btn btn-secondary" style="padding:7px 12px;font-size:13px">✕</a><?php endif; ?>
    </form>
    <a href="?export_csv=1&csrf_token=<?= csrf_token() ?>" class="btn btn-secondary" style="padding:7px 12px;font-size:13px;white-space:nowrap">📥 CSV</a>
  </div>
  <table style="width:100%;border-collapse:collapse" class="ut">
    <thead>
      <tr style="border-bottom:1px solid var(--border)">
        <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">USUÁRIO</th>
        <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">PERFIL</th>
        <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">PLANO / EXPIRA</th>
        <th style="padding:9px 14px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">CADASTRO</th>
        <th style="padding:9px 14px;text-align:right;font-size:11px;color:var(--muted);font-weight:600">AÇÕES</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
      $isSelf  = ($u['id'] == $_SESSION['user_id']);
      $avBg    = htmlspecialchars($u['plan_color'] ?: '#7c6aff');
      $expDays = null;
      if (!empty($u['expires_at'])) $expDays = ceil((strtotime($u['expires_at'])-time())/86400);
    ?>
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:10px">
          <div class="av" style="background:<?= $avBg ?>"><?= strtoupper(substr($u['name'],0,1)) ?></div>
          <div>
            <div style="font-size:13px;font-weight:600">
              <?= htmlspecialchars($u['name']) ?>
              <?php if ($isSelf): ?><span style="font-size:10px;background:rgba(124,106,255,.15);color:var(--accent);padding:1px 6px;border-radius:10px;margin-left:4px">Você</span><?php endif; ?>
            </div>
            <div class="txt-muted-xs"><?= htmlspecialchars($u['email']) ?></div>
              <?php if (!empty($u['phone'])): ?>
              <div class="txt-muted-xs">📱 <?= htmlspecialchars($u['phone']) ?></div>
              <?php endif; ?>
          </div>
        </div>
      </td>
      <td><span class="rb rb-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
      <td>
        <?php if ($u['plan_name']): ?>
        <div style="font-size:12px;font-weight:600;color:<?= $avBg ?>"><?= htmlspecialchars($u['plan_name']) ?></div>
        <?php if ($expDays !== null): ?>
        <div style="font-size:11px;color:<?= $expDays<0?'var(--danger)':($expDays<=3?'var(--warning)':'var(--muted)') ?>">
          <?= $expDays<0 ? 'Expirado' : $expDays.'d' ?>
        </div>
        <?php endif; ?>
        <?php else: ?><span class="txt-muted-sm">—</span><?php endif; ?>
      </td>
      <td class="txt-muted-sm"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
      <td style="text-align:right">
        <div style="display:flex;gap:5px;justify-content:flex-end">
          <button type="button" class="btn btn-secondary" style="padding:5px 10px;font-size:11px"
                  onclick='openEdit(<?= json_encode(["id"=>$u["id"],"name"=>$u["name"],"email"=>$u["email"],"phone"=>$u["phone"]??'',"role"=>$u["role"]]) ?>)'>
            ✏️ Editar
          </button>
          <button type="button" class="btn btn-secondary" style="padding:5px 10px;font-size:11px;color:var(--accent);border-color:rgba(124,106,255,.35)"
                  onclick='openCredit(<?= $u["id"] ?>, <?= json_encode($u["name"]) ?>)'>
            💳
          </button>
          <?php if (!$isSelf): ?>
          <form method="POST" onsubmit="return confirm('Excluir <?= htmlspecialchars(addslashes($u['name'])) ?>?')">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="uid" value="<?= $u['id'] ?>">
            <button type="submit" class="btn btn-danger" style="padding:5px 10px;font-size:11px">🗑️</button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php
  $baseUrl = '?' . http_build_query(array_diff_key($_GET, ['page'=>'']));
  echo renderPagination($page, $totalPages, rtrim($baseUrl,'?&'));
  ?>
</div>

<!-- Criar usuário -->
<div class="card" style="padding:22px">
  <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;margin-bottom:18px">➕ Novo Usuário</div>
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="action" value="create_user">
    <div class="form-group">
      <label class="form-label">Nome</label>
      <input type="text" name="new_name" class="form-control" required placeholder="Nome completo">
    </div>
    <div class="form-group">
      <label class="form-label">Telefone</label>
      <input type="tel" name="new_phone" class="form-control" placeholder="(11) 99999-9999"
             oninput="this.value=this.value.replace(/\D/g,'').replace(/^(\d{2})(\d)/,'($1) $2').replace(/(\d{4,5})(\d{4})$/,'$1-$2')">
    </div>
    <div class="form-group">
      <label class="form-label">E-mail <span style="color:var(--muted);font-weight:400">(opcional)</span></label>
      <input type="email" name="new_email" class="form-control" placeholder="email@exemplo.com">
    </div>
    <div class="form-group">
      <label class="form-label">Senha</label>
      <input type="password" name="new_password" class="form-control" required placeholder="Mín. 6 caracteres" minlength="6">
    </div>
    <div class="form-group">
      <label class="form-label">Perfil</label>
      <select name="new_role" class="form-control">
        <option value="viewer">Viewer</option>
        <option value="editor">Editor</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Criar</button>
  </form>
</div>
</div>

<!-- Modal de edição -->
<div class="modal-bg" id="edit-modal" onclick="if(event.target===this)closeEdit()">
  <div class="modal">
    <div style="font-family:'Roboto',sans-serif;font-size:18px;font-weight:800;margin-bottom:4px">✏️ Editar Usuário</div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:22px" id="modal-sub">Editando usuário</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="uid" id="edit-uid">
      <div class="form-group">
        <label class="form-label">Nome completo</label>
        <input type="text" name="name" id="edit-name" class="form-control" required placeholder="Nome completo">
      </div>
      <div class="form-group">
        <label class="form-label">Telefone / WhatsApp</label>
        <input type="tel" name="phone" id="edit-phone" class="form-control" placeholder="(11) 99999-9999">
      </div>
      <div class="form-group">
        <label class="form-label">E-mail <span style="color:var(--muted);font-weight:400;font-size:11px">(opcional)</span></label>
        <input type="email" name="email" id="edit-email" class="form-control" placeholder="email@exemplo.com">
      </div>
      <div class="form-group">
        <label class="form-label">Nova senha <span style="color:var(--muted);font-weight:400;font-size:11px">(vazio = mantém atual)</span></label>
        <div style="position:relative">
          <input type="password" name="password" id="edit-pass" class="form-control"
                 placeholder="••••••••" minlength="6" autocomplete="new-password" style="padding-right:44px">
          <button type="button" onclick="togglePass()" title="Mostrar/ocultar"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;color:var(--muted)" id="pass-eye">👁️</button>
        </div>
        <div class="txt-muted-mt">Mínimo 6 caracteres.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Perfil</label>
        <select name="role" id="edit-role" class="form-control">
          <option value="viewer">Viewer — só visualiza</option>
          <option value="editor">Editor — cria e edita posts</option>
          <option value="admin">Admin — acesso total</option>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:20px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Salvar
        </button>
        <button type="button" class="btn btn-secondary" onclick="closeEdit()" style="padding:8px 18px">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal de crédito -->
<div class="modal-bg" id="credit-modal" onclick="if(event.target===this)closeCredit()">
  <div class="modal">
    <div style="font-family:'Roboto',sans-serif;font-size:18px;font-weight:800;margin-bottom:4px">💳 Adicionar crédito</div>
    <div style="font-size:13px;color:var(--muted);margin-bottom:20px" id="credit-modal-sub">Carteira do usuário</div>

    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="add_credit">
      <input type="hidden" name="uid" id="credit-uid">

      <div class="form-group">
        <label class="form-label">Valor (R$)</label>
        <input type="number" name="credit_amount" id="credit-amount" class="form-control"
               required step="0.01" placeholder="Ex: 20 para adicionar, -20 para remover"
               style="font-size:16px;font-weight:700;text-align:center">
        <div class="txt-muted-mt">Positivo = adiciona · Negativo = remove (ex: <code>-10</code>)</div>
      </div>

      <div class="form-group">
        <label class="form-label">Motivo <span style="color:var(--muted);font-weight:400;font-size:11px">(opcional)</span></label>
        <input type="text" name="credit_note" id="credit-note" class="form-control"
               placeholder="Ex: Bônus de boas-vindas, Reembolso, Estorno..." maxlength="200">
      </div>

      <div style="background:rgba(124,106,255,.07);border:1px solid rgba(124,106,255,.2);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--muted);margin-bottom:16px;line-height:1.7">
        💡 O crédito pode ser usado pelo usuário como desconto na compra de planos.
        Use valor negativo para corrigir ou remover saldo.
      </div>

      <div style="display:flex;gap:10px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">
          ✅ Confirmar
        </button>
        <button type="button" class="btn btn-secondary" onclick="closeCredit()" style="padding:8px 18px">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(u) {
  document.getElementById('edit-uid').value   = u.id;
  document.getElementById('edit-name').value  = u.name;
  document.getElementById('edit-email').value = u.email || '';
  document.getElementById('edit-phone').value = u.phone || '';
  document.getElementById('edit-role').value  = u.role;
  document.getElementById('edit-pass').value  = '';
  document.getElementById('modal-sub').textContent = 'Editando: ' + u.name;
  document.getElementById('edit-modal').classList.add('open');
  setTimeout(() => document.getElementById('edit-name').focus(), 80);
}
function closeEdit() {
  document.getElementById('edit-modal').classList.remove('open');
}
function openCredit(uid, name) {
  document.getElementById('credit-uid').value = uid;
  document.getElementById('credit-amount').value = '';
  document.getElementById('credit-note').value = '';
  document.getElementById('credit-modal-sub').textContent = 'Carteira de: ' + name;
  document.getElementById('credit-modal').classList.add('open');
  setTimeout(() => document.getElementById('credit-amount').focus(), 80);
}
function closeCredit() {
  document.getElementById('credit-modal').classList.remove('open');
}
function togglePass() {
  const i = document.getElementById('edit-pass');
  const b = document.getElementById('pass-eye');
  i.type = i.type === 'password' ? 'text' : 'password';
  b.textContent = i.type === 'password' ? '👁️' : '🙈';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeEdit(); closeCredit(); } });

<?php if ($error && ($_POST['action'] ?? '') === 'edit_user'): ?>
document.addEventListener('DOMContentLoaded', () => openEdit({
  id:    '<?= (int)($_POST['uid']??0) ?>',
  name:  '<?= htmlspecialchars(addslashes($_POST['name']??'')) ?>',
  email: '<?= htmlspecialchars(addslashes($_POST['email']??'')) ?>',
  role:  '<?= htmlspecialchars($_POST['role']??'viewer') ?>',
}));
<?php endif; ?>
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/auth.php';
requireLoginAlways();

$db        = getDB();
$user      = currentUser();
$pageTitle = 'Meu Perfil';
$message   = '';
$error     = '';

$isAnon = !empty($user['is_anonymous']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Token inválido.';
    } else {
        $action = $_POST['action'] ?? 'profile';

        // Usuários anônimos não podem alterar perfil nem senha
        if ($isAnon) { $error = 'Usuário anônimo não possui perfil editável.'; $action = '__skip__'; }

        if ($action === 'profile') {
            $name  = trim($_POST['name'] ?? '');
            $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
            if (!$name) { $error = 'Nome é obrigatório.'; }
            elseif ($phone) {
                // Valida formato e unicidade
                if (!validateBrazilianPhone($phone)) {
                    $error = 'Telefone inválido. Use o formato (DDD) + número, ex: (11) 99999-9999.';
                } else {
                    $dup = $db->prepare('SELECT id FROM users WHERE phone=? AND id!=? LIMIT 1');
                    $dup->execute([$phone, $user['id']]);
                    if ($dup->fetch()) {
                        $error = 'Este telefone já está sendo usado por outra conta.';
                    } else {
                        $db->prepare('UPDATE users SET name=?,phone=? WHERE id=?')
                           ->execute([$name, $phone, $user['id']]);
                        $_SESSION['user_name'] = $name;
                        $message = 'Perfil atualizado com sucesso!';
                        $user = array_merge($user, ['name'=>$name,'phone'=>$phone]);
                    }
                }
            } else {
                $db->prepare('UPDATE users SET name=?,phone=? WHERE id=?')
                   ->execute([$name, null, $user['id']]);
                $_SESSION['user_name'] = $name;
                $message = 'Perfil atualizado com sucesso!';
                $user = array_merge($user, ['name'=>$name,'phone'=>'']);
            }
        } elseif ($action === 'password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $stmt = $db->prepare('SELECT password FROM users WHERE id=?');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch();

            if (!password_verify($current, $row['password'])) { $error = 'Senha atual incorreta.'; }
            elseif (strlen($new) < 6) { $error = 'Nova senha deve ter ao menos 6 caracteres.'; }
            elseif ($new !== $confirm) { $error = 'As senhas não coincidem.'; }
            else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $db->prepare('UPDATE users SET password=? WHERE id=?')->execute([$hash, $user['id']]);
                $message = 'Senha alterada com sucesso!';
            }
        }
    }
}

// User stats
$myPosts = $db->prepare('SELECT COUNT(*) FROM posts WHERE user_id=?');
$myPosts->execute([$user['id']]);
$myPostsCount = $myPosts->fetchColumn();

$myViews = $db->prepare('SELECT COALESCE(SUM(views),0) FROM posts WHERE user_id=?');
$myViews->execute([$user['id']]);
$myViewsCount = $myViews->fetchColumn();

$myMedia = $db->prepare('SELECT COUNT(*) FROM media m JOIN posts p ON m.post_id=p.id WHERE p.user_id=?');
$myMedia->execute([$user['id']]);
$myMediaCount = $myMedia->fetchColumn();

// Recent posts
$recentStmt = $db->prepare('
    SELECT p.id, p.title, p.status, p.views, p.created_at,
           (SELECT file_path FROM media WHERE post_id=p.id AND file_type="image" LIMIT 1) AS thumb
    FROM posts p WHERE p.user_id=? ORDER BY p.created_at DESC LIMIT 5
');
$recentStmt->execute([$user['id']]);
$recentPosts = $recentStmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<div style="display:grid;grid-template-columns:320px 1fr;gap:24px;align-items:start">

<!-- Profile card -->
<div>
  <div class="card" style="padding:28px;text-align:center;margin-bottom:16px">
    <div style="width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;color:#fff;margin:0 auto 16px">
      <?= strtoupper(substr($user['name'], 0, 1)) ?>
    </div>
    <div style="font-family:'Roboto',sans-serif;font-size:18px;font-weight:700"><?= htmlspecialchars($user['name']) ?></div>
    <?php if (!empty($user['phone'])): ?>
    <div style="font-size:13px;color:var(--muted);margin-top:4px">📱 <?= htmlspecialchars($user['phone']) ?></div>
    <?php elseif (!empty($user['email']) && strpos($user['email'], 'user_') !== 0 && strpos($user['email'], 'adm_') !== 0 && substr($user['email'], -strlen('@local.cms')) !== '@local.cms'): ?>
    <div style="font-size:13px;color:var(--muted);margin-top:4px"><?= htmlspecialchars($user['email']) ?></div>
    <?php endif; ?>
    <div style="margin-top:10px">
      <span class="badge badge-accent"><?= ucfirst($user['role']) ?></span>
    </div>
  </div>

  <?php if (!$isAnon): ?>
  <!-- Stats -->
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px">
    <div class="stat-card" style="text-align:center;padding:14px">
      <div class="stat-value" style="font-size:22px;color:var(--accent)"><?= $myPostsCount ?></div>
      <div class="stat-label" style="font-size:11px">Posts</div>
    </div>
    <div class="stat-card" style="text-align:center;padding:14px">
      <div class="stat-value" style="font-size:22px;color:var(--accent2)"><?= number_format($myViewsCount) ?></div>
      <div class="stat-label" style="font-size:11px">Views</div>
    </div>
    <div class="stat-card" style="text-align:center;padding:14px">
      <div class="stat-value" style="font-size:22px;color:var(--accent3)"><?= $myMediaCount ?></div>
      <div class="stat-label" style="font-size:11px">Arquivos</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent activity -->
  <?php if (!$isAnon && $recentPosts): ?>
  <div class="card" style="overflow:hidden">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700">Meus posts recentes</div>
    <?php foreach ($recentPosts as $rp): ?>
    <a href="<?= SITE_URL ?>/post/<?= $rp['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);transition:background .15s" onmouseover="this.style.background='var(--surface2)'" onmouseout="this.style.background=''">
      <div style="width:36px;height:36px;border-radius:6px;overflow:hidden;background:var(--surface2);flex-shrink:0">
        <?php if ($rp['thumb']): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($rp['thumb']) ?>" style="width:100%;height:100%;object-fit:cover">
        <?php else: ?>
        <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--border2)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
        </div>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($rp['title']) ?></div>
        <div class="txt-muted-xs"><?= timeAgo($rp['created_at']) ?> · <?= number_format($rp['views']) ?> views</div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Edit forms -->
<div style="display:flex;flex-direction:column;gap:20px">
  <?php if ($message): ?><div class="alert alert-success"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if ($isAnon): ?>
  <!-- ═══ Perfil ANÔNIMO — só código de acesso e sair ═══ -->
  <div class="card" style="padding:28px;border:1px solid rgba(124,106,255,.35);background:linear-gradient(135deg,rgba(124,106,255,.08),rgba(255,106,158,.05))">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
      <div style="font-family:'Roboto',sans-serif;font-size:16px;font-weight:700">🔐 Seu código de acesso</div>
      <span style="background:rgba(255,106,158,.18);color:var(--accent2);font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px">🕶️ Conta anônima</span>
    </div>
    <p style="font-size:13px;color:var(--muted2);margin-bottom:18px;line-height:1.55">
      Este é o único dado necessário para entrar novamente na sua conta. Guarde com segurança — ele <b>não pode ser recuperado</b> se for perdido.
    </p>

    <?php if (!empty($user['access_code'])): ?>
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:16px">
      <div id="acc-code" style="flex:1;font-family:'Courier New',monospace;font-size:22px;font-weight:800;letter-spacing:3px;color:var(--text);background:var(--surface2);border:1px solid var(--border);padding:16px;border-radius:10px;text-align:center">
        <?= htmlspecialchars($user['access_code']) ?>
      </div>
      <button type="button" id="btn-acc-copy" onclick="copyAccCode()" class="btn btn-primary" style="padding:16px 18px;white-space:nowrap">📋 Copiar</button>
    </div>
    <?php else: ?>
    <div class="alert alert-danger">Código não encontrado. Contate o administrador.</div>
    <?php endif; ?>

    <?php if (!empty($user['expires_at'])):
      $expTs = strtotime($user['expires_at']);
      $days  = ceil(($expTs - time()) / 86400);
      $expired = $expTs < time();
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--surface2);border-radius:9px;font-size:13px">
      <?php if ($expired): ?>
        <span style="color:var(--danger);font-weight:700">⚠ Acesso expirado</span>
        <a href="<?= SITE_URL ?>/renovar" class="btn btn-primary" style="margin-left:auto;padding:6px 14px;font-size:12px">Renovar</a>
      <?php else: ?>
        <span style="color:var(--muted2)">Acesso válido até</span>
        <b style="color:var(--text)"><?= date('d/m/Y', $expTs) ?></b>
        <span style="color:<?= $days<=3?'var(--warning)':'var(--success)' ?>">· <?= $days ?> dia<?= $days!=1?'s':'' ?> restante<?= $days!=1?'s':'' ?></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sair -->
  <div class="card" style="padding:20px;display:flex;align-items:center;gap:14px">
    <div style="flex:1">
      <div style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:700">Encerrar sessão neste dispositivo</div>
      <div style="font-size:12px;color:var(--muted)">Você pode voltar a qualquer momento usando seu código de acesso.</div>
    </div>
    <a href="<?= SITE_URL ?>/logout" class="btn btn-danger">Sair</a>
  </div>

  <script>
  function copyAccCode() {
    var el = document.getElementById('acc-code');
    var btn = document.getElementById('btn-acc-copy');
    if (!el) return;
    var text = el.textContent.trim();
    function done(){ if(btn){ var o=btn.textContent; btn.textContent='✅ Copiado!'; setTimeout(function(){ btn.textContent=o; },1800); } }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done).catch(function(){
        var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');done();}catch(e){} document.body.removeChild(ta);
      });
    } else {
      var ta=document.createElement('textarea'); ta.value=text; ta.style.cssText='position:fixed;top:-9999px'; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');done();}catch(e){} document.body.removeChild(ta);
    }
  }
  </script>

  <?php else: ?>
  <!-- ═══ Perfil NORMAL — edita dados e senha ═══ -->
  <div class="card" style="padding:24px">
    <div style="font-family:'Roboto',sans-serif;font-size:16px;font-weight:700;margin-bottom:20px">Informações do perfil</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="profile">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Nome completo</label>
          <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($user['name']) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Telefone / WhatsApp</label>
          <input type="tel" name="phone" class="form-control"
                 value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                 placeholder="(11) 99999-9999"
                 oninput="this.value=this.value.replace(/\D/g,'').replace(/^(\d{2})(\d)/,'($1) $2').replace(/(\d{4,5})(\d{4})$/,'$1-$2')">
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Salvar perfil</button>
    </form>
  </div>

  <div class="card" style="padding:24px">
    <div style="font-family:'Roboto',sans-serif;font-size:16px;font-weight:700;margin-bottom:20px">Alterar senha</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="password">
      <div class="form-group">
        <label class="form-label">Senha atual</label>
        <input type="password" name="current_password" class="form-control" required placeholder="••••••••">
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div class="form-group">
          <label class="form-label">Nova senha</label>
          <input type="password" name="new_password" class="form-control" required placeholder="Mín. 6 caracteres" minlength="6">
        </div>
        <div class="form-group">
          <label class="form-label">Confirmar nova senha</label>
          <input type="password" name="confirm_password" class="form-control" required placeholder="Repita a senha">
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Alterar senha</button>
    </form>
  </div>

  <div class="card" style="padding:24px;border-color:rgba(255,77,106,.2)">
    <div style="font-family:'Roboto',sans-serif;font-size:16px;font-weight:700;margin-bottom:8px;color:var(--danger)">Zona de perigo</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:16px">Ações irreversíveis. Prossiga com cautela.</p>
    <a href="<?= SITE_URL ?>/logout" class="btn btn-danger">Encerrar sessão</a>
  </div>
  <?php endif; ?>
</div>

</div>

<script>
document.querySelectorAll('input[name=phone]').forEach(function(inp){
  inp.addEventListener('input',function(){
    var v=this.value.replace(/\D/g,'').slice(0,11);
    if(v.length<=10) v=v.replace(/^(\d{2})(\d{4})(\d{0,4})/,'($1) $2-$3');
    else v=v.replace(/^(\d{2})(\d{5})(\d{0,4})/,'($1) $2-$3');
    this.value=v.replace(/-$/,'');
  });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>

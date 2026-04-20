<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db     = getDB();
$user   = currentUser();
$userId = (int)$user['id'];

$pageTitle    = 'Suporte';
$siteName     = getSetting('site_name', SITE_NAME);
$telegramUrl  = getSetting('support_telegram', '');
$supportText  = getSetting('support_text', '');
$supportEmail = getSetting('support_email', '');
$supportHours = getSetting('support_hours', '');
$chatEnabled  = getSetting('support_contact_form', '0') === '1';

try {
    $db->exec("CREATE TABLE IF NOT EXISTS support_messages (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, subject VARCHAR(200) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_sm_user (user_id))");
    $db->exec("CREATE TABLE IF NOT EXISTS support_replies (id INT AUTO_INCREMENT PRIMARY KEY, message_id INT NOT NULL, sender ENUM('user','admin') NOT NULL DEFAULT 'user', body TEXT NOT NULL, read_at DATETIME DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_sr_message (message_id))");
} catch(Exception $e) {}

$error = '';

// Nova thread
if ($chatEnabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_message']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $subject = trim($_POST['subject'] ?? '');
    $body    = trim($_POST['body'] ?? '');
    if (!$subject || !$body) { $error = 'Preencha o assunto e a mensagem.'; }
    else {
        $db->prepare('INSERT INTO support_messages (user_id, subject) VALUES (?,?)')->execute([$userId, $subject]);
        $msgId = $db->lastInsertId();
        $db->prepare('INSERT INTO support_replies (message_id, sender, body) VALUES (?,?,?)')->execute([$msgId,'user',$body]);
        $adminEmail = $supportEmail ?: getSetting('smtp_from','');
        if ($adminEmail) {
            $html = "<div style='font-family:sans-serif;max-width:600px;padding:24px;background:#0a0a0f;color:#e8e8f0;border-radius:12px'>
                <h2 style='color:#7c6aff'>Nova mensagem — {$siteName}</h2>
                <p>De: <b>{$user['name']}</b> &mdash; Assunto: <b>" . htmlspecialchars($subject) . "</b></p>
                <div style='background:#13131a;border-radius:8px;padding:14px;font-size:14px;line-height:1.7;color:#c8c8d8'>" . nl2br(htmlspecialchars($body)) . "</div>
                <a href='" . SITE_URL . "/admin/mensagens?id={$msgId}' style='display:inline-block;margin-top:14px;background:#7c6aff;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700'>Responder</a>
            </div>";
            sendMail($adminEmail, "[{$siteName}] Nova mensagem: " . htmlspecialchars($subject), $html);
        }
        auditLog('new_support_message', 'message:'.$msgId);
        header('Location: ' . SITE_URL . '/suporte?chat=1&id='.$msgId); exit;
    }
}

// Resposta do usuário
if ($chatEnabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $msgId = (int)($_POST['message_id'] ?? 0);
    $body  = trim($_POST['body'] ?? '');
    $owns  = $db->prepare('SELECT id FROM support_messages WHERE id=? AND user_id=?');
    $owns->execute([$msgId, $userId]);
    if ($owns->fetch() && $body) {
        $db->prepare('INSERT INTO support_replies (message_id, sender, body) VALUES (?,?,?)')->execute([$msgId,'user',$body]);
        $adminEmail = $supportEmail ?: getSetting('smtp_from','');
        if ($adminEmail) {
            $html = "<div style='font-family:sans-serif;padding:20px;background:#0a0a0f;color:#e8e8f0;border-radius:12px'>
                <h3 style='color:#7c6aff'>Nova resposta de {$user['name']}</h3>
                <div style='background:#13131a;border-radius:8px;padding:14px;margin:12px 0;font-size:14px;color:#c8c8d8'>" . nl2br(htmlspecialchars($body)) . "</div>
                <a href='" . SITE_URL . "/admin/mensagens?id={$msgId}' style='background:#7c6aff;color:#fff;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:700'>Ver conversa</a>
            </div>";
            sendMail($adminEmail, "[{$siteName}] Resposta na conversa #{$msgId}", $html);
        }
        header('Location: ' . SITE_URL . '/suporte?chat=1&id='.$msgId); exit;
    }
}

// Thread aberta + marcar lidas
$openThread = null; $replies = [];
if ($chatEnabled && isset($_GET['id'])) {
    $mid = (int)$_GET['id'];
    $db->prepare('UPDATE support_replies SET read_at=NOW() WHERE message_id=? AND sender="admin" AND read_at IS NULL AND EXISTS (SELECT 1 FROM support_messages sm WHERE sm.id=? AND sm.user_id=?)')->execute([$mid,$mid,$userId]);
    $st = $db->prepare('SELECT * FROM support_messages WHERE id=? AND user_id=?');
    $st->execute([$mid,$userId]); $openThread = $st->fetch();
    if ($openThread) {
        $r = $db->prepare('SELECT * FROM support_replies WHERE message_id=? ORDER BY created_at ASC');
        $r->execute([$mid]); $replies = $r->fetchAll();
    }
}

// Threads do usuário
$threads = []; $totalUnread = 0;
if ($chatEnabled) {
    $tStmt = $db->prepare("
        SELECT sm.*,
               (SELECT body FROM support_replies WHERE message_id=sm.id ORDER BY created_at DESC LIMIT 1) AS last_body,
               (SELECT created_at FROM support_replies WHERE message_id=sm.id ORDER BY created_at DESC LIMIT 1) AS last_at,
               (SELECT sender FROM support_replies WHERE message_id=sm.id ORDER BY created_at DESC LIMIT 1) AS last_sender,
               (SELECT COUNT(*) FROM support_replies WHERE message_id=sm.id AND sender='admin' AND read_at IS NULL) AS unread
        FROM support_messages sm WHERE sm.user_id=?
        ORDER BY last_at DESC, sm.created_at DESC
    ");
    $tStmt->execute([$userId]); $threads = $tStmt->fetchAll();
    $totalUnread = array_sum(array_column($threads,'unread'));
}

$showChat = isset($_GET['chat']) || isset($_GET['id']) || isset($_GET['nova']);
require __DIR__ . '/includes/header.php';
?>
<style>
.msg-row{display:flex;gap:10px;padding:13px 16px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;transition:background .15s}
.msg-row:hover,.msg-row.active{background:var(--surface2)}
.bubble{padding:10px 14px;border-radius:14px;font-size:14px;line-height:1.6;white-space:pre-wrap;word-break:break-word;max-width:85%}
.bubble-user{background:rgba(124,106,255,.18);border:1px solid rgba(124,106,255,.25);border-radius:14px 14px 2px 14px;align-self:flex-end}
.bubble-admin{background:var(--surface2);border-radius:14px 14px 14px 2px;align-self:flex-start}
.chat-wrap{display:grid;grid-template-columns:220px 1fr}
.chat-list{border-right:1px solid var(--border)}
.chat-main{display:flex;flex-direction:column}
.back-btn{display:none;align-items:center;gap:6px;padding:10px 14px;font-size:13px;color:var(--accent);text-decoration:none;border-bottom:1px solid var(--border)}
@media(max-width:640px){
  .chat-wrap{display:block}
  .chat-list{border-right:none}
  .panel-hidden{display:none!important}
  .back-btn{display:flex}
}
</style>

<div style="max-width:720px;margin:0 auto">

  <div style="text-align:center;padding:28px 0 18px">
    <div style="width:54px;height:54px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;margin:0 auto 12px">
      <svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    </div>
    <div style="font-family:'Roboto',sans-serif;font-size:22px;font-weight:800;margin-bottom:5px">Central de Suporte</div>
    <div style="font-size:13px;color:var(--muted2)">Estamos aqui para ajudar você</div>
  </div>

  <?php if ($supportText): ?>
  <div class="card" style="padding:18px;margin-bottom:12px;border-color:rgba(124,106,255,.2)">
    <div style="font-size:14px;line-height:1.8;white-space:pre-line"><?= nl2br(htmlspecialchars($supportText)) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($telegramUrl || $supportEmail): ?>
  <div style="display:grid;grid-template-columns:<?= ($telegramUrl && $supportEmail)?'1fr 1fr':'1fr' ?>;gap:12px;margin-bottom:12px">
    <?php if ($telegramUrl): ?>
    <a href="<?= htmlspecialchars($telegramUrl) ?>" target="_blank" rel="noopener"
       class="card" style="padding:18px;text-decoration:none;text-align:center;border-color:rgba(0,136,204,.25);transition:all .2s;display:block"
       onmouseover="this.style.borderColor='#0088cc';this.style.transform='translateY(-2px)'"
       onmouseout="this.style.borderColor='rgba(0,136,204,.25)';this.style.transform=''">
      <div style="width:40px;height:40px;border-radius:50%;background:rgba(0,136,204,.12);display:flex;align-items:center;justify-content:center;margin:0 auto 8px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="#0088cc"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221l-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12l-6.871 4.326-2.962-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.833.941z"/></svg>
      </div>
      <div style="font-weight:700;color:#0088cc;font-size:14px;margin-bottom:2px">Telegram</div>
      <div class="txt-muted-sm">Abrir chat</div>
    </a>
    <?php endif; ?>
    <?php if ($supportEmail): ?>
    <a href="mailto:<?= htmlspecialchars($supportEmail) ?>"
       class="card" style="padding:18px;text-decoration:none;text-align:center;border-color:rgba(16,185,129,.25);transition:all .2s;display:block"
       onmouseover="this.style.borderColor='var(--success)';this.style.transform='translateY(-2px)'"
       onmouseout="this.style.borderColor='rgba(16,185,129,.25)';this.style.transform=''">
      <div style="width:40px;height:40px;border-radius:50%;background:rgba(16,185,129,.1);display:flex;align-items:center;justify-content:center;margin:0 auto 8px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      </div>
      <div style="font-weight:700;color:var(--success);font-size:14px;margin-bottom:2px">E-mail</div>
      <div class="txt-muted-sm"><?= htmlspecialchars($supportEmail) ?></div>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($supportHours): ?>
  <div class="card" style="padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:12px">
    <div style="width:32px;height:32px;border-radius:50%;background:rgba(245,158,11,.1);display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--warning)" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div>
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted)">Horário de Atendimento</div>
      <div style="font-size:13px"><?= htmlspecialchars($supportHours) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($chatEnabled): ?>
  <div class="card" style="overflow:hidden">

    <!-- Tabs -->
    <div style="display:flex;align-items:center;border-bottom:1px solid var(--border);padding:0 16px">
      <a href="suporte.php" style="padding:12px 0;margin-right:20px;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid <?= !$showChat?'var(--accent)':'transparent' ?>;color:<?= !$showChat?'var(--accent)':'var(--muted)' ?>">
        ℹ️ Contato
      </a>
      <a href="suporte.php?chat=1" style="padding:12px 0;font-size:13px;font-weight:600;text-decoration:none;border-bottom:2px solid <?= $showChat?'var(--accent)':'transparent' ?>;color:<?= $showChat?'var(--accent)':'var(--muted)' ?>;display:flex;align-items:center;gap:6px">
        💬 Minhas mensagens<?php if($totalUnread):?> <span style="background:var(--accent);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:20px"><?= $totalUnread ?></span><?php endif;?>
      </a>
      <?php if($showChat):?>
      <a href="suporte.php?nova=1" style="margin-left:auto;font-size:12px;color:var(--accent);text-decoration:none;padding:3px 10px;border:1px solid rgba(124,106,255,.3);border-radius:6px">+ Nova</a>
      <?php endif;?>
    </div>

    <?php if (!$showChat): ?>
    <div style="padding:20px;text-align:center;color:var(--muted2)">
      <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.35;display:block;margin:0 auto 10px"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      <p style="font-size:13px;margin-bottom:12px">Envie uma mensagem direto para nossa equipe de suporte.</p>
      <a href="suporte.php?nova=1" class="btn btn-primary" style="padding:9px 18px">💬 Iniciar conversa</a>
    </div>

    <?php else: ?>
    <?php $threadOpen = isset($_GET['id']) && $openThread; ?>
    <div class="chat-wrap">

      <!-- Lista conversas -->
      <div class="chat-list <?= $threadOpen ? 'panel-hidden' : '' ?>">
        <?php if($threads): foreach($threads as $t):
          $isActive  = $openThread && (int)$openThread['id'] === (int)$t['id'];
          $hasUnread = (int)$t['unread'] > 0;
        ?>
        <a href="suporte.php?chat=1&id=<?= $t['id'] ?>" class="msg-row <?= $isActive?'active':'' ?>">
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:<?= $hasUnread?'700':'500' ?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-bottom:2px"><?= htmlspecialchars(mb_substr($t['subject'],0,28)) ?></div>
            <div style="font-size:12px;color:var(--muted2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $t['last_sender']==='admin'?'Suporte: ':'Você: ' ?><?= htmlspecialchars(mb_substr($t['last_body']??'',0,30)) ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= timeAgo($t['last_at']??$t['created_at']) ?></div>
          </div>
          <?php if($hasUnread):?><div style="width:8px;height:8px;border-radius:50%;background:var(--accent);flex-shrink:0;margin-top:4px"></div><?php endif;?>
        </a>
        <?php endforeach; else: ?>
        <div style="padding:32px 16px;text-align:center;color:var(--muted);font-size:13px">Nenhuma conversa</div>
        <?php endif;?>
      </div>

      <!-- Conteúdo principal -->
      <div class="chat-main <?= (!$threadOpen && !isset($_GET['nova'])) ? 'panel-hidden' : '' ?>">

        <?php if(isset($_GET['nova'])): ?>
        <a href="suporte.php?chat=1" class="back-btn">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Voltar
        </a>
        <div style="padding:16px;flex:1">
          <div style="font-weight:600;font-size:13px;margin-bottom:12px">✉️ Nova mensagem</div>
          <?php if($error):?><div style="background:rgba(255,77,106,.1);border:1px solid rgba(255,77,106,.3);border-radius:8px;padding:9px 12px;font-size:12px;color:var(--danger);margin-bottom:10px">❌ <?= htmlspecialchars($error) ?></div><?php endif;?>
          <form method="POST" style="display:flex;flex-direction:column;gap:10px">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="text" name="subject" class="form-control" required placeholder="Assunto..." maxlength="100" value="<?= htmlspecialchars($_POST['subject']??'') ?>" style="font-size:13px">
            <textarea name="body" rows="5" class="form-control" required placeholder="Descreva sua dúvida..." style="resize:vertical;font-size:13px" maxlength="2000"><?= htmlspecialchars($_POST['body']??'') ?></textarea>
            <div style="display:flex;gap:8px">
              <button type="submit" name="new_message" value="1" class="btn btn-primary" style="padding:8px 16px;font-size:13px">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Enviar
              </button>
              <a href="suporte.php?chat=1" class="btn btn-secondary" style="padding:8px 12px;font-size:13px">Cancelar</a>
            </div>
          </form>
        </div>

        <?php elseif($openThread): ?>
        <a href="suporte.php?chat=1" class="back-btn">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
          Voltar
        </a>
        <div style="padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px">
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:13px"><?= htmlspecialchars($openThread['subject']) ?></div>
            <div style="font-size:11px;color:var(--muted)"><?= timeAgo($openThread['created_at']) ?></div>
          </div>
          <?php if(($openThread['status']??'open')==='closed'): ?>
          <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:rgba(16,185,129,.12);color:var(--success)">✅ Concluído</span>
          <?php endif; ?>
        </div>
        <div id="chat-messages" style="flex:1;padding:14px;display:flex;flex-direction:column;gap:10px;overflow-y:auto;max-height:280px">
          <?php foreach($replies as $r): ?>
          <div class="chat-bubble-wrap" data-id="<?= $r['id'] ?>" style="display:flex;flex-direction:column;align-items:<?= $r['sender']==='user'?'flex-end':'flex-start' ?>">
            <div style="font-size:11px;color:var(--muted);margin-bottom:3px"><?= $r['sender']==='user'?'Você':'Suporte' ?> · <?= date('d/m H:i',strtotime($r['created_at'])) ?></div>
            <div class="bubble bubble-<?= $r['sender'] ?>"><?= nl2br(htmlspecialchars($r['body'])) ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if(($openThread['status']??'open')==='closed'): ?>
        <div style="padding:12px;border-top:1px solid var(--border);text-align:center;font-size:12px;color:var(--muted2)">
          Esta conversa foi concluída pela equipe de suporte.
        </div>
        <?php else: ?>
        <form method="POST" style="padding:10px 12px;border-top:1px solid var(--border);display:flex;gap:8px;align-items:flex-end">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="message_id" value="<?= $openThread['id'] ?>">
          <textarea name="body" rows="2" class="form-control" required placeholder="Responder..." style="flex:1;resize:none;font-size:13px"></textarea>
          <button type="submit" name="reply" value="1" class="btn btn-primary" style="padding:8px 12px;align-self:flex-end">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
          </button>
        </form>
        <?php endif; ?>

        <!-- Polling automático -->
        <script>
        (function(){
          var chatEl  = document.getElementById('chat-messages');
          var lastId  = <?= $replies ? max(array_column($replies,'id')) : 0 ?>;
          var lastTs  = <?= $replies ? json_encode(end($replies)['created_at']) : json_encode(date('Y-m-d H:i:s')) ?>;
          var isClosed = <?= (($openThread['status']??'open')==='closed')?'true':'false' ?>;
          var POLL_URL = <?= json_encode(SITE_URL . '/suporte-poll.php?id=' . (int)$openThread['id'] . '&after=') ?>;

          function scrollBottom(){ chatEl.scrollTop = chatEl.scrollHeight; }
          scrollBottom();
          if (isClosed) return;

          function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

          function addBubble(r){
            var isUser = r.sender === 'user';
            var wrap = document.createElement('div');
            wrap.className = 'chat-bubble-wrap';
            wrap.setAttribute('data-id', r.id);
            wrap.style.cssText = 'display:flex;flex-direction:column;align-items:'+(isUser?'flex-end':'flex-start');
            var d = new Date(r.created_at.replace(' ','T'));
            var ts = (d.getDate()<10?'0':'')+d.getDate()+'/'+(d.getMonth()<9?'0':'')+(d.getMonth()+1)+' '+(d.getHours()<10?'0':'')+d.getHours()+':'+(d.getMinutes()<10?'0':'')+d.getMinutes();
            wrap.innerHTML = '<div style="font-size:11px;color:var(--muted);margin-bottom:3px">'+(isUser?'Você':'Suporte')+' · '+ts+'</div>'
              +'<div class="bubble bubble-'+r.sender+'">'+escHtml(r.body).replace(/\n/g,'<br>')+'</div>';
            chatEl.appendChild(wrap);
            scrollBottom();
          }

          setInterval(function(){
            fetch(POLL_URL + encodeURIComponent(lastTs), {cache:'no-store'})
              .then(function(r){ return r.json(); })
              .then(function(d){
                if (d.status === 'closed') { location.reload(); return; }
                (d.replies||[]).forEach(function(r){
                  if (r.id > lastId) { addBubble(r); lastId=r.id; lastTs=r.created_at; }
                });
              }).catch(function(){});
          }, 3000);
        })();
        </script>

        <?php else: ?>
        <div style="display:flex;align-items:center;justify-content:center;flex:1;flex-direction:column;gap:8px;color:var(--muted);padding:32px">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.3"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span style="font-size:12px">Selecione ou <a href="suporte.php?nova=1" style="color:var(--accent)">inicie uma conversa</a></span>
        </div>
        <?php endif;?>

      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>

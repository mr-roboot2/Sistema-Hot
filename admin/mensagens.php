<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
requireLoginAlways();
if (!isAdmin()) { header('Location: ' . SITE_URL . '/index'); exit; }

$db = getDB();
$pageTitle = 'Mensagens';

// Garante tabelas e coluna status
try {
    $db->exec("CREATE TABLE IF NOT EXISTS support_messages (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, subject VARCHAR(200) NOT NULL, status ENUM('open','closed') DEFAULT 'open', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_sm_user (user_id), INDEX idx_sm_status (status))");
    $db->exec("CREATE TABLE IF NOT EXISTS support_replies (id INT AUTO_INCREMENT PRIMARY KEY, message_id INT NOT NULL, sender ENUM('user','admin') NOT NULL DEFAULT 'user', body TEXT NOT NULL, read_at DATETIME DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_sr_message (message_id))");
    try { $db->exec("ALTER TABLE support_messages ADD COLUMN status ENUM('open','closed') DEFAULT 'open'"); } catch(Exception $e) {}
} catch(Exception $e) {}

$msg = '';

// ── AJAX: novas mensagens (polling) ───────────
if (isset($_GET['poll']) && isset($_GET['id']) && isset($_GET['after'])) {
    header('Content-Type: application/json');
    $mid   = (int)$_GET['id'];
    $after = $_GET['after'];
    $rows  = $db->prepare("SELECT id, sender, body, created_at FROM support_replies WHERE message_id=? AND created_at > ? ORDER BY created_at ASC");
    $rows->execute([$mid, $after]);
    $st = $db->prepare('SELECT status FROM support_messages WHERE id=?');
    $st->execute([$mid]); $status = $st->fetchColumn();
    echo json_encode(['replies' => $rows->fetchAll(), 'status' => $status]);
    exit;
}

// ── Fechar/reabrir conversa ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $mid    = (int)($_POST['message_id'] ?? 0);
    $cur    = $db->prepare('SELECT status FROM support_messages WHERE id=?'); $cur->execute([$mid]); $cur=$cur->fetchColumn();
    $newSt  = $cur === 'closed' ? 'open' : 'closed';
    $db->prepare('UPDATE support_messages SET status=? WHERE id=?')->execute([$newSt, $mid]);
    auditLog('support_'.$newSt, 'message:'.$mid);
    header('Location: ' . SITE_URL . '/admin/mensagens.php?id='.$mid); exit;
}

// ── Responder ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $mid  = (int)($_POST['message_id'] ?? 0);
    $body = trim($_POST['body'] ?? '');
    if ($mid && $body) {
        $db->prepare('INSERT INTO support_replies (message_id, sender, body) VALUES (?,?,?)')->execute([$mid,'admin',$body]);
        if (!empty($_POST['notify_email'])) {
            $info = $db->prepare('SELECT sm.subject, u.name, u.email FROM support_messages sm JOIN users u ON u.id=sm.user_id WHERE sm.id=?');
            $info->execute([$mid]); $info=$info->fetch();
            if ($info && $info['email']) {
                $site = getSetting('site_name', SITE_NAME);
                $html = "<div style='font-family:sans-serif;max-width:600px;padding:24px;background:#0a0a0f;color:#e8e8f0;border-radius:12px'>
                    <h2 style='color:#7c6aff'>💬 Resposta do suporte — {$site}</h2>
                    <p style='color:#9090a8'>Olá <b style='color:#e8e8f0'>{$info['name']}</b>, você recebeu uma resposta para <b>{$info['subject']}</b>:</p>
                    <div style='background:#13131a;border-radius:8px;padding:16px;margin:16px 0;font-size:14px;line-height:1.7;color:#c8c8d8'>" . nl2br(htmlspecialchars($body)) . "</div>
                    <a href='" . SITE_URL . "/suporte.php?chat=1&id={$mid}' style='display:inline-block;background:#7c6aff;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700'>Ver conversa</a>
                </div>";
                sendMail($info['email'], "Resposta do suporte: {$info['subject']} — {$site}", $html);
            }
        }
        auditLog('support_reply', 'message:'.$mid.(!empty($_POST['notify_email'])?' +email':''));
        $notified = !empty($_POST['notify_email']);
        header('Location: ' . SITE_URL . '/admin/mensagens.php?id='.$mid.'&replied=1&emailed='.($notified?'1':'0')); exit;
    }
}

// ── Deletar thread ────────────────────────────
if (isset($_GET['delete']) && csrf_verify($_GET['csrf_token'] ?? '')) {
    $mid = (int)$_GET['delete'];
    $db->prepare('DELETE FROM support_replies WHERE message_id=?')->execute([$mid]);
    $db->prepare('DELETE FROM support_messages WHERE id=?')->execute([$mid]);
    header('Location: ' . SITE_URL . '/admin/mensagens.php'); exit;
}

// ── Marcar lidas e abrir thread ───────────────
$openThread = null; $replies = [];
if (isset($_GET['id'])) {
    $mid = (int)$_GET['id'];
    $db->prepare('UPDATE support_replies SET read_at=NOW() WHERE message_id=? AND sender="user" AND read_at IS NULL')->execute([$mid]);
    $ot = $db->prepare('SELECT sm.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone FROM support_messages sm JOIN users u ON u.id=sm.user_id WHERE sm.id=?');
    $ot->execute([$mid]); $openThread = $ot->fetch();
    if ($openThread) {
        $r = $db->prepare('SELECT * FROM support_replies WHERE message_id=? ORDER BY created_at ASC');
        $r->execute([$mid]); $replies = $r->fetchAll();
    }
}

// ── Lista threads ─────────────────────────────
$filterStatus = in_array($_GET['filter']??'', ['open','closed','unread']) ? $_GET['filter'] : 'open';
$page    = max(1,(int)($_GET['page']??1));
$perPage = 20; $offset = ($page-1)*$perPage;

$where = match($filterStatus) {
    'unread' => 'WHERE EXISTS (SELECT 1 FROM support_replies r WHERE r.message_id=sm.id AND r.sender="user" AND r.read_at IS NULL)',
    'closed' => 'WHERE sm.status="closed"',
    default  => 'WHERE sm.status="open"',
};

$total = $db->query("SELECT COUNT(*) FROM support_messages sm $where")->fetchColumn();
$pages = (int)ceil($total/$perPage);

$threads = $db->prepare("
    SELECT sm.*, u.name AS user_name,
           (SELECT body FROM support_replies WHERE message_id=sm.id ORDER BY created_at DESC LIMIT 1) AS last_body,
           (SELECT created_at FROM support_replies WHERE message_id=sm.id ORDER BY created_at DESC LIMIT 1) AS last_at,
           (SELECT sender FROM support_replies WHERE message_id=sm.id ORDER BY created_at DESC LIMIT 1) AS last_sender,
           (SELECT COUNT(*) FROM support_replies WHERE message_id=sm.id AND sender='user' AND read_at IS NULL) AS unread
    FROM support_messages sm JOIN users u ON u.id=sm.user_id
    $where ORDER BY last_at DESC, sm.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$threads->execute(); $threads = $threads->fetchAll();

$counts = $db->query("SELECT
    SUM(status='open') AS open_count,
    SUM(status='closed') AS closed_count,
    (SELECT COUNT(DISTINCT message_id) FROM support_replies WHERE sender='user' AND read_at IS NULL) AS unread_count
FROM support_messages")->fetch();

require __DIR__ . '/../includes/header.php';
?>
<style>
.msg-row{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;transition:background .15s}
.msg-row:hover,.msg-row.active{background:var(--surface2)}
.msg-row.active{border-left:3px solid var(--accent)}
.bubble{padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.6;white-space:pre-wrap;word-break:break-word;max-width:82%}
.bubble-user{background:var(--surface2);border-radius:12px 12px 12px 2px;align-self:flex-start}
.bubble-admin{background:rgba(124,106,255,.15);border:1px solid rgba(124,106,255,.2);border-radius:12px 12px 2px 12px;align-self:flex-end}
.filter-tab{padding:10px 14px;font-size:12px;font-weight:600;text-decoration:none;border-bottom:2px solid transparent;color:var(--muted);white-space:nowrap}
.filter-tab.active{color:var(--accent);border-color:var(--accent)}
</style>

<?php if(isset($_GET['replied'])):?>
<div class="alert alert-success" style="margin-bottom:14px">
  ✅ Resposta enviada<?= ($_GET['emailed']??'0')==='1'?' e usuário notificado por e-mail.':' (sem e-mail).' ?>
</div>
<?php endif;?>

<div style="display:grid;grid-template-columns:300px 1fr;border:1px solid var(--border);border-radius:12px;overflow:hidden;min-height:560px">

<!-- ── Sidebar ── -->
<div style="border-right:1px solid var(--border);display:flex;flex-direction:column">
  <!-- Filtros -->
  <div style="display:flex;border-bottom:1px solid var(--border);overflow-x:auto">
    <a href="?filter=open<?= isset($_GET['id'])?'&id='.(int)$_GET['id']:'' ?>" class="filter-tab <?= $filterStatus==='open'?'active':'' ?>">
      Abertos <?php if($counts['open_count']):?><span style="background:var(--surface2);font-size:10px;padding:1px 5px;border-radius:10px"><?= $counts['open_count'] ?></span><?php endif;?>
    </a>
    <a href="?filter=unread<?= isset($_GET['id'])?'&id='.(int)$_GET['id']:'' ?>" class="filter-tab <?= $filterStatus==='unread'?'active':'' ?>">
      Não lidos <?php if($counts['unread_count']):?><span style="background:var(--accent);color:#fff;font-size:10px;padding:1px 5px;border-radius:10px"><?= $counts['unread_count'] ?></span><?php endif;?>
    </a>
    <a href="?filter=closed<?= isset($_GET['id'])?'&id='.(int)$_GET['id']:'' ?>" class="filter-tab <?= $filterStatus==='closed'?'active':'' ?>">
      Concluídos <?php if($counts['closed_count']):?><span style="background:var(--surface2);font-size:10px;padding:1px 5px;border-radius:10px"><?= $counts['closed_count'] ?></span><?php endif;?>
    </a>
  </div>

  <!-- Lista -->
  <div style="flex:1;overflow-y:auto">
  <?php if($threads): foreach($threads as $t):
    $isActive  = $openThread && (int)$openThread['id']===(int)$t['id'];
    $hasUnread = (int)$t['unread'] > 0;
    $initials  = strtoupper(mb_substr($t['user_name'],0,1));
  ?>
  <a href="?id=<?= $t['id'] ?>&filter=<?= $filterStatus ?>" class="msg-row <?= $isActive?'active':'' ?>">
    <div style="width:34px;height:34px;border-radius:50%;background:<?= $t['status']==='closed'?'var(--surface2)':'rgba(124,106,255,.15)' ?>;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:<?= $t['status']==='closed'?'var(--muted)':'var(--accent)' ?>;flex-shrink:0">
      <?= $initials ?>
    </div>
    <div style="flex:1;min-width:0">
      <div style="font-size:12px;font-weight:<?= $hasUnread?'700':'500' ?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($t['user_name']) ?></div>
      <div style="font-size:11px;color:var(--muted2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars(mb_substr($t['last_body']??$t['subject'],0,38)) ?></div>
      <div style="font-size:10px;color:var(--muted);margin-top:1px"><?= timeAgo($t['last_at']??$t['created_at']) ?></div>
    </div>
    <?php if($hasUnread):?><div style="width:7px;height:7px;border-radius:50%;background:var(--accent);flex-shrink:0;margin-top:4px"></div><?php endif;?>
  </a>
  <?php endforeach; else: ?>
  <div style="padding:40px;text-align:center;color:var(--muted);font-size:13px">
    <div style="font-size:28px;margin-bottom:8px"><?= $filterStatus==='closed'?'✅':'💬' ?></div>
    <?= $filterStatus==='closed'?'Nenhuma conversa concluída':'Nenhuma mensagem' ?>
  </div>
  <?php endif;?>
  </div>
  <?php if($pages>1): echo renderPagination($page,$pages,'?filter='.$filterStatus.'&'.http_build_query(array_diff_key($_GET,['page'=>'','id'=>'']))); endif;?>
</div>

<!-- ── Thread ── -->
<div style="display:flex;flex-direction:column">
<?php if($openThread): ?>

  <!-- Header thread -->
  <div style="padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px">
        <?= htmlspecialchars($openThread['subject']) ?>
        <span style="font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;background:<?= $openThread['status']==='closed'?'rgba(16,185,129,.15)':'rgba(245,158,11,.15)' ?>;color:<?= $openThread['status']==='closed'?'var(--success)':'var(--warning)' ?>">
          <?= $openThread['status']==='closed'?'✅ Concluído':'🔓 Aberto' ?>
        </span>
      </div>
      <div style="font-size:11px;color:var(--muted2);margin-top:2px">
        <?= htmlspecialchars($openThread['user_name']) ?>
        <?= $openThread['user_email']?' · '.htmlspecialchars($openThread['user_email']):'' ?>
        <?= $openThread['user_phone']?' · '.htmlspecialchars($openThread['user_phone']):'' ?>
      </div>
    </div>
    <!-- Fechar/reabrir -->
    <form method="POST" style="display:flex;gap:6px">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="message_id" value="<?= $openThread['id'] ?>">
      <button type="submit" name="toggle_status" value="1" class="btn btn-secondary"
              style="padding:5px 12px;font-size:12px;color:<?= $openThread['status']==='closed'?'var(--warning)':'var(--success)' ?>;border-color:<?= $openThread['status']==='closed'?'rgba(245,158,11,.3)':'rgba(16,185,129,.3)' ?>">
        <?= $openThread['status']==='closed'?'🔓 Reabrir':'✅ Concluir' ?>
      </button>
    </form>
    <a href="?delete=<?= $openThread['id'] ?>&csrf_token=<?= csrf_token() ?>&filter=<?= $filterStatus ?>"
       onclick="return confirm('Apagar conversa?')"
       style="font-size:12px;color:var(--danger);text-decoration:none;padding:5px 10px;border:1px solid rgba(255,77,106,.3);border-radius:6px">🗑️</a>
  </div>

  <!-- Mensagens -->
  <div id="chat-messages" style="flex:1;padding:16px;display:flex;flex-direction:column;gap:10px;overflow-y:auto;max-height:360px">
  <?php foreach($replies as $r): ?>
    <div class="chat-bubble-wrap" style="display:flex;flex-direction:column;align-items:<?= $r['sender']==='admin'?'flex-end':'flex-start' ?>" data-id="<?= $r['id'] ?>">
      <div style="font-size:10px;color:var(--muted);margin-bottom:3px"><?= $r['sender']==='admin'?'Você':'Suporte: '.htmlspecialchars($openThread['user_name']) ?> · <?= date('d/m H:i',strtotime($r['created_at'])) ?></div>
      <div class="bubble bubble-<?= $r['sender'] ?>"><?= nl2br(htmlspecialchars($r['body'])) ?></div>
    </div>
  <?php endforeach; ?>
  </div>

  <!-- Responder -->
  <?php if($openThread['status'] === 'open'): ?>
  <form method="POST" style="padding:12px 14px;border-top:1px solid var(--border)">
    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
    <input type="hidden" name="message_id" value="<?= $openThread['id'] ?>">
    <textarea name="body" id="reply-body" rows="3" class="form-control" required
              placeholder="Digite sua resposta..."
              style="width:100%;resize:none;font-size:13px;margin-bottom:8px"></textarea>
    <div style="display:flex;align-items:center;justify-content:flex-end">
      <button type="submit" name="reply" value="1" class="btn btn-primary" style="padding:8px 16px;font-size:13px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        Enviar resposta
      </button>
    </div>
  </form>
  <?php else: ?>
  <div style="padding:14px;border-top:1px solid var(--border);text-align:center;font-size:13px;color:var(--muted2)">
    Conversa concluída ·
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="message_id" value="<?= $openThread['id'] ?>">
      <button type="submit" name="toggle_status" value="1" style="background:none;border:none;color:var(--accent);cursor:pointer;font-size:13px;padding:0">Reabrir para responder</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Polling automático -->
  <?php if($openThread['status'] === 'open'): ?>
  <script>
  (function(){
    var chatEl = document.getElementById('chat-messages');
    var lastId  = <?= $replies ? max(array_column($replies,'id')) : 0 ?>;
    var lastTs  = <?= $replies ? json_encode(end($replies)['created_at']) : json_encode(date('Y-m-d H:i:s')) ?>;
    var threadId = <?= (int)$openThread['id'] ?>;
    var POLL_URL = <?= json_encode(SITE_URL . '/admin/mensagens.php?poll=1&id=' . (int)$openThread['id'] . '&after=') ?>;

    function scrollBottom(){ chatEl.scrollTop = chatEl.scrollHeight; }
    scrollBottom();

    function addBubble(r) {
      var isAdmin = r.sender === 'admin';
      var wrap = document.createElement('div');
      wrap.className = 'chat-bubble-wrap';
      wrap.setAttribute('data-id', r.id);
      wrap.style.cssText = 'display:flex;flex-direction:column;align-items:' + (isAdmin?'flex-end':'flex-start');
      var d = new Date(r.created_at.replace(' ','T'));
      var ts = (d.getDate()<10?'0':'')+d.getDate()+'/'+(d.getMonth()<9?'0':'')+(d.getMonth()+1)+' '+(d.getHours()<10?'0':'')+d.getHours()+':'+(d.getMinutes()<10?'0':'')+d.getMinutes();
      wrap.innerHTML = '<div style="font-size:10px;color:var(--muted);margin-bottom:3px">' + (isAdmin?'Você':'Usuário') + ' · ' + ts + '</div>'
        + '<div class="bubble bubble-' + r.sender + '">' + r.body.replace(/\n/g,'<br>').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
      chatEl.appendChild(wrap);
      scrollBottom();
    }

    setInterval(function(){
      fetch(POLL_URL + encodeURIComponent(lastTs), {cache:'no-store'})
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (d.status === 'closed') {
            location.reload(); return;
          }
          (d.replies || []).forEach(function(r){
            if (r.id > lastId) {
              addBubble(r);
              lastId = r.id;
              lastTs = r.created_at;
            }
          });
        }).catch(function(){});
    }, 3000);
  })();
  </script>
  <?php endif; ?>

<?php else: ?>
  <div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);flex-direction:column;gap:10px;padding:60px">
    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.3"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
    <span style="font-size:13px">Selecione uma conversa</span>
  </div>
<?php endif; ?>
</div>

</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

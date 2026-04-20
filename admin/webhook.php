<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';
requireLoginAlways();
if ((currentUser()['role'] ?? '') !== 'admin') { header('Location: ' . SITE_URL . '/index'); exit; }

$db        = getDB();
$pageTitle = 'Webhook';
$message   = '';

// Garante tabela e colunas
try {
    $db->exec("CREATE TABLE IF NOT EXISTS webhook_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event VARCHAR(100), telegram_id VARCHAR(50), external_id VARCHAR(100),
        plan_name VARCHAR(100), amount DECIMAL(10,2), gateway VARCHAR(50),
        user_id INT DEFAULT NULL, user_created TINYINT(1) DEFAULT 0,
        status VARCHAR(20), error_msg VARCHAR(500),
        payload LONGTEXT, ip VARCHAR(45),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    foreach (['telegram_id VARCHAR(50) DEFAULT NULL','telegram_username VARCHAR(100) DEFAULT NULL','webhook_source VARCHAR(100) DEFAULT NULL'] as $col) {
        try { $db->exec("ALTER TABLE users ADD COLUMN $col"); } catch(Exception $e) {}
    }
    $ins = $db->prepare("INSERT IGNORE INTO settings (key_name,value,label,type) VALUES (?,?,?,?)");
    $ins->execute(['webhook_secret',       '',  'Webhook Secret',                        'text']);
    $ins->execute(['webhook_default_plan', '',  'Plano padrão (ID)',                     'text']);
    $ins->execute(['webhook_send_email',   '1', 'Enviar e-mail de boas-vindas via webhook','toggle']);
} catch(Exception $e) {}

// Salvar configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cfg']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $upd = $db->prepare('UPDATE settings SET value=? WHERE key_name=?');
    // secret
    $secret = trim($_POST['webhook_secret'] ?? '');
    $upd->execute([$secret, 'webhook_secret']);
    // plano padrão
    $upd->execute([trim($_POST['webhook_default_plan'] ?? ''), 'webhook_default_plan']);
    // toggle email
    $upd->execute([isset($_POST['webhook_send_email']) ? '1' : '0', 'webhook_send_email']);
    $message = '✅ Configurações salvas!';
}

// Gerar novo secret
if (isset($_POST['gen_secret']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $secret = bin2hex(random_bytes(24));
    $db->prepare('UPDATE settings SET value=? WHERE key_name=?')->execute([$secret, 'webhook_secret']);
    $message = '🔑 Novo secret gerado!';
}

// Limpar logs
if (isset($_POST['clear_logs']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $db->exec('TRUNCATE TABLE webhook_logs');
    $message = '🗑️ Logs apagados.';
}

// Reprocessar webhook com erro
if (isset($_POST['retry_webhook']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $logId = (int)($_POST['log_id'] ?? 0);
    if ($logId) {
        $log = $db->prepare('SELECT * FROM webhook_logs WHERE id=?');
        $log->execute([$logId]); $log = $log->fetch();
        if ($log && $log['payload']) {
            // Reenvia o payload para o webhook
            $ch = curl_init(SITE_URL . '/webhook.php');
            $secret = getSetting('webhook_secret', '');
            $headers = ['Content-Type: application/json'];
            if ($secret) $headers[] = 'X-Webhook-Token: ' . $secret;
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $log['payload'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $result = curl_exec($ch);
            $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $message = $code === 200 ? '✅ Webhook reprocessado com sucesso!' : "⚠️ Retorno HTTP {$code}: " . htmlspecialchars($result ?: 'sem resposta');
        }
    }
}

// Testar webhook (simula payload)
$testResult = null;
if (isset($_POST['test_webhook']) && csrf_verify($_POST['csrf_token'] ?? '')) {
    $testPayload = [
        'event'    => 'payment_approved',
        'timestamp'=> date('c'),
        'customer' => ['telegram_id'=>'999999999','first_name'=>'Teste','last_name'=>'Webhook','username'=>'testwebhook'],
        'bot'      => ['id'=>'bot_test','username'=>'TestBot'],
        'flow'     => ['id'=>'flow_test','name'=>'Teste'],
        'transaction' => [
            'id'             => 'test_'.time(),
            'external_id'    => 'ext_test_'.time(),
            'status'         => 'paid',
            'amount'         => 97,
            'currency'       => 'BRL',
            'gateway'        => 'test',
            'plan_name'      => $_POST['test_plan'] ?? 'Plano Teste',
            'plan_id'        => 'plan_test',
            'payment_method' => 'pix',
            'created_at'     => date('c'),
            'paid_at'        => date('c'),
        ],
    ];
    // Chama o webhook internamente
    $webhookUrl = SITE_URL . '/webhook.php';
    $secret     = getSetting('webhook_secret','');
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($testPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            $secret ? "X-Webhook-Secret: {$secret}" : 'X-Test: 1',
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $resp    = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $testResult = ['code' => $code, 'body' => $resp ?: $curlErr, 'payload' => json_encode($testPayload, JSON_PRETTY_PRINT)];
}

// Dados
$cfgSecret      = getSetting('webhook_secret','');
$cfgDefaultPlan = getSetting('webhook_default_plan','');
$cfgSendEmail   = getSetting('webhook_send_email','1') === '1';
$plans          = $db->query('SELECT id, name, duration_days FROM plans WHERE active=1 ORDER BY duration_days')->fetchAll();

// Logs com paginação
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;
$total   = (int)$db->query('SELECT COUNT(*) FROM webhook_logs')->fetchColumn();
$pages   = (int)ceil($total / $perPage);

$logs = $db->prepare('
    SELECT l.*, u.name AS user_name
    FROM webhook_logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.created_at DESC
    LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$logs->execute();
$logs = $logs->fetchAll();

// Stats
$stats = [
    'total'    => $total,
    'ok'       => $db->query('SELECT COUNT(*) FROM webhook_logs WHERE status="ok"')->fetchColumn(),
    'created'  => $db->query('SELECT COUNT(*) FROM webhook_logs WHERE user_created=1')->fetchColumn(),
    'errors'   => $db->query('SELECT COUNT(*) FROM webhook_logs WHERE status="error"')->fetchColumn(),
    'duplicate'=> $db->query('SELECT COUNT(*) FROM webhook_logs WHERE status="duplicate"')->fetchColumn(),
];

require __DIR__ . '/../includes/header.php';
?>
<style>
.wh-url{font-family:monospace;font-size:13px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 14px;word-break:break-all;display:flex;align-items:center;justify-content:space-between;gap:10px}
.wh-url span{flex:1}
.copy-btn{flex-shrink:0;padding:5px 12px;font-size:12px}
.log-row td{padding:10px 12px;font-size:13px;border-bottom:1px solid var(--border);vertical-align:middle}
.log-row:last-child td{border:none}
.log-row:hover td{background:var(--surface2)}
.status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700}
.s-ok       {background:rgba(16,185,129,.15);color:var(--success)}
.s-error    {background:rgba(255,77,106,.15);color:var(--danger)}
.s-duplicate{background:rgba(245,158,11,.15);color:var(--warning)}
.s-ignored  {background:rgba(107,107,128,.15);color:var(--muted)}
.payload-pre{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:12px;font-size:11px;font-family:monospace;max-height:200px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;color:var(--muted2)}
.tog-wrap{position:relative;display:inline-block;width:44px;height:24px;cursor:pointer}
.tog-wrap input{opacity:0;width:0;height:0;position:absolute}
.tog-track{position:absolute;inset:0;border-radius:24px;background:var(--surface3);border:1px solid var(--border2);transition:.2s}
.tog-thumb{position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:.2s}
.tog-wrap input:checked~.tog-track{background:var(--success);border-color:var(--success)}
.tog-wrap input:checked~.tog-thumb{left:23px}
</style>

<?php if ($message): ?>
<div class="alert alert-success" style="margin-bottom:20px"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 360px;gap:24px;align-items:start">

<!-- LOGS -->
<div>
  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
    <?php foreach ([
      ['Total',      $stats['total'],     'var(--muted)'],
      ['Processados',$stats['ok'],        'var(--success)'],
      ['Criados',    $stats['created'],   'var(--accent)'],
      ['Erros',      $stats['errors'],    'var(--danger)'],
    ] as [$lbl,$val,$color]): ?>
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;text-align:center">
      <div style="font-family:'Roboto',sans-serif;font-size:24px;font-weight:800;color:<?= $color ?>"><?= number_format($val) ?></div>
      <div style="font-size:12px;color:var(--muted);margin-top:3px"><?= $lbl ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- URL do webhook -->
  <div style="margin-bottom:20px">
    <div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">URL do Webhook</div>
    <div class="wh-url">
      <span id="wh-url-text"><?= SITE_URL ?>/webhook.php</span>
      <button class="btn btn-secondary copy-btn" onclick="copyUrl()">📋 Copiar</button>
    </div>
  </div>

  <!-- Teste rápido -->
  <?php if ($testResult): ?>
  <div class="card" style="padding:20px;margin-bottom:20px;border-color:<?= $testResult['code']===201||$testResult['code']===200 ? 'rgba(16,185,129,.3)' : 'rgba(255,77,106,.3)' ?>">
    <div style="font-size:14px;font-weight:700;margin-bottom:12px">
      Resultado do Teste — HTTP <?= $testResult['code'] ?>
      <?= in_array($testResult['code'],[200,201]) ? '<span style="color:var(--success)">✅ Sucesso</span>' : '<span style="color:var(--danger)">❌ Falhou</span>' ?>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-bottom:6px">Resposta:</div>
    <pre class="payload-pre"><?= htmlspecialchars(json_encode(json_decode($testResult['body']),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
  </div>
  <?php endif; ?>

  <!-- Tabela de logs -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
    <div style="font-family:'Roboto',sans-serif;font-size:16px;font-weight:700">
      Logs de Recebimento
      <span class="badge badge-accent" style="font-size:12px"><?= $total ?></span>
    </div>
    <form method="POST" onsubmit="return confirm('Apagar todos os logs?')">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="clear_logs" value="1">
      <button type="submit" class="btn btn-secondary" style="font-size:12px;padding:6px 12px">🗑️ Limpar logs</button>
    </form>
  </div>

  <div class="card" style="overflow:hidden">
    <?php if ($logs): ?>
    <table style="width:100%;border-collapse:collapse">
      <thead>
        <tr style="border-bottom:1px solid var(--border)">
          <th style="padding:10px 12px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">DATA</th>
          <th style="padding:10px 12px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">EVENTO</th>
          <th style="padding:10px 12px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">USUÁRIO</th>
          <th style="padding:10px 12px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">PLANO/VALOR</th>
          <th style="padding:10px 12px;text-align:left;font-size:11px;color:var(--muted);font-weight:600">STATUS</th>
          <th style="padding:10px 12px;font-size:11px;color:var(--muted);font-weight:600"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <tr class="log-row">
          <td style="color:var(--muted);white-space:nowrap">
            <?= date('d/m/y H:i', strtotime($log['created_at'])) ?>
          </td>
          <td>
            <span style="font-size:12px;font-weight:600"><?= htmlspecialchars($log['event']) ?></span>
            <?php if ($log['user_created']): ?>
            <span style="font-size:10px;background:rgba(124,106,255,.15);color:var(--accent);border-radius:10px;padding:1px 6px;margin-left:4px">NOVO</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($log['user_name']): ?>
            <div style="font-size:13px;font-weight:500"><?= htmlspecialchars($log['user_name']) ?></div>
            <?php endif; ?>
            <div class="txt-muted-xs">
              <?php if ($log['telegram_id']): ?>tg: <?= htmlspecialchars($log['telegram_id']) ?><?php endif; ?>
            </div>
          </td>
          <td>
            <?php if ($log['plan_name']): ?>
            <div style="font-size:12px;font-weight:600"><?= htmlspecialchars($log['plan_name']) ?></div>
            <?php endif; ?>
            <?php if ($log['amount']): ?>
            <div class="txt-muted-xs">R$ <?= number_format($log['amount'],2,',','.') ?> · <?= htmlspecialchars($log['gateway'] ?? '') ?></div>
            <?php endif; ?>
          </td>
          <td>
            <span class="status-badge s-<?= $log['status'] ?>"><?= strtoupper($log['status']) ?></span>
            <?php if ($log['error_msg']): ?>
            <div style="font-size:11px;color:var(--danger);margin-top:3px"><?= htmlspecialchars($log['error_msg']) ?></div>
            <?php endif; ?>
          </td>
          <td style="text-align:right;display:flex;gap:4px;justify-content:flex-end">
            <?php if ($log['status'] === 'error' && !empty($log['payload'])): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
              <input type="hidden" name="retry_webhook" value="1">
              <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
              <button type="submit" class="btn btn-secondary" style="padding:4px 8px;font-size:11px;color:var(--warning)"
                      onclick="return confirm('Reprocessar este webhook?')">↺ Retry</button>
            </form>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" style="padding:4px 8px;font-size:11px"
                    onclick="togglePayload(<?= $log['id'] ?>)">JSON</button>
          </td>
        </tr>
        <!-- Payload expandível -->
        <tr id="payload-<?= $log['id'] ?>" style="display:none">
          <td colspan="6" style="padding:0 12px 12px">
            <pre class="payload-pre"><?= htmlspecialchars(json_encode(json_decode($log['payload'] ?? '{}'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <?php echo renderPagination($page, $pages, '?'); ?>

    <?php else: ?>
    <div style="padding:40px;text-align:center;color:var(--muted)">
      <div style="font-size:32px;margin-bottom:10px">📭</div>
      Nenhum webhook recebido ainda.
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- CONFIG -->
<div>
  <!-- Configurações -->
  <div class="card" style="padding:22px;margin-bottom:16px">
    <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;margin-bottom:16px">⚙️ Configurações</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="save_cfg" value="1">

      <div class="form-group">
        <label class="form-label">Secret Token
          <span style="font-size:11px;color:var(--muted);font-weight:400">(header X-Webhook-Secret)</span>
        </label>
        <div style="display:flex;gap:6px">
          <input type="text" name="webhook_secret" class="form-control"
                 value="<?= htmlspecialchars($cfgSecret) ?>"
                 placeholder="Deixe vazio para sem autenticação"
                 style="font-family:monospace;font-size:12px">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Plano padrão</label>
        <select name="webhook_default_plan" class="form-control">
          <option value="">— Sem plano padrão —</option>
          <?php foreach ($plans as $pl): ?>
          <option value="<?= $pl['id'] ?>" <?= $cfgDefaultPlan == $pl['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($pl['name']) ?> (<?= $pl['duration_days'] ?>d)
          </option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--muted);margin-top:5px">Usado quando o plano do payload não corresponde a nenhum cadastrado.</div>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-top:1px solid var(--border);margin-top:4px">
        <div>
          <div style="font-size:14px;font-weight:500">E-mail de boas-vindas</div>
          <div class="txt-muted-sm">Envia credenciais ao criar usuário</div>
        </div>
        <label class="tog-wrap">
          <input type="checkbox" name="webhook_send_email" value="1" <?= $cfgSendEmail?'checked':'' ?>>
          <span class="tog-track"></span><span class="tog-thumb"></span>
        </label>
      </div>

      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center">Salvar</button>
        <form method="POST" style="flex:1">
          <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
          <input type="hidden" name="gen_secret" value="1">
          <button type="submit" class="btn btn-secondary" style="width:100%;justify-content:center">🔑 Gerar secret</button>
        </form>
      </div>
    </form>
  </div>

  <!-- Teste -->
  <div class="card" style="padding:22px;margin-bottom:16px">
    <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;margin-bottom:14px">🧪 Testar Webhook</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="test_webhook" value="1">
      <div class="form-group">
        <label class="form-label">Nome do plano no teste</label>
        <input type="text" name="test_plan" class="form-control" value="Plano Premium" placeholder="Plano Premium">
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">
        ▶ Executar teste
      </button>
    </form>
    <div style="font-size:11px;color:var(--muted);margin-top:10px;line-height:1.6">
      Simula um pagamento com telegram_id <code>999999999</code>.
      Cria ou renova o usuário de teste.
    </div>
  </div>

  <!-- Documentação -->
  <div class="card" style="padding:22px">
    <div style="font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;margin-bottom:14px">📖 Como integrar</div>
    <div style="font-size:13px;color:var(--muted);line-height:1.8">
      <b style="color:var(--text)">Método:</b> POST<br>
      <b style="color:var(--text)">URL:</b> <code style="font-size:11px"><?= SITE_URL ?>/webhook.php</code><br>
      <b style="color:var(--text)">Header:</b> <code style="font-size:11px">X-Webhook-Secret: {secret}</code><br>
      <b style="color:var(--text)">Content-Type:</b> <code style="font-size:11px">application/json</code>
      <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
      <b style="color:var(--text)">Eventos processados:</b><br>
      <code style="font-size:11px">payment_approved</code><br>
      <code style="font-size:11px">purchase_approved</code><br>
      <code style="font-size:11px">payment_confirmed</code><br>
      <code style="font-size:11px">order_paid</code>
      <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
      <b style="color:var(--text)">Campo obrigatório:</b><br>
      <code style="font-size:11px">customer.telegram_id</code>
      <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
      <b style="color:var(--text)">Resposta — novo usuário (201):</b><br>
      <code style="font-size:11px">credentials.email + credentials.password</code>
    </div>
  </div>
</div>
</div>

<script>
function copyUrl() {
  navigator.clipboard.writeText(document.getElementById('wh-url-text').textContent)
    .then(() => { const b = event.target; b.textContent='✅ Copiado!'; setTimeout(()=>b.textContent='📋 Copiar',2000); });
}
function togglePayload(id) {
  const el = document.getElementById('payload-'+id);
  el.style.display = el.style.display === 'none' ? '' : 'none';
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>

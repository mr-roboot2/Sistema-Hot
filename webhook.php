<?php
/**
 * webhook.php — Recebe eventos de pagamento e cria/atualiza usuários
 * URL: http://seusite.com/cms/webhook.php
 * Método: POST (JSON)
 */

require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');

// ── Só aceita POST ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Lê body JSON ─────────────────────────────
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

if (!$data || !is_array($data)) {
    logWebhook('unknown', null, null, null, null, null, 'error', 'JSON inválido', $rawBody);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// ── Verificação de segurança ───
// Aceita 2 modos (não-exclusivos):
//   1) HMAC-SHA256 do body — header X-Webhook-Signature (preferido, prova integridade)
//   2) Shared secret — header X-Webhook-Secret / Authorization: Bearer … (legado)
$secret = getSetting('webhook_secret', '');
if ($secret !== '') {
    $sigHeader   = trim($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');
    $headerToken = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $headerToken = preg_replace('/^Bearer\s+/i', '', trim($headerToken));

    // Normaliza prefixos comuns (sha256=...)
    $sigHeader = preg_replace('/^sha256=/i', '', $sigHeader);

    $ok = false;
    if ($sigHeader !== '') {
        $expected = hash_hmac('sha256', $rawBody, $secret);
        $ok = hash_equals($expected, $sigHeader);
    } elseif ($headerToken !== '') {
        $ok = hash_equals($secret, $headerToken);
    }

    if (!$ok) {
        logWebhook($data['event'] ?? 'unknown', null, null, null, null, null, 'error', 'Token/assinatura inválido', $rawBody);
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// ── Extrai campos do payload ──────────────────
$customer    = is_array($data['customer'] ?? null)    ? $data['customer']    : [];
$transaction = is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
$event       = $data['event']                 ?? '';
$telegramId  = (string)($customer['telegram_id'] ?? '');
$firstName   = trim((string)($customer['first_name'] ?? ''));
$lastName    = trim((string)($customer['last_name']  ?? ''));
$username    = trim((string)($customer['username']   ?? ''));
$externalId  = $transaction['id']             ?? ($transaction['external_id'] ?? '');
$planName    = $transaction['plan_name']       ?? '';
$planId      = $transaction['plan_id']         ?? '';
$amount      = isset($transaction['amount']) ? (float)$transaction['amount'] : null;
$gateway     = $transaction['gateway']         ?? '';
$status      = $transaction['status']          ?? '';

// ── Só processa pagamentos aprovados ─────────
$approvedEvents = ['payment_approved', 'purchase_approved', 'payment_confirmed', 'order_paid'];
if (!in_array($event, $approvedEvents)) {
    logWebhook($event, $telegramId, $externalId, $planName, $amount, $gateway, 'ignored', "Evento '{$event}' ignorado", $rawBody);
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'message' => "Evento '{$event}' não processado"]);
    exit;
}

if ($status !== 'paid' && !empty($status)) {
    logWebhook($event, $telegramId, $externalId, $planName, $amount, $gateway, 'ignored', "Status '{$status}' não é paid", $rawBody);
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'message' => "Status '{$status}' não processado"]);
    exit;
}

if (!$telegramId) {
    logWebhook($event, null, $externalId, $planName, $amount, $gateway, 'error', 'telegram_id ausente', $rawBody);
    http_response_code(422);
    echo json_encode(['error' => 'telegram_id is required']);
    exit;
}

// ── Previne duplicatas (mesmo external_id) ────
if ($externalId) {
    $db   = getDB();
    $dupl = $db->prepare('SELECT id FROM webhook_logs WHERE external_id=? AND status IN ("ok","duplicate") LIMIT 1');
    $dupl->execute([$externalId]);
    if ($dupl->fetch()) {
        logWebhook($event, $telegramId, $externalId, $planName, $amount, $gateway, 'duplicate', 'Transação já processada', $rawBody);
        http_response_code(200);
        echo json_encode(['status' => 'duplicate', 'message' => 'Transação já processada anteriormente']);
        exit;
    }
}

// ── Monta nome completo e e-mail ──────────────
$db       = getDB();
$fullName = trim("$firstName $lastName") ?: ($username ?: "User_{$telegramId}");
// E-mail gerado a partir do telegram_id (único)
$email    = "tg_{$telegramId}@webhook.local";

// ── Busca plano correspondente ────────────────
$plan        = null;
$defaultPlan = getSetting('webhook_default_plan', '');

// 1. Tenta pelo plan_id do payload
if ($planId) {
    $ps = $db->prepare("SELECT id,name,duration_days,price,color FROM plans WHERE name LIKE ? AND active=1 LIMIT 1");
    $ps->execute(["%$planId%"]);
    $plan = $ps->fetch();
}
// 2. Tenta pelo plan_name do payload
if (!$plan && $planName) {
    $ps = $db->prepare("SELECT id,name,duration_days,price,color FROM plans WHERE name LIKE ? AND active=1 LIMIT 1");
    $ps->execute(["%$planName%"]);
    $plan = $ps->fetch();
}
// 3. Usa o plano padrão das configurações
if (!$plan && $defaultPlan) {
    $ps = $db->prepare("SELECT id,name,duration_days,price,color FROM plans WHERE id=? AND active=1 LIMIT 1");
    $ps->execute([$defaultPlan]);
    $plan = $ps->fetch();
}

$planDbId  = $plan ? (int)$plan['id']           : null;
$planDays  = $plan ? (int)$plan['duration_days'] : 30;
$expires   = date('Y-m-d H:i:s', time() + $planDays * 86400);

// ── Verifica se usuário já existe (telegram_id) ──
$existing = $db->prepare('SELECT id,name,email,phone,role,plan_id,expires_at,telegram_id,telegram_username FROM users WHERE telegram_id=? LIMIT 1');
$existing->execute([$telegramId]);
$user = $existing->fetch();

$plainPassword = null;
$created       = false;

if ($user) {
    // ── Usuário existe: renova o plano ────────
    $db->prepare('UPDATE users SET
        plan_id=?, expires_at=?, expired_notified=0,
        name=?, telegram_username=?, updated_at=NOW()
        WHERE id=?')
       ->execute([$planDbId, $expires, $fullName, $username, $user['id']]);

    $user['expires_at']         = $expires;
    $user['plan_id']            = $planDbId;
    $user['plan_name']          = $plan['name'] ?? null;
    $logUserId = $user['id'];

} else {
    // ── Usuário novo: cria conta ──────────────
    $plainPassword = generatePassword();
    $hash          = password_hash($plainPassword, PASSWORD_DEFAULT);

    // Garante e-mail único
    $emailFinal = $email;
    $attempt    = 1;
    while (true) {
        $chk = $db->prepare('SELECT id FROM users WHERE email=?');
        $chk->execute([$emailFinal]);
        if (!$chk->fetch()) break;
        $emailFinal = "tg_{$telegramId}_{$attempt}@webhook.local";
        $attempt++;
    }

    $db->prepare('INSERT INTO users
        (name, email, password, role, plan_id, expires_at, telegram_id, telegram_username, webhook_source)
        VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([
           $fullName, $emailFinal, $hash, 'viewer',
           $planDbId, $expires,
           $telegramId, $username,
           $gateway ?: 'webhook'
       ]);

    $newId = (int)$db->lastInsertId();

    // Busca usuário criado
    $stmtUser = $db->prepare('SELECT id,name,email,phone,role,plan_id,expires_at,telegram_id,telegram_username FROM users WHERE id=?');
    $stmtUser->execute([$newId]);
    $user = $stmtUser->fetch();

    if (!$user) {
        logWebhook($event, $telegramId, $externalId, $planName, $amount, $gateway, 'error', 'Falha ao buscar usuário criado', $rawBody, $newId);
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load created user']);
        exit;
    }

    $logUserId = $newId;
    $created   = true;

    // ── Envia e-mail de boas-vindas ───────────
    if (getSetting('webhook_send_email','1') === '1') {
        $site    = getSetting('site_name', SITE_NAME);
        $siteUrl = SITE_URL;
        $expStr  = date('d/m/Y', strtotime($expires));
        // Escape de todos os campos vindos do payload (Telegram first_name/last_name podem conter HTML)
        $eFullName  = htmlspecialchars($fullName,  ENT_QUOTES, 'UTF-8');
        $eEmail     = htmlspecialchars($emailFinal, ENT_QUOTES, 'UTF-8');
        $ePassword  = htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8');
        $eSite      = htmlspecialchars($site,      ENT_QUOTES, 'UTF-8');
        $ePlanName  = $plan ? htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') : '';
        $html = "
        <div style='font-family:sans-serif;max-width:520px;margin:0 auto;background:#0a0a0f;color:#e8e8f0;padding:40px;border-radius:16px'>
          <h2 style='color:#7c6aff;margin-bottom:8px'>Acesso liberado! 🎉</h2>
          <p style='color:#9090a8;margin-bottom:20px'>Olá, <b style='color:#e8e8f0'>{$eFullName}</b>! Seu pagamento foi confirmado e sua conta no <b>{$eSite}</b> foi criada.</p>
          <div style='background:#13131a;border:1px solid #2a2a3a;border-radius:12px;padding:20px;margin-bottom:24px'>
            <div style='margin-bottom:10px'><span style='color:#6b6b80;font-size:12px'>E-MAIL DE ACESSO</span><br><b style='font-size:16px'>{$eEmail}</b></div>
            <div style='margin-bottom:10px'><span style='color:#6b6b80;font-size:12px'>SENHA</span><br><b style='font-size:20px;letter-spacing:2px;color:#7c6aff'>{$ePassword}</b></div>
            <div><span style='color:#6b6b80;font-size:12px'>ACESSO VÁLIDO ATÉ</span><br><b>{$expStr}</b>" . ($ePlanName ? " — {$ePlanName}" : '') . "</div>
          </div>
          <a href='{$siteUrl}/login' style='display:inline-block;padding:13px 28px;background:#7c6aff;color:#fff;border-radius:9px;text-decoration:none;font-weight:600;margin-bottom:24px'>Acessar agora →</a>
          <p style='color:#6b6b80;font-size:12px'>Guarde sua senha com segurança. Você pode alterá-la nas configurações do seu perfil.</p>
        </div>";
        // Tenta enviar para e-mail real se existir nos dados
        $realEmail = $customer['email'] ?? null;
        if ($realEmail && filter_var($realEmail, FILTER_VALIDATE_EMAIL)) {
            sendMail($realEmail, "Seu acesso ao {$site} foi liberado!", $html);
        }
    }
}

// ── Salva log ─────────────────────────────────
logWebhook($event, $telegramId, $externalId, $planName ?: ($plan['name'] ?? null), $amount, $gateway, 'ok', null, $rawBody, $logUserId, $created ? 1 : 0);

// ── Resposta ──────────────────────────────────
$response = [
    'status'       => 'ok',
    'action'       => $created ? 'created' : 'renewed',
    'user' => [
        'id'               => (int)$user['id'],
        'name'             => $user['name'],
        'email'            => $user['email'],
        'telegram_id'      => $user['telegram_id'],
        'telegram_username'=> $user['telegram_username'],
        'role'             => $user['role'],
        'plan'             => $plan['name'] ?? null,
        'expires_at'       => $expires,
        'expires_formatted'=> date('d/m/Y H:i', strtotime($expires)),
    ],
];

if ($created) {
    // Credenciais vão só por e-mail — não expomos senha plaintext na resposta do webhook
    $response['credentials'] = [
        'email'     => $user['email'],
        'login_url' => SITE_URL . '/login',
    ];
}

http_response_code($created ? 201 : 200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;

// ──────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────

function generatePassword(int $len = 12): string {
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$';
    $pass  = '';
    for ($i = 0; $i < $len; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

function logWebhook(
    string  $event,
    ?string $telegramId,
    ?string $externalId,
    ?string $planName,
    ?float  $amount,
    ?string $gateway,
    string  $status,
    ?string $errorMsg,
    ?string $payload  = null,
    ?int    $userId   = null,
    int     $created  = 0
): void {
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS webhook_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event VARCHAR(100), telegram_id VARCHAR(50), external_id VARCHAR(100),
            plan_name VARCHAR(100), amount DECIMAL(10,2), gateway VARCHAR(50),
            user_id INT DEFAULT NULL, user_created TINYINT(1) DEFAULT 0,
            status VARCHAR(20), error_msg VARCHAR(500),
            payload LONGTEXT, ip VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $db->prepare('INSERT INTO webhook_logs
            (event,telegram_id,external_id,plan_name,amount,gateway,user_id,user_created,status,error_msg,payload,ip)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([
               $event, $telegramId, $externalId, $planName, $amount, $gateway,
               $userId, $created, $status, $errorMsg,
               $payload ? mb_substr($payload, 0, 65000) : null,
               $_SERVER['REMOTE_ADDR'] ?? null,
           ]);
    } catch (Exception $e) { /* silencia */ }
}

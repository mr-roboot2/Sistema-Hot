<?php
/**
 * helix-callback.php — Recebe notificação da Helix quando PIX é pago
 * Configure essa URL na Helix como callback/webhook
 * URL: http://seusite.com/cms/helix-callback.php
 *
 * A Helix pode chamar com GET ou POST contendo o ID da transação
 * Ex: GET /helix-callback.php?id=23846178
 * Ex: POST com body: {"id":"23846178","status":"paid"}
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/pix.php';
require_once __DIR__ . '/includes/tracking.php';

header('Content-Type: application/json');

// ── Validação de origem ───────────────────────
// Se um webhook secret estiver configurado, valida o token
$webhookSecret = getSetting('helix_webhook_secret', '');
if ($webhookSecret !== '') {
    $tokenFromHeader = $_SERVER['HTTP_X_HELIX_TOKEN']
        ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN']
        ?? $_GET['token']
        ?? '';
    if (!hash_equals($webhookSecret, $tokenFromHeader)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// Aceita GET ou POST
$body = file_get_contents('php://input');
$data = json_decode($body, true) ?? [];

// Tenta pegar o ID de várias formas que a Helix pode enviar
$extId = $data['id']
    ?? $_GET['id']
    ?? $_POST['id']
    ?? $data['transaction_id']
    ?? $_GET['transaction_id']
    ?? null;

$statusFromApi = strtolower($data['status'] ?? $_GET['status'] ?? '');

if (!$extId) {
    http_response_code(400);
    echo json_encode(['error' => 'ID não fornecido']);
    exit;
}

$extId = (string)$extId;

// Só processa se veio "paid" ou não veio status (vai verificar na API)
if ($statusFromApi && $statusFromApi !== 'paid') {
    echo json_encode(['status' => 'ignored', 'reason' => "status={$statusFromApi}"]);
    exit;
}

// Busca a transação no banco pelo external_id
$db   = getDB();
$stmt = $db->prepare('SELECT * FROM transactions WHERE external_id=? AND gateway="helix" LIMIT 1');
$stmt->execute([$extId]);
$tx = $stmt->fetch();

if (!$tx) {
    // Não encontrou — tudo bem, pode ser de outro sistema
    http_response_code(200);
    echo json_encode(['status' => 'not_found', 'external_id' => $extId]);
    exit;
}

// Já estava pago
if ($tx['status'] === 'paid') {
    echo json_encode(['status' => 'already_paid']);
    exit;
}

// Se veio "paid" direto da Helix, confia e ativa
// Se não veio status, verifica na API para ter certeza
$confirmed = ($statusFromApi === 'paid');

if (!$confirmed) {
    $baseUrl = rtrim(getSetting('helix_url',''), '/');
    if ($baseUrl) {
        $resp = helixGet($baseUrl . '/status/' . $extId);
        if ($resp['ok']) {
            $check = json_decode(trim($resp['body']), true);
            $confirmed = strtolower($check['status'] ?? '') === 'paid';
        }
    }
}

if ($confirmed) {
    $db->prepare('UPDATE transactions SET status="paid", paid_at=? WHERE id=?')
       ->execute([date('Y-m-d H:i:s'), $tx['id']]);
    activatePlan((int)$tx['user_id'], (int)$tx['plan_id'], (int)$tx['id'], (float)$tx['amount']);

    // Dispara Purchase server-side (Meta Conversions API)
    if (function_exists('trackPurchase')) {
        $u = $db->prepare('SELECT name, email, phone FROM users WHERE id=?');
        $u->execute([$tx['user_id']]); $u = $u->fetch() ?: [];

        // Restaura fbc/fbp salvos no momento da criação do PIX (cookies do browser)
        // O webhook é chamado server-side então $_COOKIE está vazio
        if (!empty($tx['fbc']))  $_COOKIE['_fbc_cms'] = $tx['fbc'];
        if (!empty($tx['fbp']))  $_COOKIE['_fbp']     = $tx['fbp'];

        // event_id para deduplicação com pixel browser-side
        $eventId = hash('sha256', 'purchase_' . $tx['id'] . '_' . $tx['user_id']);

        trackPurchase((float)$tx['amount'], 'BRL', $u, (string)$tx['id'], $eventId, $tx['event_source_url'] ?? '');
    }

    // Log
    try {
        $db->prepare('INSERT IGNORE INTO webhook_logs (event,external_id,gateway,amount,status,payload,ip)
                      VALUES (?,?,?,?,?,?,?)')
           ->execute(['helix_payment_paid', $extId, 'helix', $tx['amount'], 'ok',
                      $body ?: json_encode($_GET), $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch(Exception $e) {}

    http_response_code(200);
    echo json_encode(['status' => 'activated', 'user_id' => $tx['user_id'], 'tx_id' => $tx['id']]);
} else {
    echo json_encode(['status' => 'pending']);
}

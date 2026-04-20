<?php
/**
 * includes/pix.php — Integração Helix PIX
 * API: GET {helix_url}/?valor=XX  → gera cobrança
 * API: GET {helix_url}/status/{id} → verifica status
 */


function checkPixRateLimit(int $userId): bool {
    // Máx 3 PIX gerados por usuário a cada 10 minutos
    $db    = getDB();
    $since = date('Y-m-d H:i:s', time() - 600);
    $count = $db->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=? AND created_at > ?');
    $count->execute([$userId, $since]);
    return (int)$count->fetchColumn() < 3;
}

function createPixCharge(int $userId, int $planId, float $amount): array {
    $db = getDB();

    // Rate limit — máx 3 PIX por 10 minutos
    if (!checkPixRateLimit($userId)) {
        return ['ok'=>false,'error'=>'Muitas tentativas. Aguarde alguns minutos antes de gerar um novo PIX.'];
    }

    // Reutiliza PIX pendente ainda válido para o mesmo plano
    $nowTs = date('Y-m-d H:i:s');
    $existing = $db->prepare('
        SELECT id,pix_code,pix_expires_at,external_id FROM transactions
        WHERE user_id=? AND plan_id=? AND status="pending"
          AND (pix_expires_at IS NULL OR pix_expires_at > ?)
        ORDER BY created_at DESC LIMIT 1
    ');
    $existing->execute([$userId, $planId, $nowTs]);
    $existing = $existing->fetch();
    if ($existing) {
        return [
            'ok'         => true,
            'tx_id'      => (int)$existing['id'],
            'pix_code'   => $existing['pix_code'],
            'pix_image'  => null,
            'expires_at' => $existing['pix_expires_at'],
            'external_id'=> $existing['external_id'],
            'reused'     => true,
        ];
    }

    $baseUrl = rtrim(getSetting('helix_url',''), '/');
    if (!$baseUrl) return ['ok'=>false,'error'=>'URL da API Helix não configurada em Configurações.'];

    $expiry = max(5, (int)getSetting('pix_expiry_minutes', '30'));
    $url    = $baseUrl . '/?valor=' . number_format($amount, 2, '.', '');

    $resp = helixGet($url);
    if (!$resp['ok']) return ['ok'=>false,'error'=>'Erro ao conectar: '.$resp['error']];

    $data = json_decode($resp['body'], true);
    if (empty($data['pix']) || empty($data['id'])) {
        return ['ok'=>false,'error'=>'Resposta inválida: '.$resp['body']];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + $expiry * 60);
    $txId = saveTransaction($userId, $planId, (string)$data['id'], 'helix', $amount, $data['pix'], $expiresAt, $resp['body']);

    return [
        'ok'         => true,
        'tx_id'      => $txId,
        'pix_code'   => $data['pix'],
        'pix_image'  => null,
        'expires_at' => $expiresAt,
        'external_id'=> (string)$data['id'],
    ];
}

function checkPixStatus(int $txId): array {
    $db   = getDB();
    $stmt = $db->prepare('SELECT id,user_id,plan_id,external_id,status,pix_expires_at,pix_code FROM transactions WHERE id=?');
    $stmt->execute([$txId]);
    $tx = $stmt->fetch();
    if (!$tx) return ['status'=>'not_found'];

    // Já está pago no banco
    if ($tx['status'] === 'paid') return ['status'=>'paid'];

    // PIX expirado — marca no banco para não ficar como pending para sempre
    if (!empty($tx['pix_expires_at']) && strtotime($tx['pix_expires_at']) < time()) {
        try {
            $db->prepare('UPDATE transactions SET status="failed" WHERE id=? AND status="pending"')
               ->execute([$txId]);
        } catch(Exception $e) {}
        return ['status'=>'expired'];
    }

    $extId   = $tx['external_id'] ?? '';
    $baseUrl = rtrim(getSetting('helix_url',''), '/');
    if (!$extId || !$baseUrl) return ['status'=>'pending'];

    // Consulta Helix: GET {url}/status/{id}
    $resp = helixGet($baseUrl . '/status/' . $extId);
    if (!$resp['ok']) return ['status'=>'pending'];

    // Limpa o body e decodifica
    $body   = trim($resp['body']);
    $data   = json_decode($body, true);
    $apiStatus = strtolower(trim($data['status'] ?? ''));

    // Helix retorna "paid" quando pago
    $isPaid = ($apiStatus === 'paid');

    if ($isPaid) {
        $db->prepare('UPDATE transactions SET status="paid", paid_at=? WHERE id=?')
           ->execute([date('Y-m-d H:i:s'), $txId]);
        activatePlan((int)$tx['user_id'], (int)$tx['plan_id'], (int)$tx['id'], (float)$tx['amount']);
        return ['status'=>'paid'];
    }

    return ['status'=>'pending'];
}

function activatePlan(int $userId, int $planId, int $txId = 0, float $saleAmount = 0): void {
    $db   = getDB();
    $plan = $db->prepare('SELECT id,duration_days FROM plans WHERE id=?');
    $plan->execute([$planId]); $plan = $plan->fetch();
    if (!$plan) return;
    $expires = date('Y-m-d H:i:s', time() + $plan['duration_days'] * 86400);
    $db->prepare('UPDATE users SET plan_id=?, expires_at=?, expired_notified=0 WHERE id=?')
       ->execute([$planId, $expires, $userId]);
    // Comissão de afiliado
    if ($txId && $saleAmount > 0) {
        if (!function_exists('affiliateOnPurchase')) require_once __DIR__ . '/affiliate.php';
        affiliateOnPurchase($userId, $txId, $saleAmount);
    }
}

function helixGet(string $url): array {
    // Valida que a URL pertence ao domínio configurado da Helix (evita SSRF)
    $baseUrl = rtrim(getSetting('helix_url',''), '/');
    if ($baseUrl && !str_starts_with($url, $baseUrl)) {
        return ['ok'=>false,'error'=>'URL não permitida'];
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https','http'])) {
        return ['ok'=>false,'error'=>'URL inválida'];
    }
    if (!function_exists('curl_init')) {
        // Fallback file_get_contents
        $ctx  = stream_context_create(['http'=>['timeout'=>15,'ignore_errors'=>true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return ['ok'=>false,'error'=>'Falha na requisição'];
        return ['ok'=>true,'body'=>$body];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['ok'=>false,'error'=>$err];
    return ['ok'=>true,'body'=>$body];
}

function saveTransaction(int $userId, int $planId, string $extId, string $gateway, float $amount, string $pixCode, string $expiresAt, string $payload): int {
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT, plan_id INT, external_id VARCHAR(150),
            gateway VARCHAR(50) DEFAULT 'helix',
            amount DECIMAL(10,2), currency VARCHAR(10) DEFAULT 'BRL',
            status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
            payment_method VARCHAR(50) DEFAULT 'pix',
            pix_code TEXT, pix_expires_at DATETIME, paid_at DATETIME,
            payload LONGTEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        // Colunas de tracking — adicionadas se não existirem
        try { $db->exec("ALTER TABLE transactions ADD COLUMN fbc VARCHAR(250) DEFAULT NULL"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE transactions ADD COLUMN fbp VARCHAR(250) DEFAULT NULL"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE transactions ADD COLUMN event_source_url VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}
    } catch(Exception $e) {}

    // Captura fbc/fbp dos cookies do browser (disponíveis aqui pois é chamado no request do usuário)
    $fbc = $_COOKIE['_fbc_cms'] ?? '';
    $fbp = $_COOKIE['_fbp']     ?? '';
    // Monta fbc no formato Meta se vier do cookie bruto
    if ($fbc && !str_starts_with($fbc, 'fb.')) {
        $ts  = (int)($_COOKIE['_fbc_ts'] ?? time());
        $fbc = 'fb.1.' . $ts . '.' . $fbc;
    }
    // URL do checkout (salva para usar no evento server-side)
    $sourceUrl = SITE_URL . '/renovar';

    $db->prepare('INSERT INTO transactions (user_id,plan_id,external_id,gateway,amount,pix_code,pix_expires_at,payload,fbc,fbp,event_source_url) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$userId,$planId,$extId,$gateway,$amount,$pixCode,$expiresAt,mb_substr($payload,0,65000),$fbc,$fbp,$sourceUrl]);
    return (int)$db->lastInsertId();
}

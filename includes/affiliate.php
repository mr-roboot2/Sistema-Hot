<?php
/**
 * includes/affiliate.php
 * Sistema de afiliados: captura ?ref=CODIGO, registra indicação, paga comissão na conversão.
 */

// ── Cria tabelas ───────────────────────────────────────────────
function affiliateEnsureTables(): void {
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS affiliates (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT NOT NULL UNIQUE,
            code        VARCHAR(20) NOT NULL UNIQUE,
            active      TINYINT(1) DEFAULT 1,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_aff_code (code),
            INDEX idx_aff_user (user_id)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS referrals (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            affiliate_id    INT NOT NULL,
            referred_user_id INT DEFAULT NULL,
            transaction_id  INT DEFAULT NULL,
            commission_type ENUM('percent','fixed','plan') NOT NULL DEFAULT 'percent',
            commission_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            commission_earned DECIMAL(10,2) DEFAULT 0,
            sale_amount     DECIMAL(10,2) DEFAULT 0,
            status          ENUM('click','registered','converted','paid') DEFAULT 'click',
            ip_hash         VARCHAR(64) DEFAULT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            converted_at    DATETIME DEFAULT NULL,
            INDEX idx_ref_aff (affiliate_id),
            INDEX idx_ref_user (referred_user_id),
            INDEX idx_ref_tx (transaction_id)
        )");
        // Créditos gastos (ao usar o saldo para pagar planos)
        $db->exec("CREATE TABLE IF NOT EXISTS affiliate_credits_used (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            affiliate_id INT NOT NULL,
            amount       DECIMAL(10,2) NOT NULL,
            transaction_id INT DEFAULT NULL,
            note         VARCHAR(200) DEFAULT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        // Atualiza ENUM se vier de versão anterior
        try { $db->exec("ALTER TABLE referrals MODIFY commission_type ENUM('percent','fixed','plan') NOT NULL DEFAULT 'percent'"); } catch(Exception $e) {}
        // Coluna plan_id ganho (para tipo 'plan')
        try { $db->exec("ALTER TABLE referrals ADD COLUMN reward_plan_id INT DEFAULT NULL"); } catch(Exception $e) {}
        try { $db->exec("ALTER TABLE referrals ADD COLUMN reward_plan_granted TINYINT(1) DEFAULT 0"); } catch(Exception $e) {}
        // Ativa/desativa programa
        try { $db->exec("ALTER TABLE affiliates ADD COLUMN notes TEXT DEFAULT NULL"); } catch(Exception $e) {}
    } catch(Exception $e) {}
}

// ── Captura ?ref= e salva em cookie/sessão ─────────────────────
function affiliateCaptureRef(): void {
    if (getSetting('affiliate_enabled','0') !== '1') return;
    $ref = trim($_GET['ref'] ?? '');
    if (!$ref) return;

    // Valida código existe e está ativo
    $db = getDB();
    affiliateEnsureTables();
    $aff = $db->prepare('SELECT id FROM affiliates WHERE code=? AND active=1');
    $aff->execute([$ref]);
    if (!$aff->fetch()) return;

    // Persiste por 30 dias em cookie
    $expiry = time() + 30 * 86400;
    if (!headers_sent()) {
        setcookie('_aff_ref', $ref, ['expires'=>$expiry,'path'=>'/','samesite'=>'Lax']);
        setcookie('_aff_ts',  (string)time(), ['expires'=>$expiry,'path'=>'/','samesite'=>'Lax']);
    }

    // Registra o clique (uma vez por IP por dia)
    $ip     = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : ($_SERVER['REMOTE_ADDR'] ?? ''));
    $ipHash = hash('sha256', $ip . date('Ymd') . $ref);

    $aff2 = $db->prepare('SELECT id FROM affiliates WHERE code=?');
    $aff2->execute([$ref]);
    $affRow = $aff2->fetch();
    if (!$affRow) return;

    // Evita registrar o mesmo IP duas vezes no mesmo dia
    $dup = $db->prepare('SELECT id FROM referrals WHERE affiliate_id=? AND ip_hash=? AND status="click" AND DATE(created_at)=CURDATE()');
    $dup->execute([$affRow['id'], $ipHash]);
    if (!$dup->fetch()) {
        $commType  = getSetting('affiliate_commission_type', 'percent');
        $commValue = (float)getSetting('affiliate_commission_value', '10');
        $db->prepare('INSERT INTO referrals (affiliate_id, commission_type, commission_value, ip_hash, status) VALUES (?,?,?,?,?)')
           ->execute([$affRow['id'], $commType, $commValue, $ipHash, 'click']);
    }
}

// ── Retorna código de ref ativo (cookie ou GET) ────────────────
function affiliateGetRef(): string {
    return $_COOKIE['_aff_ref'] ?? $_GET['ref'] ?? '';
}

// ── Registra indicação após cadastro ──────────────────────────
function affiliateOnRegister(int $newUserId): void {
    if (getSetting('affiliate_enabled','0') !== '1') return;
    $ref = affiliateGetRef();
    if (!$ref) return;

    $db = getDB();
    affiliateEnsureTables();

    $aff = $db->prepare('SELECT id, user_id FROM affiliates WHERE code=? AND active=1');
    $aff->execute([$ref]);
    $affRow = $aff->fetch();
    if (!$affRow) return;

    // Não conta auto-indicação
    if ((int)$affRow['user_id'] === $newUserId) return;

    $commType  = getSetting('affiliate_commission_type', 'percent');
    $commValue = (float)getSetting('affiliate_commission_value', '10');

    // Atualiza clique mais recente para "registered" ou insere novo
    $latest = $db->prepare('SELECT id FROM referrals WHERE affiliate_id=? AND status="click" ORDER BY created_at DESC LIMIT 1');
    $latest->execute([$affRow['id']]);
    $latestRow = $latest->fetch();

    if ($latestRow) {
        $db->prepare('UPDATE referrals SET referred_user_id=?, status="registered", commission_type=?, commission_value=? WHERE id=?')
           ->execute([$newUserId, $commType, $commValue, $latestRow['id']]);
    } else {
        $db->prepare('INSERT INTO referrals (affiliate_id, referred_user_id, commission_type, commission_value, status) VALUES (?,?,?,?,?)')
           ->execute([$affRow['id'], $newUserId, $commType, $commValue, 'registered']);
    }
}

// ── Registra conversão e calcula comissão após pagamento ───────
function affiliateOnPurchase(int $userId, int $txId, float $saleAmount): void {
    if (getSetting('affiliate_enabled','0') !== '1') return;
    $db = getDB();
    affiliateEnsureTables();

    // Busca referral pendente deste usuário
    $ref = $db->prepare("SELECT r.*, tx.plan_id AS tx_plan_id FROM referrals r LEFT JOIN transactions tx ON tx.id=? WHERE r.referred_user_id=? AND r.status IN ('click','registered') ORDER BY r.created_at DESC LIMIT 1");
    $ref->execute([$txId, $userId]);
    $refRow = $ref->fetch();
    if (!$refRow) return;

    $commission    = 0;
    $rewardPlanId  = null;

    if ($refRow['commission_type'] === 'percent') {
        $commission = round($saleAmount * $refRow['commission_value'] / 100, 2);
    } elseif ($refRow['commission_type'] === 'fixed') {
        $commission = min((float)$refRow['commission_value'], $saleAmount);
    } elseif ($refRow['commission_type'] === 'plan') {
        // Concede o mesmo plano que o indicado comprou
        $planId = $refRow['tx_plan_id'];
        if ($planId) {
            $rewardPlanId = $planId;
            // Busca o afiliado (user_id) para dar o plano
            $affUser = $db->prepare('SELECT user_id FROM affiliates WHERE id=?');
            $affUser->execute([$refRow['affiliate_id']]);
            $affUserId = (int)($affUser->fetchColumn() ?: 0);
            if ($affUserId) {
                // Estende ou inicia plano do afiliado
                $planInfo = $db->prepare('SELECT duration_days, price FROM plans WHERE id=?');
                $planInfo->execute([$planId]);
                $planInfo = $planInfo->fetch();
                if ($planInfo) {
                    // Verifica se já tem plano ativo — se sim, adiciona dias
                    $currentExpiry = $db->prepare('SELECT expires_at FROM users WHERE id=?');
                    $currentExpiry->execute([$affUserId]);
                    $currentExpiry = $currentExpiry->fetchColumn();
                    $base   = ($currentExpiry && strtotime($currentExpiry) > time()) ? strtotime($currentExpiry) : time();
                    $newExp = date('Y-m-d H:i:s', $base + $planInfo['duration_days'] * 86400);
                    $db->prepare('UPDATE users SET plan_id=?, expires_at=?, expired_notified=0 WHERE id=?')
                       ->execute([$planId, $newExp, $affUserId]);
                    $commission = (float)$planInfo['price']; // valor equivalente para registro
                }
            }
        }
    }

    $db->prepare("UPDATE referrals SET status='converted', transaction_id=?, sale_amount=?, commission_earned=?, reward_plan_id=?, reward_plan_granted=?, converted_at=NOW() WHERE id=?")
       ->execute([$txId, $saleAmount, $commission, $rewardPlanId, $rewardPlanId ? 1 : 0, $refRow['id']]);
}

// ── Gera código único para afiliado ───────────────────────────
function affiliateGenerateCode(string $name): string {
    $base = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', explode(' ', $name)[0]));
    $base = substr($base ?: 'AFF', 0, 6);
    $code = $base . rand(100, 999);
    // Garante unicidade
    $db = getDB();
    while (true) {
        $exists = $db->prepare('SELECT id FROM affiliates WHERE code=?');
        $exists->execute([$code]);
        if (!$exists->fetch()) break;
        $code = $base . rand(1000, 9999);
    }
    return $code;
}

// ── Saldo disponível para saque ────────────────────────────────
function affiliateBalance(int $affiliateId): float {
    $db = getDB();
    try {
        // Créditos ganhos por comissão
        $earned = $db->prepare("SELECT COALESCE(SUM(commission_earned),0) FROM referrals WHERE affiliate_id=? AND status='converted'");
        $earned->execute([$affiliateId]);
        $total = (float)$earned->fetchColumn();

        // Créditos adicionados pelo admin
        try {
            $added = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM affiliate_credits_added WHERE affiliate_id=?");
            $added->execute([$affiliateId]);
            $total += (float)$added->fetchColumn();
        } catch(Exception $e) {}

        // Créditos já utilizados
        $used = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM affiliate_credits_used WHERE affiliate_id=?");
        $used->execute([$affiliateId]);
        $totalUsed = (float)$used->fetchColumn();

        return max(0, round($total - $totalUsed, 2));
    } catch(Exception $e) { return 0; }
}

// ── Usa crédito de afiliado para pagar (parcial ou total) ──────
function affiliateUseCredit(int $affiliateId, float $amount, int $txId = 0, string $note = ''): float {
    $balance = affiliateBalance($affiliateId);
    $use     = min($balance, $amount); // não deixa ir negativo
    if ($use <= 0) return 0;

    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS affiliate_credits_used (
            id INT AUTO_INCREMENT PRIMARY KEY, affiliate_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL, transaction_id INT DEFAULT NULL,
            note VARCHAR(200) DEFAULT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $db->prepare('INSERT INTO affiliate_credits_used (affiliate_id, amount, transaction_id, note) VALUES (?,?,?,?)')
           ->execute([$affiliateId, $use, $txId ?: null, $note ?: 'Compra de plano']);
    } catch(Exception $e) {}

    return $use;
}

// ── Retorna affiliate_id do usuário (ou 0) ──────────────────────
function affiliateIdByUser(int $userId): int {
    try {
        $db  = getDB();
        $row = $db->prepare('SELECT id FROM affiliates WHERE user_id=? AND active=1');
        $row->execute([$userId]);
        return (int)($row->fetchColumn() ?: 0);
    } catch(Exception $e) { return 0; }
}

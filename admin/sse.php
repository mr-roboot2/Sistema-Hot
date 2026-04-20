<?php
/**
 * admin/sse.php — Server-Sent Events para dashboard em tempo real
 * Envia KPIs a cada 10 segundos sem recarregar a página
 */
require_once __DIR__ . '/../includes/auth.php';
requireLoginAlways();
if (!isAdmin()) { http_response_code(403); exit; }

// Sem cache, sem buffering
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // nginx
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', false);
if (ob_get_level()) ob_end_clean();
set_time_limit(0);
session_write_close();

$db = getDB();
$lastEtag = '';

function sendEvent(array $data): void {
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush(); flush();
}

// Envia heartbeat inicial imediato
sendEvent(['type' => 'connected']);

$i = 0;
while (!connection_aborted()) {
    $i++;

    try {
        // KPIs financeiros
        $fin = $db->query('
            SELECT
                COALESCE(SUM(CASE WHEN status="paid" THEN amount ELSE 0 END),0)    AS total_paid,
                SUM(status="paid")                                                   AS paid_count,
                SUM(status="pending")                                                AS pending_count,
                COALESCE(SUM(CASE WHEN status="paid" AND DATE(paid_at)=CURDATE() THEN amount ELSE 0 END),0) AS today_paid
            FROM transactions
        ')->fetch();

        // Usuários
        $usr = $db->query('
            SELECT
                COUNT(*)                                                        AS total,
                SUM(role!="admin" AND (expires_at IS NULL OR expires_at > NOW())) AS active
            FROM users WHERE role!="admin"
        ')->fetch();

        // Última transação
        $last = $db->query('
            SELECT t.amount, t.status, u.name AS user_name, t.paid_at, t.created_at
            FROM transactions t LEFT JOIN users u ON u.id=t.user_id
            ORDER BY t.id DESC LIMIT 1
        ')->fetch();

        $payload = [
            'type'       => 'kpi',
            'total_paid' => (float)$fin['total_paid'],
            'paid_count' => (int)$fin['paid_count'],
            'pending'    => (int)$fin['pending_count'],
            'today'      => (float)$fin['today_paid'],
            'users'      => (int)$usr['total'],
            'active'     => (int)$usr['active'],
            'last_tx'    => $last ? [
                'amount'    => (float)$last['amount'],
                'status'    => $last['status'],
                'user_name' => $last['user_name'],
                'time'      => $last['paid_at'] ?? $last['created_at'],
            ] : null,
            'ts' => time(),
        ];

        // Só envia se dados mudaram (evita tráfego desnecessário)
        $etag = md5(json_encode($payload));
        if ($etag !== $lastEtag) {
            sendEvent($payload);
            $lastEtag = $etag;
        } else {
            // Heartbeat a cada 30s para manter conexão viva
            if ($i % 3 === 0) {
                echo ": heartbeat\n\n";
                ob_flush(); flush();
            }
        }
    } catch(Exception $e) {
        sendEvent(['type' => 'error', 'msg' => $e->getMessage()]);
    }

    sleep(10);
}

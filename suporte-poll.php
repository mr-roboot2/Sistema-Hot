<?php
/**
 * suporte-poll.php — endpoint de polling para o chat de suporte do usuário
 * Retorna novas mensagens de uma thread que pertence ao usuário logado
 */
require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) { http_response_code(403); echo json_encode(['error'=>'auth']); exit; }

header('Content-Type: application/json');

$db     = getDB();
$userId = (int)$_SESSION['user_id'];
$mid    = (int)($_GET['id'] ?? 0);
$after  = $_GET['after'] ?? '2000-01-01 00:00:00';

if (!$mid) { echo json_encode(['replies'=>[], 'status'=>'open']); exit; }

// Verifica que a thread pertence ao usuário
$owns = $db->prepare('SELECT status FROM support_messages WHERE id=? AND user_id=?');
$owns->execute([$mid, $userId]);
$thread = $owns->fetch();

if (!$thread) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

// Marca replies do admin como lidas
$db->prepare('UPDATE support_replies SET read_at=NOW() WHERE message_id=? AND sender="admin" AND read_at IS NULL')
   ->execute([$mid]);

// Busca novas mensagens após o timestamp
$rows = $db->prepare('SELECT id, sender, body, created_at FROM support_replies WHERE message_id=? AND created_at > ? ORDER BY created_at ASC');
$rows->execute([$mid, $after]);

echo json_encode([
    'replies' => $rows->fetchAll(),
    'status'  => $thread['status'],
]);

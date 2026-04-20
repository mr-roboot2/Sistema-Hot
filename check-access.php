<?php
/**
 * check-access.php — verifica se a conta foi renovada
 * Chamado via AJAX pelo expired.php
 */
require_once __DIR__ . '/includes/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$userId = $_SESSION['expired_user_id'] ?? null;

if (!$userId) {
    echo json_encode(['renewed' => false, 'reason' => 'no_session']);
    exit;
}

try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, email, role, expires_at FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['renewed' => false, 'reason' => 'not_found']);
        exit;
    }

    // Admin nunca expira
    $isAdmin = $user['role'] === 'admin';

    // Verifica se foi renovado (expires_at no futuro ou sem expiração)
    $renewed = $isAdmin
        || empty($user['expires_at'])
        || strtotime($user['expires_at']) > time();

    if ($renewed) {
        // Restaura a sessão
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        unset($_SESSION['expired_user_id']);

        echo json_encode([
            'renewed'     => true,
            'redirect'    => SITE_URL . '/index',
            'expires_at'  => $user['expires_at']
                ? date('d/m/Y H:i', strtotime($user['expires_at']))
                : 'ilimitado',
        ]);
    } else {
        echo json_encode(['renewed' => false, 'reason' => 'still_expired']);
    }

} catch (Exception $e) {
    echo json_encode(['renewed' => false, 'reason' => 'error']);
}

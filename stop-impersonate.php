<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['impersonator_user_id'])) {
    $adminId   = (int)$_SESSION['impersonator_user_id'];
    $adminName = (string)($_SESSION['impersonator_user_name'] ?? '');
    $adminRole = (string)($_SESSION['impersonator_user_role'] ?? 'admin');

    // Remove token de sessão atual (do usuário impersonado) para não ficar "logado" por lá
    if (!empty($_SESSION['session_token'])) {
        try { getDB()->prepare('DELETE FROM user_sessions WHERE session_token=?')->execute([$_SESSION['session_token']]); } catch(Exception $e) {}
    }

    session_regenerate_id(true);
    unset($_SESSION['impersonator_user_id'], $_SESSION['impersonator_user_name'], $_SESSION['impersonator_user_role'], $_SESSION['session_token']);
    $_SESSION['user_id']   = $adminId;
    $_SESSION['user_name'] = $adminName;
    $_SESSION['user_role'] = $adminRole;

    try { auditLog('stop_impersonate', 'user:'.$adminId, $adminName); } catch(Exception $e) {}
}

header('Location: ' . SITE_URL . '/admin/users.php');
exit;

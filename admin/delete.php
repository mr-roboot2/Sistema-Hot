<?php
// admin/delete.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
requireLoginAlways();
if (!isAdmin()) { header('Location: ' . SITE_URL . '/index'); exit; }

if (!csrf_verify($_GET['csrf_token'] ?? '')) {
    header('Location: ' . SITE_URL . '/admin/manage.php?error=csrf');
    exit;
}

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

if ($id) {
    $inTransaction = false;
    try {
        // Garante que não há transação pendente de conexão reutilizada
        if ($db->inTransaction()) $db->rollBack();

        $db->beginTransaction();
        $inTransaction = true;

        $media = $db->prepare('SELECT id FROM media WHERE post_id = ?');
        $media->execute([$id]);
        foreach ($media->fetchAll() as $m) deleteMedia($m['id']);

        $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
        clearPageCache();
        auditLog('delete_post', 'post:'.$id);
        $db->commit();
    } catch(Exception $e) {
        if ($inTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
    }
}

header('Location: ' . SITE_URL . '/admin/manage.php?deleted=1');
exit;

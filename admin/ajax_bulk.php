<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';

header('Content-Type: application/json');
session_write_close();

if (!isAdmin()) { echo json_encode(['error'=>'forbidden']); exit; }
if (!csrf_verify($_POST['csrf_token'] ?? '')) { echo json_encode(['error'=>'csrf']); exit; }

$db     = getDB();
$action = $_POST['action'] ?? '';
$ids    = array_filter(array_map('intval', json_decode($_POST['ids'] ?? '[]', true)));

if (!$ids) { echo json_encode(['error'=>'no ids']); exit; }

$placeholders = implode(',', array_fill(0, count($ids), '?'));

switch ($action) {
    case 'publish':
        $db->prepare("UPDATE posts SET status='published' WHERE id IN ($placeholders)")
           ->execute($ids);
        clearPageCache();
        echo json_encode(['ok'=>true,'affected'=>count($ids)]);
        break;

    case 'draft':
        $db->prepare("UPDATE posts SET status='draft' WHERE id IN ($placeholders)")
           ->execute($ids);
        clearPageCache();
        echo json_encode(['ok'=>true,'affected'=>count($ids)]);
        break;

    case 'archive':
        $db->prepare("UPDATE posts SET status='archived' WHERE id IN ($placeholders)")
           ->execute($ids);
        clearPageCache();
        echo json_encode(['ok'=>true,'affected'=>count($ids)]);
        break;

    case 'delete':
        // Busca todas as mídias de uma vez (evita N+1)
        $allMedia = $db->prepare("SELECT id FROM media WHERE post_id IN ($placeholders)");
        $allMedia->execute($ids);
        foreach ($allMedia->fetchAll() as $m) deleteMedia($m['id']);
        $db->prepare("DELETE FROM posts WHERE id IN ($placeholders)")->execute($ids);
        clearPageCache();
        auditLog('bulk_delete', 'posts:'.implode(',',$ids));
        echo json_encode(['ok'=>true,'affected'=>count($ids)]);
        break;

    case 'move_category':
        $catId = (int)($_POST['category_id'] ?? 0);
        $db->prepare("UPDATE posts SET category_id=? WHERE id IN ($placeholders)")
           ->execute(array_merge([$catId ?: null], $ids));
        clearPageCache();
        echo json_encode(['ok'=>true,'affected'=>count($ids)]);
        break;

    default:
        echo json_encode(['error'=>'unknown action']);
}

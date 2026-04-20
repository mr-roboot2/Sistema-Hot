<?php
require_once __DIR__ . '/config.php';

function detectFileType(string $ext): string {
    if (in_array($ext, ALLOWED_IMAGES)) return 'image';
    if (in_array($ext, ALLOWED_VIDEOS)) return 'video';
    return 'file';
}

function uploadFile(array $file, int $postId): array {
    $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $fileType   = detectFileType($ext);
    $allowed    = array_merge(ALLOWED_IMAGES, ALLOWED_VIDEOS, ALLOWED_FILES);

    if (!in_array($ext, $allowed)) {
        return ['success' => false, 'error' => "Extensão .$ext não é permitida."];
    }

    // Valida MIME real com finfo (evita extensão falsificada ex: shell.php.jpg)
    if (function_exists('finfo_open')) {
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimeReal = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $safeMimes = [
            // Imagens
            'image/jpeg','image/jpg','image/png','image/gif','image/webp','image/svg+xml',
            // Vídeos
            'video/mp4','video/webm','video/ogg','video/quicktime','video/x-msvideo',
            'video/x-matroska','video/mpeg','video/3gpp',
            // Arquivos
            'application/pdf','application/zip','application/x-rar-compressed',
            'application/x-zip-compressed','application/octet-stream',
            'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ];
        if (!in_array($mimeReal, $safeMimes)) {
            return ['success' => false, 'error' => "Tipo de arquivo não permitido ($mimeReal)."];
        }
    }
    $maxBytes = (int)getSetting('max_file_mb', '500') * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        return ['success' => false, 'error' => 'Arquivo excede o tamanho máximo de ' . formatFileSize($maxBytes)];
    }

    // Verifica se já existe arquivo com o mesmo nome neste post
    $db  = getDB();
    $dup = $db->prepare('SELECT id FROM media WHERE post_id=? AND original_name=?');
    $dup->execute([$postId, $file['name']]);
    if ($dup->fetch()) {
        return ['success' => false, 'error' => 'Arquivo "' . $file['name'] . '" já existe neste post.'];
    }

    $subDir   = $fileType === 'image' ? 'images' : ($fileType === 'video' ? 'videos' : 'files');
    $destDir  = UPLOAD_DIR . $subDir . '/';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    $filename = uniqid('', true) . '_' . time() . '.' . $ext;
    $destPath = $destDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['success' => false, 'error' => 'Falha ao mover o arquivo.'];
    }

    // Compressão e redimensionamento de imagens
    if ($fileType === 'image' && function_exists('imagecreatetruecolor')) {
        compressImage($destPath, $ext);
    }

    $width = $height = $duration = $videoThumb = null;
    if ($fileType === 'image' && function_exists('getimagesize')) {
        $info = @getimagesize($destPath);
        if ($info) { $width = $info[0]; $height = $info[1]; }
    }

    $actualSize = filesize($destPath) ?: $file['size'];

    // Garante colunas
    try { $db->exec("ALTER TABLE media ADD COLUMN video_thumb VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}

    $stmt = $db->prepare('INSERT INTO media (post_id, filename, original_name, file_path, file_type, mime_type, file_size, width, height, duration) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $postId, $filename, $file['name'],
        $subDir . '/' . $filename, $fileType, $file['type'],
        $actualSize, $width, $height, $duration,
    ]);

    return [
        'success'   => true,
        'id'        => $db->lastInsertId(),
        'filename'  => $filename,
        'file_path' => $subDir . '/' . $filename,
        'file_type' => $fileType,
        'url'       => UPLOAD_URL . $subDir . '/' . $filename,
    ];
}

function deleteMedia(int $mediaId): bool {
    $db   = getDB();
    $stmt = $db->prepare('SELECT file_path, video_thumb FROM media WHERE id = ?');
    $stmt->execute([$mediaId]);
    $media = $stmt->fetch();
    if (!$media) return false;

    // Apaga arquivo principal
    $full = UPLOAD_DIR . $media['file_path'];
    if (file_exists($full)) @unlink($full);

    // Apaga thumbnail do vídeo se existir
    if (!empty($media['video_thumb'])) {
        $thumb = UPLOAD_DIR . $media['video_thumb'];
        if (file_exists($thumb)) @unlink($thumb);
    }

    $db->prepare('DELETE FROM media WHERE id = ?')->execute([$mediaId]);
    return true;
}

// Remove chunks temporários com mais de 24h (limpeza automática)
function cleanOldChunks(): void {
    $chunksDir = UPLOAD_DIR . 'chunks/';
    if (!is_dir($chunksDir)) return;
    $cutoff = time() - 86400;
    foreach (glob($chunksDir . '*', GLOB_ONLYDIR) as $dir) {
        if (filemtime($dir) < $cutoff) {
            array_map('unlink', glob($dir . '/*.part'));
            @rmdir($dir);
        }
    }
}

// Compressão, redimensionamento e conversão para WebP
function compressImage(string $path, string $ext): void {
    try {
        $maxDim  = (int)getSetting('image_max_dim', '1920');
        $quality = (int)getSetting('image_quality', '85');
        $info    = @getimagesize($path);
        if (!$info) return;

        [$w, $h, $type] = $info;

        // Só processa tipos suportados
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF])) return;

        $src = match($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            default        => null,
        };
        if (!$src) return;

        // Redimensiona se necessário
        if ($w > $maxDim || $h > $maxDim) {
            $ratio = $w > $h ? $maxDim / $w : $maxDim / $h;
            $newW  = (int)round($w * $ratio);
            $newH  = (int)round($h * $ratio);
            $dst   = imagecreatetruecolor($newW, $newH);
            if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP])) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefilledrectangle($dst, 0, 0, $newW, $newH, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        // Converte para WebP se ativado nas configurações
        $convertToWebp = getSetting('convert_webp', '0') === '1'
                      && in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG])
                      && function_exists('imagewebp')
                      && !str_ends_with(strtolower($path), '.webp');

        if ($convertToWebp) {
            $webpPath = preg_replace('/\.(jpe?g|png)$/i', '.webp', $path);
            if (imagewebp($src, $webpPath, $quality)) {
                // Remove original e renomeia para .webp
                @unlink($path);
                @rename($webpPath, $path); // mantém o mesmo caminho, muda só o conteúdo
                // Atualiza extensão no banco via flag — retorna via exceção de convenção
            }
        } else {
            match($type) {
                IMAGETYPE_JPEG => imagejpeg($src, $path, $quality),
                IMAGETYPE_PNG  => imagepng($src, $path, (int)round(9 - ($quality / 100 * 9))),
                IMAGETYPE_WEBP => imagewebp($src, $path, $quality),
                IMAGETYPE_GIF  => imagegif($src, $path),
                default        => null,
            };
        }

        imagedestroy($src);
    } catch(Throwable $e) {}
}

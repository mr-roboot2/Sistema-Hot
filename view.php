<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
session_write_close(); // libera lock de sessão imediatamente
pageCache((int)getSetting('cache_html_ttl', '0'));

$db     = getDB();
$postId = (int)($_GET['id'] ?? 0);
if (!$postId) { header('Location: ' . SITE_URL . '/posts'); exit; }

$stmt = $db->prepare('
    SELECT p.*, c.name AS cat_name, c.color AS cat_color, c.slug AS cat_slug,
           u.name AS author_name
    FROM posts p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.id = ? AND p.status = "published"
');
$stmt->execute([$postId]);
$post = $stmt->fetch();
if (!$post) { header('Location: ' . SITE_URL . '/posts'); exit; }

// Verifica se já é favorito do usuário
$isFavorite = false;
if (!empty($_SESSION['user_id'])) {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS user_favorites (
            id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, post_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_fav (user_id, post_id), INDEX idx_fav_user (user_id)
        )");
        $favStmt = $db->prepare('SELECT id FROM user_favorites WHERE user_id=? AND post_id=?');
        $favStmt->execute([$_SESSION['user_id'], $postId]);
        $isFavorite = (bool)$favStmt->fetch();
    } catch(Exception $e) {}
}

// Incrementa views — uma vez por IP a cada 1h por post (sessão é fechada antes por requireLogin)
$ip      = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : ($_SERVER['REMOTE_ADDR'] ?? ''));
$ipHash  = hash('xxh3', $ip . $postId . date('YmdH')); // muda a cada hora
try {
    $db->exec("CREATE TABLE IF NOT EXISTS post_views (
        id INT AUTO_INCREMENT PRIMARY KEY,
        post_id INT NOT NULL,
        ip_hash VARCHAR(64) NOT NULL,
        viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_view (post_id, ip_hash)
    )");
    // INSERT IGNORE — se já existe o par (post_id, ip_hash) não conta novamente
    $ins = $db->prepare('INSERT IGNORE INTO post_views (post_id, ip_hash) VALUES (?, ?)');
    $ins->execute([$postId, $ipHash]);
    if ($ins->rowCount() > 0) {
        $db->prepare('UPDATE posts SET views = views + 1 WHERE id = ?')->execute([$postId]);
    }
} catch(Exception $e) {}

// Paginação de mídia na visualização
$mediaPerPage = (int)getSetting('media_per_post', 12);

// Limite de exibição por tipo (0 = sem limite)
$mediaViewLimit = (int)getSetting('media_view_limit', 0);

// Garante coluna video_thumb antes das queries
try { $db->exec("ALTER TABLE media ADD COLUMN video_thumb VARCHAR(500) DEFAULT NULL"); } catch(Exception $e) {}

// Busca cada tipo separadamente — aplica limite se configurado
$limitSQL = ($mediaViewLimit > 0 && !isAdmin()) ? " LIMIT $mediaViewLimit" : '';

try {
    $imgStmt = $db->prepare("SELECT id, original_name, file_path, file_type, mime_type, file_size, width, height, IFNULL(video_thumb,'') AS video_thumb FROM media WHERE post_id = ? AND file_type = 'image' ORDER BY sort_order, id{$limitSQL}");
    $imgStmt->execute([$postId]);
    $images = array_values($imgStmt->fetchAll());
} catch(Exception $e) { $images = []; }

try {
    $vidStmt = $db->prepare("SELECT id, original_name, file_path, file_type, mime_type, file_size, width, height, IFNULL(video_thumb,'') AS video_thumb FROM media WHERE post_id = ? AND file_type = 'video' ORDER BY sort_order, id{$limitSQL}");
    $vidStmt->execute([$postId]);
    $videos = array_values($vidStmt->fetchAll());
} catch(Exception $e) { $videos = []; }

try {
    $fileStmt = $db->prepare("SELECT id, original_name, file_path, file_type, mime_type, file_size FROM media WHERE post_id = ? AND file_type = 'file' ORDER BY sort_order, id{$limitSQL}");
    $fileStmt->execute([$postId]);
    $files = array_values($fileStmt->fetchAll());
} catch(Exception $e) { $files = []; }

// Posts relacionados — thumbnail correta
$relStmt = $db->prepare('
    SELECT p.id, p.title, p.created_at,
           COALESCE(p.thumbnail,(SELECT file_path FROM media WHERE post_id=p.id AND file_type="image" ORDER BY id LIMIT 1)) AS thumb_image
    FROM posts p
    WHERE p.status="published" AND p.id != ? AND p.category_id = ?
    ORDER BY p.created_at DESC LIMIT 4
');
$relStmt->execute([$postId, $post['category_id'] ?: 0]);
$related = $relStmt->fetchAll();

$pageTitle = $post['title'];
require __DIR__ . '/includes/header.php';
?>
<style>
/* ── Post layout ── */
.post-header{margin-bottom:28px}
.post-title{font-family:'Roboto',sans-serif;font-size:clamp(20px,4vw,32px);font-weight:800;line-height:1.3;margin-bottom:14px}
.post-meta-bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13px;color:var(--muted)}
.post-meta-bar span{display:flex;align-items:center;gap:5px}
.post-desc{font-size:15px;line-height:1.7;color:var(--muted2);margin-top:16px;padding:18px 20px;background:var(--surface);border-radius:12px;border-left:3px solid var(--accent)}

/* ── Media sections ── */
.media-section{margin-bottom:32px}
.media-section-title{font-family:'Roboto',sans-serif;font-size:15px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.media-section-title-left{display:flex;align-items:center;gap:8px}

/* ── Gallery grid ── */
.gallery{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px}
.gallery-item{border-radius:9px;overflow:hidden;cursor:pointer;aspect-ratio:9/16;position:relative;background:var(--surface2);transition:transform .2s}
.gallery-item:hover{transform:scale(1.02)}
.gallery-item img{width:100%;height:100%;object-fit:cover}

/* ── Video gallery ── */
.video-thumb-item{cursor:pointer}
.video-thumb-item canvas{display:block;width:100%;height:100%;object-fit:cover}
.vid-overlay{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(0,0,0,.38);transition:background .2s}
.video-thumb-item:hover .vid-overlay{background:rgba(0,0,0,.6)}
.play-btn{width:46px;height:46px;border-radius:50%;background:rgba(255,106,158,.92);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 18px rgba(0,0,0,.5);transition:transform .2s}
.video-thumb-item:hover .play-btn{transform:scale(1.12)}
.vid-size{color:#fff;font-size:11px;font-weight:600;margin-top:7px;text-shadow:0 1px 4px rgba(0,0,0,.8)}
.vid-name{position:absolute;bottom:0;left:0;right:0;padding:5px 8px;background:linear-gradient(transparent,rgba(0,0,0,.8));font-size:10px;color:rgba(255,255,255,.9);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ── Lightbox ── */
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.95);z-index:1000;display:none;align-items:center;justify-content:center;padding:20px}
.lightbox.open{display:flex}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:10px;object-fit:contain}
.lightbox-close{position:absolute;top:18px;right:18px;background:rgba(255,255,255,.1);border:none;color:#fff;width:38px;height:38px;border-radius:50%;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center}
.lightbox-prev,.lightbox-next{position:absolute;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.1);border:none;color:#fff;width:42px;height:42px;border-radius:50%;cursor:pointer;font-size:22px;display:flex;align-items:center;justify-content:center;transition:background .15s}
.lightbox-prev:hover,.lightbox-next:hover{background:rgba(255,255,255,.2)}
.lightbox-prev{left:18px}.lightbox-next{right:18px}
.lightbox-counter{position:absolute;bottom:20px;left:50%;transform:translateX(-50%);color:rgba(255,255,255,.7);font-size:13px;background:rgba(0,0,0,.5);padding:4px 14px;border-radius:20px}

/* ── Video modal ── */
.vmodal{position:fixed;inset:0;background:rgba(0,0,0,.96);z-index:1001;display:none;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.vmodal.open{display:flex}
.vmodal-inner{width:100%;max-width:920px;background:var(--surface);border-radius:14px;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.7)}
.vmodal-header{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;background:var(--surface2);border-bottom:1px solid var(--border)}
.vmodal-title{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:calc(100% - 130px)}
.vmodal-meta{font-size:12px;color:var(--muted);white-space:nowrap;flex-shrink:0}
.vmodal-close{background:none;border:none;cursor:pointer;color:var(--muted);padding:4px;margin-left:10px;transition:color .15s;flex-shrink:0}
.vmodal-close:hover{color:var(--danger)}
#vmodal-player{width:100%;max-height:72vh;display:block;background:#000}
.vmodal-nav{display:flex;align-items:center;justify-content:space-between;padding:11px 18px;background:var(--surface2);border-top:1px solid var(--border)}
.vmodal-nav-btn{display:flex;align-items:center;gap:6px;padding:7px 14px;background:var(--surface3);border:1px solid var(--border);border-radius:8px;color:var(--text);font-size:13px;font-weight:500;cursor:pointer;transition:background .15s}
.vmodal-nav-btn:hover:not(:disabled){background:var(--accent);border-color:var(--accent);color:#fff}
.vmodal-nav-btn:disabled{opacity:.3;cursor:not-allowed}
.vmodal-counter{font-size:13px;color:var(--muted)}

/* ── Paginação de mídia ── */
.media-pag{display:flex;gap:5px;margin-top:12px;flex-wrap:wrap;align-items:center}
.mpag-btn{min-width:34px;height:34px;padding:0 10px;border-radius:8px;font-size:13px;font-weight:500;border:1px solid var(--border);background:var(--surface);color:var(--muted2);cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;justify-content:center}
.mpag-btn:hover{background:var(--surface2);border-color:var(--border2);color:var(--text)}
.mpag-btn.active{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:700}
.mpag-btn.dots{border:none;background:none;color:var(--muted);cursor:default;min-width:20px;pointer-events:none}
.mpag-btn.arrow{padding:0 14px}

/* ── Content ── */
.post-content{line-height:1.8;font-size:15px;color:var(--muted2)}

/* ── Download button ── */
.btn-download{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;color:var(--muted2);background:var(--surface2);border:1px solid var(--border);cursor:pointer;text-decoration:none;transition:all .15s;white-space:nowrap}
.btn-download:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-download svg{flex-shrink:0}

/* ── Plyr custom ── */
.plyr { --plyr-color-main: #7c6aff; --plyr-range-fill-background: #7c6aff; border-radius: 0; }
.plyr--video { background: #000; }
.plyr__controls { padding: 10px 14px !important; gap: 6px !important; }
.plyr--video .plyr__controls { background: linear-gradient(transparent, rgba(0,0,0,.75)) !important; }
.plyr__control--overlaid { background: rgba(124,106,255,.85) !important; }
.plyr__control--overlaid:hover { background: rgba(124,106,255,1) !important; }
.plyr__progress input[type=range] { color: #7c6aff; }
.vmodal-inner .plyr { border-radius: 0; }

/* ── Related ── */
.related-card{border-radius:10px;overflow:hidden;background:var(--surface);border:1px solid var(--border);transition:transform .2s,border-color .2s}
.related-card:hover{transform:translateY(-2px);border-color:var(--border2)}
.related-thumb{aspect-ratio:9/16;background:var(--surface2);overflow:hidden}
.related-thumb img{width:100%;height:100%;object-fit:cover}
.related-body{padding:10px}
.related-title{font-size:13px;font-weight:600;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
</style>

<!-- Breadcrumb -->
<div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--muted);margin-bottom:20px;flex-wrap:wrap">
  <a href="<?= SITE_URL ?>/posts" style="color:var(--muted2)">Postagens</a>
  <?php if ($post['cat_name']): ?>
  <span>›</span>
  <a href="<?= SITE_URL ?>/posts?cat=<?= $post['category_id'] ?>" style="color:<?= htmlspecialchars($post['cat_color']) ?>"><?= htmlspecialchars($post['cat_name']) ?></a>
  <?php endif; ?>
  <span>›</span>
  <span style="color:var(--muted)"><?= htmlspecialchars(mb_substr($post['title'],0,60)) ?><?= mb_strlen($post['title'])>60?'...':'' ?></span>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:28px;align-items:start" class="post-layout">
<div>

  <!-- Header do post -->
  <div class="post-header">
    <?php if ($post['cat_name']): ?>
    <a href="<?= SITE_URL ?>/posts?cat=<?= $post['category_id'] ?>"
       style="color:<?= htmlspecialchars($post['cat_color']) ?>;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px">
      <?= htmlspecialchars($post['cat_name']) ?>
    </a>
    <?php endif; ?>
    <h1 class="post-title"><?= htmlspecialchars($post['title']) ?></h1>
    <div class="post-meta-bar">
      <span>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?= htmlspecialchars($post['author_name']) ?>
      </span>
      <span>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <?= date('d/m/Y', strtotime($post['created_at'])) ?>
      </span>
      <span>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <?= number_format($post['views']) ?> views
      </span>
      <?php $totalMedia = count($images) + count($videos) + count($files); ?>
      <?php if ($totalMedia): ?>
      <span>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        <?= $totalMedia ?> arquivo<?= $totalMedia>1?'s':'' ?>
      </span>
      <?php endif; ?>

      <!-- Botão favorito -->
      <span style="margin-left:auto">
        <button id="fav-btn" onclick="toggleFavorite()"
                title="<?= $isFavorite ? 'Remover dos favoritos' : 'Salvar nos favoritos' ?>"
                style="display:flex;align-items:center;gap:5px;background:none;border:1px solid <?= $isFavorite ? 'rgba(255,107,107,.4)' : 'var(--border2)' ?>;border-radius:20px;padding:4px 12px;cursor:pointer;font-size:12px;color:<?= $isFavorite ? '#ff6b6b' : 'var(--muted)' ?>;transition:all .2s"
                onmouseover="this.style.borderColor='rgba(255,107,107,.5)';this.style.color='#ff6b6b'"
                onmouseout="if(!window._favSaved)this.style.borderColor='var(--border2)';if(!window._favSaved)this.style.color='var(--muted)'">
          <span id="fav-icon"><?= $isFavorite ? '❤️' : '🤍' ?></span>
          <span id="fav-label"><?= $isFavorite ? 'Salvo' : 'Salvar' ?></span>
        </button>
      </span>
    </div>
    <?php if ($post['description']): ?>
    <?php
    // No preview, trunca a descrição para as primeiras 3 linhas
    $isPreview = !isLoggedIn() && getSetting('preview_mode','0') === '1';
    $desc = $post['description'];
    if ($isPreview) {
        $descLines = explode("\n", $desc);
        $previewLines = array_slice($descLines, 0, 3);
        $desc = implode("\n", $previewLines);
        $descTruncated = count($descLines) > 3;
    }
    ?>
    <div class="post-desc" style="<?= ($isPreview && ($descTruncated ?? false)) ? 'position:relative;max-height:80px;overflow:hidden' : '' ?>">
      <?= nl2br(htmlspecialchars($desc)) ?>
      <?php if ($isPreview && ($descTruncated ?? false)): ?>
      <div style="position:absolute;bottom:0;left:0;right:0;height:40px;background:linear-gradient(transparent,var(--surface))"></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php
  // ── Preview blocked — exibe overlay de assinatura ──────────────
  $previewBlocked = defined('PREVIEW_BLOCKED') && PREVIEW_BLOCKED;
  $isPreviewMode  = !isLoggedIn() && getSetting('preview_mode','0') === '1';
  $previewBlur    = $previewBlocked && getSetting('preview_blur_media','1') === '1';
  $planCount = 0;
  if ($previewBlocked || $isPreviewMode) {
      try { $planCount = (int)$db->query('SELECT COUNT(*) FROM plans WHERE active=1 AND price > 0')->fetchColumn(); } catch(Exception $e) {}
  }

  // ── Overlay de bloqueio total (limite de posts atingido) ────────
  if ($previewBlocked):
  ?>
  <div style="position:relative;margin-bottom:24px">
    <!-- Conteúdo desfocado por baixo -->
    <?php if($previewBlur): ?>
    <div style="filter:blur(8px);pointer-events:none;user-select:none;max-height:340px;overflow:hidden;border-radius:12px">
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:8px">
        <?php for($i=0;$i<6;$i++): ?><div style="background:var(--surface2);border-radius:8px;aspect-ratio:9/16"></div><?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Overlay de bloqueio -->
    <div style="<?= $previewBlur ? 'position:absolute;inset:0;' : '' ?>display:flex;align-items:center;justify-content:center;background:<?= $previewBlur ? 'rgba(10,10,15,.85)' : 'var(--surface)' ?>;border-radius:12px;border:1px solid var(--border2);padding:40px 24px;text-align:center;backdrop-filter:<?= $previewBlur ? 'blur(4px)' : 'none' ?>">
      <div style="max-width:380px">
        <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:26px">🔒</div>
        <div style="font-family:'Roboto',sans-serif;font-size:20px;font-weight:800;margin-bottom:8px">
          Conteúdo exclusivo para membros
        </div>
        <div style="font-size:14px;color:var(--muted2);margin-bottom:24px;line-height:1.6">
          Você atingiu o limite de visualizações gratuitas.<br>
          Assine para ter acesso completo a todo o conteúdo.
        </div>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
          <?php if($planCount > 0): ?>
          <a href="<?= SITE_URL ?>/bem-vindo" class="btn btn-primary" style="padding:12px 28px;font-size:14px">
            🚀 Assinar agora
          </a>
          <?php endif; ?>
          <a href="<?= SITE_URL ?>/login" class="btn btn-secondary" style="padding:12px 22px;font-size:14px">
            Já sou membro
          </a>
        </div>
        <div style="margin-top:16px;font-size:12px;color:var(--muted)">
          ✅ Acesso imediato após o pagamento
        </div>
      </div>
    </div>
  </div>

  <?php else: // preview não bloqueado — exibe mídia (com limite parcial se preview mode) ?>

  <?php
  // Limita mídia no modo preview: 1 imagem + 1 vídeo visíveis, resto bloqueado
  $previewMediaLimit = $isPreviewMode && !isLoggedIn();

  function previewMediaOverlay(int $planCount, int $hiddenCount, string $tipo): string {
      $s = $planCount > 0
          ? '<a href="'.SITE_URL.'/bem-vindo" style="display:inline-block;background:var(--accent);color:#fff;padding:10px 24px;border-radius:8px;font-weight:700;font-size:13px;text-decoration:none;margin-bottom:8px">🚀 Assinar para ver tudo</a>'
          : '';
      return '<div style="margin-top:10px;padding:24px;background:rgba(10,10,15,.7);border:1px solid var(--border2);border-radius:10px;text-align:center;backdrop-filter:blur(6px)">
        <div style="font-size:22px;margin-bottom:8px">🔒</div>
        <div style="font-size:14px;font-weight:600;margin-bottom:4px">+'.$hiddenCount.' '.$tipo.($hiddenCount>1?'s':'').' bloqueada'.($hiddenCount>1?'s':'').'</div>
        <div style="font-size:12px;color:var(--muted);margin-bottom:14px">Assine para ver o conteúdo completo</div>'
        .$s.
        '<div style="margin-top:8px"><a href="'.SITE_URL.'/login" style="font-size:12px;color:var(--muted)">Já sou membro →</a></div>
      </div>';
  }
  ?>

  <?php
  // ── Helper: renderiza paginação de galeria ──
  function renderMediaPagination(string $id, int $total, int $perPage): void {
      if ($total <= $perPage) return;
      $pages = (int)ceil($total / $perPage);

      // Páginas a mostrar: 1, últimas, e vizinhas da ativa (começa na 1)
      $cur   = 1;
      $show  = array_unique(array_merge([1, $pages], range(max(2,$cur-2), min($pages-1,$cur+2))));
      sort($show);

      echo "<div class='media-pag' id='pag-$id'>";
      echo "<button type='button' class='mpag-btn arrow' id='pag-prev-$id' onclick='pagNav(\"$id\",\"prev\",$perPage)' disabled>← Ant</button>";

      $prev = 0;
      foreach ($show as $p) {
          if ($prev && $p - $prev > 1) echo "<span class='mpag-btn dots'>…</span>";
          $active = $p === 1 ? ' active' : '';
          echo "<button type='button' class='mpag-btn$active' onclick='showPage(\"$id\",$p,$perPage)'>$p</button>";
          $prev = $p;
      }

      echo "<button type='button' class='mpag-btn arrow' id='pag-next-$id' onclick='pagNav(\"$id\",\"next\",$perPage)'" . ($pages <= 1 ? ' disabled' : '') . ">Prox →</button>";
      echo "<span style='margin-left:auto;font-size:11px;color:var(--muted)' id='pag-info-$id'>Página 1 de $pages</span>";
      echo "</div>";
  }
  ?>

  <!-- ── VÍDEOS ── -->
  <?php if ($videos): ?>
  <?php if ($previewMediaLimit): // preview: só mostra 1 vídeo ?>
  <div class="media-section">
    <div class="media-section-title">
      <div class="media-section-title-left">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--accent2)" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
        Vídeos
        <span class="badge badge-accent" style="font-size:11px"><?= count($videos) ?></span>
      </div>
    </div>
    <?php $v = $videos[0]; $vidx = 0; ?>
    <div class="gallery" style="position:relative">
      <div class="gallery-item video-thumb-item" onclick="openVideoModal(0)" data-vidx="0">
        <?php if (!empty($v['video_thumb'])): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($v['video_thumb']) ?>" alt="<?= htmlspecialchars($v['original_name']) ?>" loading="lazy" class="img-cover" id="vcanvas-0">
        <?php else: ?>
        <canvas id="vcanvas-0" class="img-cover"></canvas>
        <video id="vsrc-0" data-src="<?= UPLOAD_URL . htmlspecialchars($v['file_path']) ?>#t=1" preload="none" muted playsinline style="display:none" data-idx="0"></video>
        <?php endif; ?>
        <div class="vid-overlay"><div class="play-btn"><svg width="18" height="18" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"/></svg></div><span class="vid-size"><?= formatFileSize($v['file_size']) ?></span></div>
        <div class="vid-name"><?= htmlspecialchars($v['original_name']) ?></div>
      </div>
      <?php if (count($videos) > 1): ?>
      <?php echo previewMediaOverlay($planCount, count($videos) - 1, 'vídeo'); ?>
      <?php endif; ?>
    </div>
  </div>
  <?php else: // sem limite ?>
  <div class="media-section">
    <div class="media-section-title">
      <div class="media-section-title-left">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--accent2)" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
        Vídeos
        <span class="badge badge-accent" style="font-size:11px"><?= count($videos) ?></span>
      </div>
    </div>
    <div class="gallery" id="gallery-video">
      <?php foreach ($videos as $vidx => $v): ?>
      <div class="gallery-item video-thumb-item" onclick="openVideoModal(<?= $vidx ?>)" data-vidx="<?= $vidx ?>"
           style="<?= $vidx >= $mediaPerPage ? 'display:none' : '' ?>">
        <?php if (!empty($v['video_thumb'])): ?>
        <img src="<?= UPLOAD_URL . htmlspecialchars($v['video_thumb']) ?>"
             alt="<?= htmlspecialchars($v['original_name']) ?>"
             loading="lazy" class="img-cover" id="vcanvas-<?= $vidx ?>">
        <?php else: ?>
        <canvas id="vcanvas-<?= $vidx ?>" class="img-cover"></canvas>
        <video id="vsrc-<?= $vidx ?>" data-src="<?= UPLOAD_URL . htmlspecialchars($v['file_path']) ?>#t=1"
               preload="none" muted playsinline style="display:none" data-idx="<?= $vidx ?>"></video>
        <?php endif; ?>
        <div class="vid-overlay">
          <div class="play-btn">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="#fff"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          </div>
          <span class="vid-size"><?= formatFileSize($v['file_size']) ?></span>
        </div>
        <div class="vid-name"><?= htmlspecialchars($v['original_name']) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php renderMediaPagination('video', count($videos), $mediaPerPage); ?>
    <?php if ($mediaViewLimit > 0 && !isAdmin()): ?>
    <div style="margin-top:10px;padding:10px 14px;background:rgba(124,106,255,.07);border:1px solid rgba(124,106,255,.2);border-radius:8px;font-size:12px;color:var(--muted2);display:flex;align-items:center;gap:8px">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Exibindo <b><?= count($videos) ?></b> de todos os vídeos deste post.
    </div>
    <?php endif; ?>
  </div>
  <?php endif; // previewMediaLimit for videos ?>
  <?php endif; // $videos ?>

  <!-- ── IMAGENS ── -->
  <?php if ($images): ?>
  <?php if ($previewMediaLimit): // preview: só mostra 1 imagem ?>
  <div class="media-section">
    <div class="media-section-title">
      <div class="media-section-title-left">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        Imagens
        <span class="badge badge-accent" style="font-size:11px"><?= count($images) ?></span>
      </div>
    </div>
    <div class="gallery" style="position:relative">
      <?php $img = $images[0]; ?>
      <div class="gallery-item" onclick="openLightbox(0)" data-imgidx="0">
        <img src="<?= UPLOAD_URL . htmlspecialchars($img['file_path']) ?>" alt="<?= htmlspecialchars($img['original_name']) ?>" loading="lazy">
      </div>
      <?php if (count($images) > 1): ?>
      <?php echo previewMediaOverlay($planCount, count($images) - 1, 'imagem'); ?>
      <?php endif; ?>
    </div>
  </div>
  <?php else: // sem limite ?>
  <div class="media-section">
    <div class="media-section-title">
      <div class="media-section-title-left">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
        Imagens
        <span class="badge badge-accent" style="font-size:11px"><?= count($images) ?></span>
      </div>
    </div>
    <div class="gallery" id="gallery-image">
      <?php foreach ($images as $idx => $img): ?>
      <div class="gallery-item" onclick="openLightbox(<?= $idx ?>)" data-imgidx="<?= $idx ?>"
           style="<?= $idx >= $mediaPerPage ? 'display:none' : '' ?>">
        <img src="<?= UPLOAD_URL . htmlspecialchars($img['file_path']) ?>"
             alt="<?= htmlspecialchars($img['original_name']) ?>" loading="lazy">
      </div>
      <?php endforeach; ?>
    </div>
    <?php renderMediaPagination('image', count($images), $mediaPerPage); ?>
    <?php if ($mediaViewLimit > 0 && !isAdmin()): ?>
    <div style="margin-top:10px;padding:10px 14px;background:rgba(124,106,255,.07);border:1px solid rgba(124,106,255,.2);border-radius:8px;font-size:12px;color:var(--muted2);display:flex;align-items:center;gap:8px">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Exibindo <b><?= count($images) ?></b> de todas as imagens deste post.
    </div>
    <?php endif; ?>
  </div>
  <?php endif; // previewMediaLimit for images ?>
  <?php endif; // $images ?>

  <!-- ── ARQUIVOS ── -->
  <?php if ($files): ?>
  <div class="media-section">
    <div class="media-section-title">
      <div class="media-section-title-left">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
        Arquivos
        <span class="badge badge-accent" style="font-size:11px"><?= count($files) ?></span>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($files as $fidx => $f): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:10px" data-fidx="<?= $fidx ?>">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" style="flex-shrink:0"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($f['original_name']) ?></div>
          <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= formatFileSize($f['file_size']) ?></div>
        </div>
        <?php if (getSetting('allow_download','1') === '1'): ?>
        <a href="<?= SITE_URL ?>/download.php?id=<?= $f['id'] ?>"
           class="btn-download" download>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Baixar
        </a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; // $files ?>

  <!-- Conteúdo texto -->
  <?php if ($post['content']): ?>
  <?php if ($previewMediaLimit): ?>
  <div class="card" style="padding:22px;margin-bottom:24px;position:relative;overflow:hidden">
    <div style="filter:blur(4px);pointer-events:none;user-select:none;max-height:80px;overflow:hidden">
      <div class="post-content"><?= nl2br(htmlspecialchars(mb_substr($post['content'], 0, 200))) ?>...</div>
    </div>
    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(10,10,15,.6);backdrop-filter:blur(2px)">
      <div style="text-align:center">
        <div style="font-size:13px;font-weight:600;margin-bottom:8px">🔒 Conteúdo exclusivo para membros</div>
        <?php if($planCount > 0): ?><a href="<?= SITE_URL ?>/bem-vindo" style="font-size:12px;color:var(--accent);font-weight:700">Assinar agora →</a><?php endif; ?>
      </div>
    </div>
  </div>
  <?php else: ?>
  <div class="card" style="padding:22px;margin-bottom:24px">
    <div class="post-content"><?= nl2br(htmlspecialchars($post['content'])) ?></div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Ações admin -->
  <?php if (isAdmin()): ?>
  <div style="display:flex;gap:8px;margin-top:8px;padding-top:20px;border-top:1px solid var(--border)">
    <a href="<?= SITE_URL ?>/admin/upload.php?edit=<?= $postId ?>" class="btn btn-secondary">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Editar
    </a>
    <a href="<?= SITE_URL ?>/admin/delete.php?id=<?= $postId ?>&csrf_token=<?= csrf_token() ?>" class="btn btn-danger"
       onclick="return confirm('Excluir este post e todos os arquivos?')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
      Excluir
    </a>
  </div>
  <?php endif; ?>

  <?php endif; // fim do bloco preview else ?>
</div>

<!-- Sidebar -->
<div style="display:flex;flex-direction:column;gap:16px">

  <!-- Thumbnail do post -->
  <?php
  $thumbUrl = '';
  if (!empty($post['thumbnail'])) $thumbUrl = UPLOAD_URL . $post['thumbnail'];
  elseif ($images) $thumbUrl = UPLOAD_URL . $images[0]['file_path'];
  ?>
  <?php if ($thumbUrl): ?>
  <div class="card" style="overflow:hidden">
    <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="Capa" style="width:100%;aspect-ratio:9/16;object-fit:cover;display:block">
  </div>
  <?php endif; ?>

  <!-- Relacionados -->
  <?php if ($related): ?>
  <div class="card" style="padding:18px">
    <div style="font-family:'Roboto',sans-serif;font-size:14px;font-weight:700;margin-bottom:14px">Relacionados</div>
    <div style="display:flex;flex-direction:column;gap:10px">
      <?php foreach ($related as $r): ?>
      <a href="<?= SITE_URL ?>/post/<?= $r['id'] ?>" class="related-card" style="display:block">
        <div class="related-thumb">
          <?php if ($r['thumb_image']): ?>
          <img src="<?= UPLOAD_URL . htmlspecialchars($r['thumb_image']) ?>" alt="" loading="lazy">
          <?php else: ?>
          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--border2)" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
          </div>
          <?php endif; ?>
        </div>
        <div class="related-body">
          <div class="related-title"><?= htmlspecialchars($r['title']) ?></div>
          <div class="txt-muted-mt"><?= timeAgo($r['created_at']) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Navegação entre posts -->
  <?php
  // Prev + next em uma única query usando union
  $navStmt = $db->prepare('
    (SELECT id, title, "prev" AS nav FROM posts WHERE status="published" AND id < ? ORDER BY id DESC LIMIT 1)
    UNION ALL
    (SELECT id, title, "next" AS nav FROM posts WHERE status="published" AND id > ? ORDER BY id ASC LIMIT 1)
  ');
  $navStmt->execute([$postId, $postId]);
  $prevPost = $nextPost = null;
  foreach ($navStmt->fetchAll() as $nav) {
    if ($nav['nav'] === 'prev') $prevPost = $nav;
    else $nextPost = $nav;
  }
  ?>
  <?php if ($prevPost || $nextPost): ?>
  <div class="card" style="padding:16px;display:flex;flex-direction:column;gap:8px">
    <div style="font-size:11px;font-weight:700;letter-spacing:1px;color:var(--muted);text-transform:uppercase;margin-bottom:4px">Navegação</div>
    <?php if ($prevPost): ?>
    <a href="<?= SITE_URL ?>/post/<?= $prevPost['id'] ?>"
       style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted2);padding:8px;background:var(--surface2);border-radius:8px;transition:background .15s"
       onmouseover="this.style.background='var(--surface3)'" onmouseout="this.style.background='var(--surface2)'">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><polyline points="15 18 9 12 15 6"/></svg>
      <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars(mb_substr($prevPost['title'],0,40)) ?></span>
    </a>
    <?php endif; ?>
    <?php if ($nextPost): ?>
    <a href="<?= SITE_URL ?>/post/<?= $nextPost['id'] ?>"
       style="display:flex;align-items:center;justify-content:flex-end;gap:8px;font-size:13px;color:var(--muted2);padding:8px;background:var(--surface2);border-radius:8px;transition:background .15s"
       onmouseover="this.style.background='var(--surface3)'" onmouseout="this.style.background='var(--surface2)'">
      <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;text-align:right"><?= htmlspecialchars(mb_substr($nextPost['title'],0,40)) ?></span>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><polyline points="9 18 15 12 9 6"/></svg>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
</div><!-- .post-layout -->

<!-- Video Modal -->
<div class="vmodal" id="vmodal" onclick="if(event.target===this)closeVideoModal()">
  <div class="vmodal-inner">
    <div class="vmodal-header">
      <div class="vmodal-title" id="vmodal-title"></div>
      <div style="display:flex;align-items:center;gap:8px;flex-shrink:0">
        <span class="vmodal-meta" id="vmodal-meta"></span>
        <?php if (getSetting('allow_download','1') === '1'): ?>
        <a id="vmodal-download" href="#" download
           class="btn-download" style="font-size:11px;padding:4px 10px">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Download
        </a>
        <?php endif; ?>
        <button class="vmodal-close" onclick="closeVideoModal()">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
      </div>
    </div>
    <video id="vmodal-player" playsinline style="width:100%;max-height:72vh;display:block;background:#000">
      Seu navegador não suporta vídeo HTML5.
    </video>
    <div class="vmodal-nav">
      <button class="vmodal-nav-btn" id="vbtn-prev" onclick="changeVideo(-1)">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        Anterior
      </button>
      <span class="vmodal-counter" id="vmodal-counter"></span>
      <button class="vmodal-nav-btn" id="vbtn-next" onclick="changeVideo(1)">
        Próximo
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </button>
    </div>
  </div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="if(event.target===this)closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()">✕</button>
  <?php if (getSetting('allow_download','1') === '1'): ?>
  <a id="lb-download" href="#" download
     style="position:absolute;top:18px;right:66px;background:rgba(255,255,255,.1);border:none;color:#fff;width:38px;height:38px;border-radius:50%;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:background .15s"
     onmouseover="this.style.background='rgba(124,106,255,.7)'" onmouseout="this.style.background='rgba(255,255,255,.1)'"
     title="Download">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
  </a>
  <?php endif; ?>
  <button class="lightbox-prev" onclick="changeLightbox(-1)">‹</button>
  <img src="" id="lightbox-img" alt="">
  <button class="lightbox-next" onclick="changeLightbox(1)">›</button>
  <div class="lightbox-counter" id="lb-counter"></div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/plyr@3/dist/plyr.css">
<script src="https://cdn.jsdelivr.net/npm/plyr@3/dist/plyr.polyfilled.js"></script>

<script>
const ALLOW_DOWNLOAD = <?= getSetting('allow_download','1') === '1' ? 'true' : 'false' ?>;
const DOWNLOAD_BASE  = <?= json_encode(SITE_URL . '/download.php?id=') ?>;

// ── Dados ─────────────────────────────────────
const videoData = <?= json_encode(array_map(function($v) { return [
    'id'   => $v['id'],
    'src'  => UPLOAD_URL . $v['file_path'],
    'name' => $v['original_name'],
    'size' => formatFileSize($v['file_size']),
    'mime' => $v['mime_type'],
]; }, $videos)) ?>;

const imageData = <?= json_encode(array_map(function($i) { return [
    'id'  => $i['id'],
    'src' => UPLOAD_URL . $i['file_path'],
    'name'=> $i['original_name'],
]; }, $images)) ?>;
const imageUrls = imageData.map(function(i){ return i.src; });
const PER_PAGE  = <?= $mediaPerPage ?>;

// ── Plyr init ─────────────────────────────────
var plyrPlayer = null;
function initPlyr() {
  if (plyrPlayer) return;
  var el = document.getElementById('vmodal-player');
  if (!el || typeof Plyr === 'undefined') return;
  plyrPlayer = new Plyr(el, {
    controls: [
      'play-large',
      'play',
      'rewind',
      'fast-forward',
      'progress',
      'current-time',
      'duration',
      'mute',
      'volume',
      'captions',
      'settings',
      'pip',
      'fullscreen'
    ],
    settings: ['quality', 'speed', 'loop'],
    speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
    keyboard: { focused: true, global: false },
    tooltips: { controls: true, seek: true },
    seekTime: 10,
    ratio: null,
    storage: { enabled: false },
    resetOnEnd: false,
  });
}
// Inicializa assim que o script carrega (Plyr já está disponível)
document.addEventListener('DOMContentLoaded', function() {
  if (typeof Plyr !== 'undefined' && <?= count($videos) > 0 ? 'true' : 'false' ?>) {
    initPlyr();
  }
});

// ── Paginação de galeria ──────────────────────
function showPage(type, page, perPage) {
  const gallery = document.getElementById('gallery-' + type);
  if (!gallery) return;

  const attr  = type === 'file' ? 'data-fidx' : (type === 'image' ? 'data-imgidx' : 'data-vidx');
  const items = gallery.querySelectorAll('[' + attr + ']');
  const total = items.length;
  const pages = Math.ceil(total / perPage);
  const start = (page - 1) * perPage;
  const end   = start + perPage;

  items.forEach(el => {
    const idx = parseInt(el.getAttribute(attr));
    el.style.display = (idx >= start && idx < end) ? '' : 'none';
  });

  // Atualiza botões de paginação smart
  const pagEl = document.getElementById('pag-' + type);
  if (pagEl) {
    // Rebuild smart button list
    const show = new Set([1, pages]);
    for (let i = Math.max(2, page-2); i <= Math.min(pages-1, page+2); i++) show.add(i);
    const sorted = [...show].sort((a,b)=>a-b);

    const btnContainer = pagEl;
    // Remove old page buttons (keep arrows and info)
    btnContainer.querySelectorAll('.mpag-btn:not(.arrow), .mpag-btn.dots').forEach(b => b.remove());

    // Re-insert page buttons between arrows
    const nextBtn = document.getElementById('pag-next-' + type);
    let prev = 0;
    sorted.forEach(p => {
      if (prev && p - prev > 1) {
        const dots = document.createElement('span');
        dots.className = 'mpag-btn dots'; dots.textContent = '…';
        btnContainer.insertBefore(dots, nextBtn);
      }
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mpag-btn' + (p === page ? ' active' : '');
      btn.textContent = p;
      btn.onclick = () => showPage(type, p, perPage);
      btnContainer.insertBefore(btn, nextBtn);
      prev = p;
    });

    // Update arrows
    const prevBtn = document.getElementById('pag-prev-' + type);
    if (prevBtn) prevBtn.disabled = page <= 1;
    if (nextBtn) nextBtn.disabled = page >= pages;

    // Update info
    const info = document.getElementById('pag-info-' + type);
    if (info) info.textContent = 'Página ' + page + ' de ' + pages;

    pagEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    // Store current page
    pagEl.dataset.currentPage = page;
  }
}

function pagNav(type, dir, perPage) {
  const pagEl = document.getElementById('pag-' + type);
  if (!pagEl) return;
  const cur   = parseInt(pagEl.dataset.currentPage || 1);
  const gallery = document.getElementById('gallery-' + type);
  const attr  = type === 'file' ? 'data-fidx' : (type === 'image' ? 'data-imgidx' : 'data-vidx');
  const total = gallery ? gallery.querySelectorAll('[' + attr + ']').length : 0;
  const pages = Math.ceil(total / perPage);
  const next  = dir === 'next' ? Math.min(pages, cur+1) : Math.max(1, cur-1);
  showPage(type, next, perPage);
}

// ── Canvas thumbnails — lazy via IntersectionObserver ──
function drawFallback(canvas, idx) {
  canvas.width = 320; canvas.height = 180;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#13131a'; ctx.fillRect(0,0,320,180);
  ctx.strokeStyle='#1c1c27'; ctx.lineWidth=1;
  for(let x=0;x<320;x+=40){ctx.beginPath();ctx.moveTo(x,0);ctx.lineTo(x,180);ctx.stroke()}
  for(let y=0;y<180;y+=40){ctx.beginPath();ctx.moveTo(0,y);ctx.lineTo(320,y);ctx.stroke()}
  ctx.fillStyle='#2a2a3a'; ctx.beginPath(); ctx.arc(160,90,34,0,Math.PI*2); ctx.fill();
  ctx.fillStyle='#ff6a9e'; ctx.beginPath(); ctx.moveTo(150,75); ctx.lineTo(150,105); ctx.lineTo(176,90); ctx.closePath(); ctx.fill();
}

function loadVideoThumb(vid) {
  if (vid.dataset.loaded) return;
  vid.dataset.loaded = '1';
  const idx    = parseInt(vid.dataset.idx);
  const canvas = document.getElementById('vcanvas-' + idx);
  if (!canvas) return;

  vid.src = vid.dataset.src;
  vid.load();

  const tryCapture = () => {
    try {
      canvas.width  = vid.videoWidth  || 320;
      canvas.height = vid.videoHeight || 180;
      if (canvas.width > 0) canvas.getContext('2d').drawImage(vid, 0, 0, canvas.width, canvas.height);
    } catch(e) {}
  };
  vid.addEventListener('loadeddata', tryCapture, {once:true});
  vid.addEventListener('canplay',    tryCapture, {once:true});
  vid.addEventListener('error',      () => drawFallback(canvas, idx), {once:true});
  setTimeout(() => { if (!canvas.width) drawFallback(canvas, idx); }, 4000);
}

// IntersectionObserver: só carrega vídeo quando o card entrar na tela
if ('IntersectionObserver' in window) {
  const obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) {
        const vid = e.target.querySelector('video[data-src]');
        if (vid) loadVideoThumb(vid);
        obs.unobserve(e.target);
      }
    });
  }, {rootMargin: '200px'});

  document.querySelectorAll('.video-thumb-item').forEach(function(el) {
    obs.observe(el);
  });
} else {
  // Fallback: carrega todos de imediato em browsers antigos
  document.querySelectorAll('video[data-src]').forEach(loadVideoThumb);
}

// ── Modal de vídeo ────────────────────────────
let currentVidIdx = 0;

function openVideoModal(idx) {
  if (typeof Plyr !== 'undefined') initPlyr(); // garante init caso DOMContentLoaded já passou
  currentVidIdx = idx;
  loadVideoModal();
  document.getElementById('vmodal').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeVideoModal() {
  if (plyrPlayer) {
    plyrPlayer.pause();
  } else {
    var el = document.getElementById('vmodal-player');
    if (el) el.pause();
  }
  document.getElementById('vmodal').classList.remove('open');
  document.body.style.overflow = '';
}
function changeVideo(dir) {
  if (plyrPlayer) {
    plyrPlayer.pause();
  } else {
    var el = document.getElementById('vmodal-player');
    if (el) el.pause();
  }
  currentVidIdx = (currentVidIdx + dir + videoData.length) % videoData.length;
  loadVideoModal();
}
function loadVideoModal() {
  var v  = videoData[currentVidIdx];
  // Atualiza src diretamente no elemento <video> — mais confiável que plyrPlayer.source
  var el = document.getElementById('vmodal-player');
  if (el) {
    // Se Plyr está ativo, obtém o elemento de media real
    var mediaEl = (plyrPlayer && plyrPlayer.media) ? plyrPlayer.media : el;
    mediaEl.src = v.src;
    mediaEl.load();
    mediaEl.play().catch(function(){});
  }
  document.getElementById('vmodal-title').textContent = v.name;
  document.getElementById('vmodal-meta').textContent  = v.size;
  document.getElementById('vmodal-counter').textContent =
    videoData.length > 1 ? (currentVidIdx + 1) + ' / ' + videoData.length : '';
  document.getElementById('vbtn-prev').disabled = videoData.length <= 1;
  document.getElementById('vbtn-next').disabled = videoData.length <= 1;
  // Atualiza link de download
  var dlBtn = document.getElementById('vmodal-download');
  if (dlBtn) { dlBtn.href = DOWNLOAD_BASE + v.id; dlBtn.download = v.name; }
}

// ── Lightbox ──────────────────────────────────
let currentIdx = 0;

function updateLbDownload(idx) {
  var dlBtn = document.getElementById('lb-download');
  if (!dlBtn || !ALLOW_DOWNLOAD) return;
  var img = imageData[idx];
  if (img) { dlBtn.href = DOWNLOAD_BASE + img.id; dlBtn.download = img.name; }
}
function openLightbox(idx) {
  currentIdx = idx;
  document.getElementById('lightbox-img').src = imageUrls[idx];
  document.getElementById('lb-counter').textContent =
    imageUrls.length > 1 ? (idx+1)+' / '+imageUrls.length : '';
  document.getElementById('lightbox').classList.add('open');
  document.body.style.overflow = 'hidden';
  updateLbDownload(idx);
}
function closeLightbox() {
  document.getElementById('lightbox').classList.remove('open');
  document.body.style.overflow = '';
}
function changeLightbox(dir) {
  currentIdx = (currentIdx + dir + imageUrls.length) % imageUrls.length;
  document.getElementById('lightbox-img').src = imageUrls[currentIdx];
  document.getElementById('lb-counter').textContent =
    imageUrls.length > 1 ? (currentIdx+1)+' / '+imageUrls.length : '';
  updateLbDownload(currentIdx);
}

// ── Teclado ───────────────────────────────────
document.addEventListener('keydown', e => {
  if (document.getElementById('vmodal').classList.contains('open')) {
    if (e.key==='Escape')     closeVideoModal();
    if (e.key==='ArrowLeft')  changeVideo(-1);
    if (e.key==='ArrowRight') changeVideo(1);
    return;
  }
  if (document.getElementById('lightbox').classList.contains('open')) {
    if (e.key==='Escape')     closeLightbox();
    if (e.key==='ArrowLeft')  changeLightbox(-1);
    if (e.key==='ArrowRight') changeLightbox(1);
  }
});

// ── Favorito ──────────────────────────────────
window._favSaved = <?= $isFavorite ? 'true' : 'false' ?>;

<?php if (!isLoggedIn() && getSetting('preview_mode','0') === '1' && !($previewBlocked ?? false)): ?>
<?php
  $previewLimit = max(1,(int)getSetting('preview_post_limit','3'));
  $previewSeen  = (int)($_COOKIE['_preview_seen'] ?? 0);
  $previewLeft  = max(0, $previewLimit - $previewSeen);
?>
// Badge de preview flutuante
(function() {
  var left = <?= $previewLeft ?>;
  if (left <= 0) return;
  var badge = document.createElement('div');
  badge.innerHTML = '👁️ <b>' + left + '</b> post' + (left > 1 ? 's' : '') + ' grátis restante' + (left > 1 ? 's' : '') +
    ' — <a href="<?= SITE_URL ?>/bem-vindo" style="color:#fff;font-weight:700">Assinar</a>';
  badge.style.cssText = 'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);' +
    'background:rgba(20,20,30,.92);border:1px solid rgba(124,106,255,.5);border-radius:24px;' +
    'padding:9px 20px;font-size:13px;color:#e8e8f0;z-index:1000;white-space:nowrap;' +
    'backdrop-filter:blur(10px);animation:fadeUp .3s ease';
  var style = document.createElement('style');
  style.textContent = '@keyframes fadeUp{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}';
  document.head.appendChild(style);
  document.body.appendChild(badge);
  setTimeout(function(){ badge.style.opacity='0'; badge.style.transition='opacity .5s'; setTimeout(function(){ badge.remove(); },500); }, 6000);
})();
<?php endif; ?>

function toggleFavorite() {
  var btn   = document.getElementById('fav-btn');
  var icon  = document.getElementById('fav-icon');
  var label = document.getElementById('fav-label');
  if (!btn || btn.disabled) return;
  btn.disabled = true;

  var fd = new FormData();
  fd.append('csrf_token', <?= json_encode(csrf_token()) ?>);
  fd.append('post_id',    <?= $postId ?>);

  fetch(<?= json_encode(SITE_URL . '/ajax-favorito.php') ?>, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      btn.disabled = false;
      if (typeof d.saved !== 'undefined') {
        window._favSaved = d.saved;
        icon.textContent  = d.saved ? '❤️' : '🤍';
        label.textContent = d.saved ? 'Salvo' : 'Salvar';
        btn.style.borderColor = d.saved ? 'rgba(255,107,107,.4)' : 'var(--border2)';
        btn.style.color       = d.saved ? '#ff6b6b' : 'var(--muted)';
        // Micro-animação
        btn.style.transform = 'scale(1.15)';
        setTimeout(function(){ btn.style.transform = ''; }, 150);
      }
    })
    .catch(function() { btn.disabled = false; });
}
</script>

<style>
@media(max-width:900px){.post-layout{grid-template-columns:1fr!important}}
#fav-btn { transition: all .2s; }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
// Arquivo mantido para compatibilidade — redireciona para suporte.php
require_once __DIR__ . '/includes/config.php';
header('Location: ' . SITE_URL . '/suporte?chat=1');
exit;

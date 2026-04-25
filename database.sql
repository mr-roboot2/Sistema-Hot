-- ============================================
-- CMS Database Schema — versão completa
-- ============================================

CREATE DATABASE IF NOT EXISTS cms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cms_db;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','editor','viewer') DEFAULT 'viewer',
    avatar VARCHAR(255) DEFAULT NULL,
    reset_token VARCHAR(100) DEFAULT NULL,
    reset_expires DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) UNIQUE NOT NULL,
    description TEXT DEFAULT NULL,
    color VARCHAR(7) DEFAULT '#6366f1',
    icon VARCHAR(50) DEFAULT 'folder',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT DEFAULT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(280) UNIQUE NOT NULL,
    description TEXT DEFAULT NULL,
    content LONGTEXT DEFAULT NULL,
    type ENUM('image','video','mixed') DEFAULT 'mixed',
    thumbnail VARCHAR(255) DEFAULT NULL,
    views INT DEFAULT 0,
    status ENUM('published','draft','archived') DEFAULT 'published',
    featured TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type ENUM('image','video') NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size BIGINT NOT NULL,
    width INT DEFAULT NULL,
    height INT DEFAULT NULL,
    duration INT DEFAULT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS settings (
    key_name VARCHAR(100) PRIMARY KEY,
    value    VARCHAR(500) NOT NULL,
    label    VARCHAR(200) NOT NULL,
    type     VARCHAR(30) DEFAULT 'text',
    options  VARCHAR(500) DEFAULT NULL
);

-- Dados iniciais
INSERT IGNORE INTO users (name, email, password, role) VALUES
('Administrador', 'admin@cms.com', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
-- Senha padrão: password

INSERT IGNORE INTO categories (name, slug, description, color, icon) VALUES
('Tecnologia', 'tecnologia', 'Posts sobre tecnologia e inovação', '#6366f1', 'cpu'),
('Tutoriais',  'tutoriais',  'Guias e tutoriais passo a passo',   '#10b981', 'book-open'),
('Notícias',   'noticias',   'Últimas notícias e atualizações',   '#f59e0b', 'newspaper'),
('Vídeos',     'videos',     'Conteúdo em vídeo',                 '#ef4444', 'play-circle'),
('Imagens',    'imagens',    'Galeria de imagens',                '#8b5cf6', 'image');

INSERT IGNORE INTO settings (key_name, value, label, type) VALUES
('per_page_home',    '8',        'Posts por página — Página Inicial',    'number'),
('per_page_listing', '12',       'Posts por página — Listagem',          'number'),
('per_page_media',   '24',       'Arquivos por página — Biblioteca',     'number'),
('media_per_post',   '12',       'Arquivos por aba — Edição de post',    'number'),
('site_name',        'MediaCMS', 'Nome do site',                         'text'),
('require_login',    '1',        'Exigir login para acessar o site',     'toggle'),
('allow_register',   '0',        'Permitir auto-cadastro de usuários',   'toggle'),
('smtp_host',        '',         'SMTP Host',                            'text'),
('smtp_port',        '587',      'SMTP Porta',                           'number'),
('smtp_user',        '',         'SMTP Usuário',                         'text'),
('smtp_pass',        '',         'SMTP Senha',                           'text'),
('smtp_from',        '',         'E-mail remetente',                     'text');

-- Colunas adicionais (rodar se tabela já existe)
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(100) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires DATETIME DEFAULT NULL;

-- Novas configurações (rodar se já tiver o banco)
INSERT IGNORE INTO settings (key_name,value,label,type) VALUES
('allow_register',      '0', 'Permitir auto-cadastro',           'toggle'),
('allow_password_reset','0', 'Permitir recuperação de senha',    'toggle'),
('protect_uploads',     '0', 'Proteger arquivos de upload',      'toggle'),
('show_php_limit_warn', '1', 'Mostrar aviso de limite php.ini',  'toggle'),
('smtp_host',  '', 'SMTP Host',                 'text'),
('smtp_port',  '587', 'SMTP Porta',             'number'),
('smtp_user',  '', 'SMTP Usuário',              'text'),
('smtp_pass',  '', 'SMTP Senha',                'text'),
('smtp_from',  '', 'E-mail remetente',          'text');

ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token   VARCHAR(100) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_expires DATETIME     DEFAULT NULL;

-- ── Planos de acesso ──────────────────────────
CREATE TABLE IF NOT EXISTS plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    duration_days INT NOT NULL DEFAULT 30,
    color VARCHAR(7) DEFAULT '#7c6aff',
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO plans (id, name, description, duration_days, color) VALUES
(1, 'Mensal',   '30 dias de acesso completo', 30,  '#7c6aff'),
(2, 'Semanal',  '7 dias de acesso completo',   7,  '#10b981'),
(3, 'Diário',   '1 dia de acesso completo',     1,  '#f59e0b'),
(4, 'Anual',    '365 dias de acesso completo', 365, '#ff6a9e');

-- Colunas de expiração na tabela users
ALTER TABLE users ADD COLUMN IF NOT EXISTS plan_id       INT DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS expires_at    DATETIME DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS expired_notified TINYINT(1) DEFAULT 0;

-- Códigos de acesso pré-pagos (admin/acessos.php)
ALTER TABLE users ADD COLUMN IF NOT EXISTS access_code    VARCHAR(20) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_anonymous   TINYINT(1)  DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS first_login_at DATETIME    DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS created_by     INT         DEFAULT NULL;
CREATE INDEX IF NOT EXISTS idx_users_access_code ON users (access_code);

-- ── Webhook ───────────────────────────────────
CREATE TABLE IF NOT EXISTS webhook_logs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    event        VARCHAR(100) NOT NULL,
    telegram_id  VARCHAR(50)  DEFAULT NULL,
    external_id  VARCHAR(100) DEFAULT NULL,
    plan_name    VARCHAR(100) DEFAULT NULL,
    amount       DECIMAL(10,2) DEFAULT NULL,
    gateway      VARCHAR(50)  DEFAULT NULL,
    user_id      INT          DEFAULT NULL,
    user_created TINYINT(1)   DEFAULT 0,
    status       ENUM('ok','error','duplicate','ignored') DEFAULT 'ok',
    error_msg    VARCHAR(500) DEFAULT NULL,
    payload      LONGTEXT     DEFAULT NULL,
    ip           VARCHAR(45)  DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Coluna telegram_id nos usuários (para vincular)
ALTER TABLE users ADD COLUMN IF NOT EXISTS telegram_id VARCHAR(50) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS telegram_username VARCHAR(100) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS webhook_source VARCHAR(100) DEFAULT NULL;

-- Configurações do webhook
INSERT IGNORE INTO settings (key_name,value,label,type) VALUES
('webhook_secret',      '',   'Webhook Secret (token de segurança)',  'text'),
('webhook_default_plan','',   'Plano padrão ao criar via webhook',    'text'),
('webhook_send_email',  '1',  'Enviar e-mail de boas-vindas ao criar usuário via webhook', 'toggle');

-- ── Checkout / PIX ────────────────────────────
ALTER TABLE plans ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) DEFAULT 0.00;
ALTER TABLE plans ADD COLUMN IF NOT EXISTS checkout_url VARCHAR(500) DEFAULT NULL;

-- Tabela de pagamentos/transações
CREATE TABLE IF NOT EXISTS transactions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT DEFAULT NULL,
    plan_id         INT DEFAULT NULL,
    external_id     VARCHAR(150) DEFAULT NULL,
    gateway         VARCHAR(50)  DEFAULT NULL,
    amount          DECIMAL(10,2) DEFAULT 0,
    currency        VARCHAR(10)  DEFAULT 'BRL',
    status          ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
    payment_method  VARCHAR(50)  DEFAULT NULL,
    pix_code        TEXT DEFAULT NULL,
    pix_expires_at  DATETIME DEFAULT NULL,
    paid_at         DATETIME DEFAULT NULL,
    payload         LONGTEXT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL
);

-- Configurações PIX
INSERT IGNORE INTO settings (key_name,value,label,type) VALUES
('pix_gateway',        'pushinpay', 'Gateway PIX (pushinpay/mercadopago/pagarme)', 'text'),
('pushinpay_token',    '',          'PushinPay — API Token',                       'text'),
('mercadopago_token',  '',          'MercadoPago — Access Token',                  'text'),
('pagarme_api_key',    '',          'Pagar.me — API Key',                          'text'),
('pix_expiry_minutes', '30',        'Expiração do PIX (minutos)',                  'number');

-- Status de pagamento pendente no usuário
ALTER TABLE users ADD COLUMN IF NOT EXISTS payment_status ENUM('pending','active','none') DEFAULT 'none';

-- Telefone do usuário
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(20) DEFAULT NULL;

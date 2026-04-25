# MediaCMS — Sistema de Gerenciamento de Conteúdo

Sistema completo em PHP/MySQL para upload e gerenciamento de imagens, vídeos e documentos com categorias, login e visualização de posts.

---

## 📋 Requisitos

- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Extensões: PDO, PDO_MySQL, GD (opcional, para dimensões de imagem)
- Servidor web: Apache (com mod_rewrite) ou Nginx

---

## 🚀 Instalação

### 1. Clonar / copiar arquivos

Copie a pasta `cms/` para o diretório do seu servidor web (ex: `htdocs`, `www` ou `public_html`).

```
/htdocs/cms/
├── admin/
│   ├── delete.php
│   ├── manage.php
│   └── upload.php
├── includes/
│   ├── auth.php
│   ├── config.php
│   ├── footer.php
│   ├── header.php
│   ├── post_card.php
│   └── upload.php
├── uploads/
│   ├── images/
│   ├── videos/
│   └── files/
├── index.php
├── login.php
├── logout.php
├── posts.php
└── view.php
```

### 2. Criar o banco de dados

Acesse o phpMyAdmin ou seu cliente MySQL e execute o arquivo `database.sql`:

```sql
mysql -u root -p < database.sql
```

Ou copie e cole o conteúdo do `database.sql` no phpMyAdmin.

### 3. Configurar a conexão

Edite `includes/config.php` e ajuste:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cms_db');
define('DB_USER', 'seu_usuario');
define('DB_PASS', 'sua_senha');

define('SITE_URL', 'http://localhost/cms');  // URL do site
```

### 4. Permissões de pastas

Garanta que a pasta `uploads/` e suas subpastas sejam graváveis:

```bash
chmod -R 755 uploads/
# ou no Linux:
chown -R www-data:www-data uploads/
```

### 5. Acessar o sistema

Abra `http://localhost/cms/login.php` no navegador.

**Credenciais padrão:**
- Email: `admin@cms.com`
- Senha: `password`

> ⚠️ Troque a senha do admin imediatamente após o primeiro acesso!

---

## 🔐 Perfis de Usuário

| Perfil   | Permissões |
|----------|-----------|
| `admin`  | Tudo: criar, editar, excluir posts e mídia |
| `editor` | Criar e editar posts |
| `viewer` | Apenas visualizar conteúdo |

---

## 📁 Tipos de arquivo suportados

| Tipo      | Extensões |
|-----------|-----------|
| Imagens   | jpg, jpeg, png, gif, webp, svg |
| Vídeos    | mp4, webm, ogg, mov, avi, mkv |
| Documentos| pdf, doc, docx, xls, xlsx, ppt, pptx, zip, rar, txt, csv |

**Tamanho máximo:** 500 MB por arquivo (configurável em `config.php`)

---

## 🗂️ Funcionalidades

- ✅ Login seguro com sessão PHP + CSRF token
- ✅ Upload múltiplo de arquivos (drag and drop)
- ✅ Galeria de imagens com lightbox
- ✅ Player de vídeo HTML5 nativo
- ✅ Download de documentos
- ✅ Categorias com cores personalizadas
- ✅ Posts em destaque
- ✅ Filtros por tipo e categoria
- ✅ Busca por título
- ✅ Paginação
- ✅ Contagem de visualizações
- ✅ Posts relacionados
- ✅ Interface responsiva (mobile-friendly)
- ✅ Painel de administração

---

## ⚙️ Configurações avançadas

No `includes/config.php`:

```php
define('MAX_FILE_SIZE', 500 * 1024 * 1024); // Tamanho máx. por arquivo

// Extensões permitidas
define('ALLOWED_IMAGES', ['jpg','jpeg','png','gif','webp','svg']);
define('ALLOWED_VIDEOS', ['mp4','webm','ogg','mov','avi','mkv']);
define('ALLOWED_FILES',  ['pdf','doc','docx','xls','xlsx','ppt','pptx','zip','rar','txt','csv']);
```

---

## 🛡️ Segurança

- Senhas com bcrypt (password_hash)
- Proteção CSRF em todos os formulários
- Validação de tipos de arquivo por extensão
- Nomes de arquivo aleatórios no servidor
- PDO com prepared statements (proteção SQL injection)
- Controle de acesso por perfil

---

## 📝 Adicionar novos usuários

Execute no banco de dados:

```sql
INSERT INTO users (name, email, password, role) VALUES 
('Nome do Usuário', 'email@exemplo.com', '$2y$12$HASH_BCRYPT_AQUI', 'viewer');
```

Para gerar o hash bcrypt via PHP:
```php
echo password_hash('minha_senha', PASSWORD_DEFAULT);
```

---

## 🔧 Estrutura do Banco de Dados

- `users` — usuários do sistema
- `categories` — categorias de conteúdo
- `posts` — postagens (título, descrição, conteúdo, status...)
- `media` — arquivos vinculados a posts (imagens, vídeos, docs)

<!-- deploy test 2026-04-25 01:11:50 -->

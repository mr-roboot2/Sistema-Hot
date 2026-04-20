# Configuração de Performance — OpenLiteSpeed

## 1. Redis para Sessions PHP

### Por que fazer isso?
Sessions em arquivo criam locks — 500 usuários simultâneos = 500 arquivos sendo travados.
Redis elimina isso completamente e é ~10x mais rápido.

### Instalar Redis (Ubuntu/Debian):
```bash
apt install redis-server php8.x-redis
systemctl enable redis-server
systemctl start redis-server
```

### Configurar PHP.ini no OLS:
No painel OpenLiteSpeed → Server Configuration → External App → PHP → Edit php.ini:

```ini
session.save_handler = redis
session.save_path = "tcp://127.0.0.1:6379"
```

Ou se Redis tiver senha:
```ini
session.save_path = "tcp://127.0.0.1:6379?auth=SUA_SENHA"
```

Reiniciar OLS: `systemctl restart lsws`

---

## 2. LiteSpeed Cache (LSCache)

### Por que fazer isso?
O OLS tem cache nativo no nível do servidor web — cacheia páginas antes do PHP executar.
É mais eficiente que qualquer cache PHP.

### Ativar no OLS:
1. OLS Admin → Virtual Hosts → Seu site → Cache
2. Enable Cache: **Yes**
3. Cache Storage Path: `/tmp/lscache/`
4. Default Expires: **300** (segundos)
5. Public Cache: **Yes**

### Regras para não cachear área admin e usuário logado:
No Virtual Host → Rewrite Rules:
```
RewriteCond %{HTTP_COOKIE} cms_session [NC]
RewriteRule .* - [E=cache-control:no-cache]

RewriteCond %{REQUEST_URI} ^/admin [NC]
RewriteRule .* - [E=cache-control:no-cache]
```

---

## 3. APCu (já configurado no código)

### Instalar:
```bash
apt install php8.x-apcu
```

### Adicionar ao php.ini:
```ini
apc.enabled = 1
apc.shm_size = 64M
apc.ttl = 3600
```

### Verificar se está ativo:
```php
<?php var_dump(function_exists('apcu_store')); ?>
```

---

## 4. MySQL — configurações recomendadas

No `/etc/mysql/mysql.conf.d/mysqld.cnf`:

```ini
# Pool de buffer — coloque 70% da RAM disponível
innodb_buffer_pool_size = 1G

# Cache de queries
query_cache_type = 1
query_cache_size = 64M

# Conexões
max_connections = 500
wait_timeout = 60
interactive_timeout = 60

# Log de queries lentas (para diagnóstico)
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 1
```

---

## 5. Cloudflare — configurações para media

No Cloudflare Dashboard → Caching:
- Cache Level: **Standard**
- Browser Cache TTL: **4 hours**

Criar Page Rule para uploads:
- URL: `seusite.com/uploads/*`
- Cache Level: **Cache Everything**
- Edge Cache TTL: **7 days**

Criar Page Rule para admin (nunca cachear):
- URL: `seusite.com/admin/*`
- Cache Level: **Bypass**

---

## Resultado esperado após implementar tudo:

| Situação | Antes | Depois |
|----------|-------|--------|
| Usuário novo abre index.php | ~150ms | ~5ms (cache) |
| 100 usuários simultâneos | servidor trava | sem impacto |
| Upload de 10 arquivos | lento para todos | isolado no admin |
| Sessão (login check) | lock de arquivo | Redis in-memory |

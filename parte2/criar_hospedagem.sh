#!/bin/bash
# criar_hospedagem.sh - Provisiona ambiente de hospedagem compartilhada para um domínio
# Uso: sudo bash criar_hospedagem.sh exemplo.com.br
#
# O script é idempotente: rodar duas vezes com o mesmo domínio não gera erros
# nem recria recursos que já existem.

set -euo pipefail

# ---------------------------------------------------------------------------
# Configurações — ajustar conforme o ambiente
# ---------------------------------------------------------------------------
PHP_FPM_SOCK="/var/run/php/php8.1-fpm.sock"  # Caminho do socket PHP-FPM
NGINX_AVAILABLE="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"
WEB_BASE="/home"
LOG_FILE="/var/log/criar_hospedagem.log"

# ---------------------------------------------------------------------------
# Utilitários de log
# ---------------------------------------------------------------------------
log() {
    local level="${1:-INFO}"
    shift
    local message="$*"
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" | tee -a "$LOG_FILE"
}

info()  { log "INFO"  "$@"; }
warn()  { log "WARN"  "$@"; }
error() { log "ERROR" "$@"; exit 1; }

# ---------------------------------------------------------------------------
# Validações iniciais
# ---------------------------------------------------------------------------
DOMAIN="${1:-}"

[[ -z "$DOMAIN" ]] && error "Uso: $0 <dominio>  (ex: $0 meusite.com.br)"
[[ "$EUID" -ne 0 ]] && error "Este script precisa ser executado como root (sudo)"

# Validação básica do formato do domínio
if ! echo "$DOMAIN" | grep -qE '^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$'; then
    error "Domínio inválido: '$DOMAIN'"
fi

# ---------------------------------------------------------------------------
# Derivar username do domínio
# Remove TLD, substitui . e - por _, limita a 32 caracteres, prefixo "cliente_"
# Exemplo: meusite.com.br → cliente_meusite
# ---------------------------------------------------------------------------
DOMAIN_BASE=$(echo "$DOMAIN" | cut -d. -f1)
USERNAME="cliente_$(echo "$DOMAIN_BASE" | tr '[:upper:]' '[:lower:]' | tr '-' '_' | cut -c1-24)"

HOME_DIR="$WEB_BASE/$USERNAME"
PUBLIC_HTML="$HOME_DIR/public_html"
VHOST_FILE="$NGINX_AVAILABLE/$DOMAIN"
VHOST_LINK="$NGINX_ENABLED/$DOMAIN"

info "=== Iniciando provisionamento ==="
info "Domínio   : $DOMAIN"
info "Usuário   : $USERNAME"
info "Home      : $HOME_DIR"
info "Public    : $PUBLIC_HTML"
info "Vhost     : $VHOST_FILE"

# ---------------------------------------------------------------------------
# 1. Criar usuário Linux (idempotente)
# ---------------------------------------------------------------------------
if id "$USERNAME" &>/dev/null; then
    info "Usuário '$USERNAME' já existe — pulando criação"
else
    useradd \
        --create-home \
        --home-dir "$HOME_DIR" \
        --shell /usr/sbin/nologin \
        --comment "Hospedagem $DOMAIN" \
        "$USERNAME"
    info "Usuário '$USERNAME' criado"
fi

# ---------------------------------------------------------------------------
# 2. Criar estrutura de diretórios (idempotente)
# ---------------------------------------------------------------------------
if [[ -d "$PUBLIC_HTML" ]]; then
    info "Diretório '$PUBLIC_HTML' já existe — pulando criação"
else
    mkdir -p "$PUBLIC_HTML"
    info "Diretório '$PUBLIC_HTML' criado"
fi

# Permissões sempre reaplicadas para garantir consistência:
# - Home dir: 711 (dono acessa tudo; outros podem traversar mas não listar)
# - public_html: 755 (nginx/www-data precisa ler os arquivos)
chown -R "$USERNAME":"$USERNAME" "$HOME_DIR"
chmod 711 "$HOME_DIR"
chmod 755 "$PUBLIC_HTML"
info "Permissões aplicadas em $HOME_DIR"

# Página index padrão (só cria se não existir)
INDEX_FILE="$PUBLIC_HTML/index.html"
if [[ ! -f "$INDEX_FILE" ]]; then
    cat > "$INDEX_FILE" <<EOF
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>$DOMAIN</title>
</head>
<body>
    <h1>$DOMAIN</h1>
    <p>Ambiente de hospedagem provisionado com sucesso.</p>
</body>
</html>
EOF
    chown "$USERNAME":"$USERNAME" "$INDEX_FILE"
    info "Arquivo index.html padrão criado em $PUBLIC_HTML"
fi

# ---------------------------------------------------------------------------
# 3. Gerar virtual host Nginx (idempotente)
# ---------------------------------------------------------------------------
if [[ -f "$VHOST_FILE" ]]; then
    info "Virtual host '$VHOST_FILE' já existe — pulando geração"
else
    cat > "$VHOST_FILE" <<EOF
server {
    listen 80;
    server_name $DOMAIN www.$DOMAIN;

    root $PUBLIC_HTML;
    index index.php index.html index.htm;

    access_log /var/log/nginx/${DOMAIN}_access.log;
    error_log  /var/log/nginx/${DOMAIN}_error.log warn;

    # Bloquear acesso a arquivos ocultos (ex: .htaccess, .git)
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    location / {
        try_files \$uri \$uri/ =404;
    }

    # PHP via PHP-FPM
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;

        fastcgi_read_timeout 60;
        fastcgi_connect_timeout 10;
    }
}
EOF
    info "Virtual host '$VHOST_FILE' gerado"
fi

# ---------------------------------------------------------------------------
# 4. Ativar virtual host via symlink (idempotente)
# ---------------------------------------------------------------------------
if [[ -L "$VHOST_LINK" ]]; then
    info "Symlink '$VHOST_LINK' já existe — pulando criação"
elif [[ -e "$VHOST_LINK" ]]; then
    warn "Arquivo '$VHOST_LINK' existe mas não é symlink — verifique manualmente"
else
    ln -s "$VHOST_FILE" "$VHOST_LINK"
    info "Symlink '$VHOST_LINK' criado"
fi

# ---------------------------------------------------------------------------
# 5. Testar configuração Nginx e recarregar apenas se válida
# ---------------------------------------------------------------------------
info "Testando configuração do Nginx..."
if nginx -t 2>>"$LOG_FILE"; then
    info "Configuração válida — recarregando Nginx"
    systemctl reload nginx
    info "Nginx recarregado com sucesso"
else
    error "Configuração Nginx inválida — reload cancelado. Verifique $VHOST_FILE"
fi

# ---------------------------------------------------------------------------
# Resumo final
# ---------------------------------------------------------------------------
info "=== Provisionamento concluído com sucesso ==="
info "Domínio      : $DOMAIN"
info "Usuário Linux: $USERNAME"
info "Web root     : $PUBLIC_HTML"
info "Vhost ativo  : $VHOST_LINK"

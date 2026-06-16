#!/bin/bash
# health_check.sh - Verifica status do Nginx e PHP-FPM e registra alerta se algum estiver fora
# Uso: sudo bash health_check.sh
# Cron sugerido: */5 * * * * /usr/local/bin/health_check.sh

set -euo pipefail

LOG_FILE="/var/log/health_check.log"
ALERT_EMAIL=""          # Preencher com e-mail destino para alertas por e-mail (opcional)
PHP_FPM_SERVICE="php8.1-fpm"   # Ajustar para a versão instalada
NGINX_SERVICE="nginx"

# Detecta automaticamente a versão do PHP-FPM instalada
if ! systemctl list-units --type=service | grep -q "$PHP_FPM_SERVICE"; then
    PHP_FPM_SERVICE=$(systemctl list-units --type=service | grep -oP 'php\S+-fpm' | head -1)
fi

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

alert() {
    local service="$1"
    local status="$2"
    local message="ALERTA: $service está $status no host $(hostname)"

    log "$message"

    # Alerta por e-mail (requer mailutils ou postfix/exim configurado)
    if [[ -n "$ALERT_EMAIL" ]] && command -v mail &>/dev/null; then
        echo "$message" | mail -s "[HEALTH CHECK] $service DOWN - $(hostname)" "$ALERT_EMAIL"
        log "Alerta por e-mail enviado para: $ALERT_EMAIL"
    fi
}

check_service() {
    local service="$1"

    if systemctl is-active --quiet "$service"; then
        log "OK: $service está ATIVO"
        return 0
    else
        local status
        status=$(systemctl is-active "$service" 2>/dev/null || echo "desconhecido")
        alert "$service" "$status"
        return 1
    fi
}

check_port() {
    local service="$1"
    local port="$2"

    if ss -tlnp | grep -q ":$port "; then
        log "OK: porta $port ($service) está escutando"
        return 0
    else
        log "AVISO: porta $port ($service) não está escutando"
        return 1
    fi
}

check_nginx_config() {
    if nginx -t &>/dev/null; then
        log "OK: configuração do Nginx é válida"
        return 0
    else
        local errors
        errors=$(nginx -t 2>&1)
        log "ERRO: configuração do Nginx inválida — $errors"
        alert "Nginx (config)" "com configuração inválida"
        return 1
    fi
}

check_php_socket() {
    local socket
    socket=$(find /var/run/php/ /run/php/ -name "*.sock" 2>/dev/null | head -1)

    if [[ -S "$socket" ]]; then
        log "OK: socket PHP-FPM encontrado em $socket"
        return 0
    else
        log "AVISO: nenhum socket PHP-FPM encontrado em /var/run/php/ ou /run/php/"
        return 1
    fi
}

main() {
    log "=== Início do health check ==="

    local exit_code=0

    check_service "$NGINX_SERVICE"    || exit_code=1
    check_service "$PHP_FPM_SERVICE"  || exit_code=1
    check_port    "Nginx" "80"        || exit_code=1
    check_nginx_config                || exit_code=1
    check_php_socket                  || exit_code=1

    if [[ "$exit_code" -eq 0 ]]; then
        log "RESULTADO: todos os serviços OK"
    else
        log "RESULTADO: um ou mais serviços com problema — verifique os alertas acima"
    fi

    log "=== Fim do health check ==="
    return "$exit_code"
}

main

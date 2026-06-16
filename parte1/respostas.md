# Parte 1 — Linux e Resolução de Problemas

## Cenário: Nginx retornando 502 em servidor de hospedagem

---

## 1. Diagnóstico inicial — comandos utilizados

### Status dos serviços

```bash
# Verificar se Nginx está rodando
systemctl status nginx

# Verificar se PHP-FPM está rodando (ajustar versão conforme ambiente)
systemctl status php8.1-fpm
systemctl status php-fpm  # genérico em algumas distros

# Logs do systemd para ambos
journalctl -u nginx -n 100 --no-pager
journalctl -u php8.1-fpm -n 100 --no-pager
```

### Logs de erro

```bash
# Log de erros do Nginx (geral e do vhost específico)
tail -200 /var/log/nginx/error.log
tail -200 /var/log/nginx/access.log

# Log do PHP-FPM
tail -200 /var/log/php8.1-fpm.log
tail -200 /var/log/php8.1-fpm.log.1  # rotação anterior se o atual estiver vazio

# Se o vhost tiver log separado
tail -200 /var/log/nginx/dominio_error.log
```

### Portas e sockets em uso

```bash
# Verificar se Nginx escuta nas portas 80/443
ss -tlnp | grep -E ':80|:443'

# Verificar se PHP-FPM escuta na porta 9000 (modo TCP) ou se socket existe
ss -tlnp | grep 9000
ls -la /var/run/php/
ls -la /run/php/

# Alternativa com netstat
netstat -tlnp | grep -E '80|443|9000'
```

### Processos ativos

```bash
ps aux | grep -E 'nginx|php-fpm'
pgrep -a nginx
pgrep -a php-fpm
```

### Validar configuração

```bash
# Testar sintaxe do Nginx
nginx -t

# Verificar qual socket/porta está configurado no fastcgi_pass
grep -r "fastcgi_pass" /etc/nginx/sites-enabled/
grep -r "fastcgi_pass" /etc/nginx/conf.d/

# Conferir configuração do pool PHP-FPM
grep -E "^listen" /etc/php/8.1/fpm/pool.d/www.conf
```

### Testar conectividade interna

```bash
# Testar diretamente se o PHP-FPM responde (TCP)
curl -v http://127.0.0.1:9000

# Verificar se socket Unix tem permissão correta
stat /var/run/php/php8.1-fpm.sock
```

---

## 2. Três causas prováveis de 502 (Nginx + PHP-FPM)

### Causa 1 — PHP-FPM parado ou socket/porta inexistente

**O que acontece:** O Nginx tenta repassar a requisição ao PHP-FPM mas não encontra ninguém escutando — o socket Unix não existe ou a porta TCP não responde.

**Como confirmar:**
```bash
systemctl status php8.1-fpm
# Se inativo → causa confirmada

ls -la /var/run/php/php8.1-fpm.sock
# Se arquivo não existe → socket não foi criado (FPM não iniciou)

ss -tlnp | grep 9000
# Vazio → porta TCP não está aberta
```

**Resolução:** `systemctl start php8.1-fpm` e investigar por que caiu (`journalctl -u php8.1-fpm`).

---

### Causa 2 — Mismatch entre `fastcgi_pass` no Nginx e socket real do PHP-FPM

**O que acontece:** O Nginx aponta para um socket (`/var/run/php/php8.1-fpm.sock`) mas o PHP-FPM está configurado para escutar em outro path ou em TCP (`127.0.0.1:9000`). Ambos os serviços estão rodando, mas não "se encontram".

**Como confirmar:**
```bash
# Ver o que o Nginx está tentando usar
grep "fastcgi_pass" /etc/nginx/sites-enabled/exemplo.com

# Ver o que o PHP-FPM realmente usa
grep "^listen" /etc/php/8.1/fpm/pool.d/www.conf

# Se os valores divergem → causa confirmada
# Exemplo de divergência:
#   Nginx: fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
#   FPM:   listen = 127.0.0.1:9000
```

**Resolução:** Alinhar a diretiva `fastcgi_pass` com o `listen` real do PHP-FPM e recarregar o Nginx.

---

### Causa 3 — Timeout do PHP-FPM (processo PHP demorou demais)

**O que acontece:** O PHP-FPM está rodando, mas o processo PHP travou, consumiu toda a memória ou simplesmente demorou além do `fastcgi_read_timeout` configurado no Nginx. O Nginx encerra a conexão com 502/504.

**Como confirmar:**
```bash
# Ver timeout configurado no Nginx
grep -r "fastcgi_read_timeout" /etc/nginx/

# Ver request_terminate_timeout no pool FPM
grep "request_terminate_timeout" /etc/php/8.1/fpm/pool.d/www.conf

# Verificar se há workers PHP travados
ps aux | grep php-fpm | grep -v grep
# Muitos processos em estado "D" (uninterruptible sleep) indica travar

# Verificar uso de memória
free -h
cat /proc/meminfo | grep -E 'MemFree|MemAvailable'

# Logs do PHP-FPM mostram erros de timeout
tail -100 /var/log/php8.1-fpm.log | grep -i "timeout\|warning\|error"
```

**Resolução:** Aumentar `fastcgi_read_timeout` no Nginx e/ou otimizar o script PHP (query lenta, loop infinito, etc).

---

## 3. Diferenciando problema no servidor web, na aplicação ou na rede

### Servidor web (Nginx)

**Indicadores:**
- `nginx -t` retorna erro de sintaxe
- `systemctl status nginx` mostra serviço parado ou em falha
- Porta 80/443 não responde nem localmente: `curl -v http://127.0.0.1`
- Log do Nginx em `/var/log/nginx/error.log` com erros de configuração ou permissão

**Teste:** Se `curl http://127.0.0.1` retornar connection refused, o problema é no servidor web. Se retornar 502, o Nginx está respondendo mas o backend não.

---

### Aplicação (PHP-FPM / código)

**Indicadores:**
- Nginx está rodando e porta responde, mas retorna 502/503/500
- Log do Nginx mostra: `connect() failed (111: Connection refused) while connecting to upstream`
- Log do PHP-FPM mostra erros de código (exceções, erros fatais)
- PHP-FPM parado ou sem workers disponíveis

**Teste:**
```bash
# Verificar se PHP-FPM responde diretamente
cgi-fcgi -bind -connect /var/run/php/php8.1-fpm.sock
# ou
curl -v http://127.0.0.1:9000

# Testar script PHP simples para isolar código da aplicação
echo "<?php phpinfo(); ?>" > /tmp/test.php
```

---

### Camada de rede

**Indicadores:**
- Servidor funciona localmente (`curl http://127.0.0.1`) mas não externamente
- Timeouts ao acessar de fora
- Rota ou firewall bloqueando

**Teste:**
```bash
# Verificar firewall local
ufw status
iptables -L -n | grep -E '80|443'

# Verificar se porta está exposta externamente
ss -tlnp | grep -E ':80|:443'

# Testar conectividade de fora (de outra máquina)
telnet IP_SERVIDOR 80
curl -v http://IP_SERVIDOR

# Verificar rota de rede
traceroute IP_SERVIDOR
mtr --report IP_SERVIDOR
```

**Matriz de diagnóstico rápido:**

| Sintoma | Provável causa |
|---|---|
| `connection refused` na porta 80 | Nginx parado |
| `connection refused` no upstream (log) | PHP-FPM parado ou socket errado |
| 502 com timeout (> 60s) | Script PHP travado ou lento |
| Funciona local, não externo | Firewall/rede |
| 502 esporádico sob carga | PHP-FPM sem workers suficientes |

---

## Bônus — Script `health_check.sh`

Veja o arquivo [`health_check.sh`](./health_check.sh) nesta pasta.

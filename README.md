# Desafio Técnico — Analista de Infraestrutura Linux
**Candidato:** Gabriel da Silva Araujo  
**Empresa:** Configr / Umbler  
**Prazo:** 18/06/2026

---

## Estrutura do repositório

```
.
├── parte1/                   # Linux e resolução de problemas
│   ├── respostas.md          # Diagnóstico 502, causas, diferenciação
│   └── health_check.sh       # Script de monitoramento Nginx + PHP-FPM
│
├── parte2/                   # Automação com shell script
│   └── criar_hospedagem.sh   # Provisiona usuário + vhost + permissões (idempotente)
│
├── parte3/                   # Gerenciamento de configuração (Puppet)
│   ├── respostas.md          # Organização de manifests, idempotência, grupos
│   └── nginx.pp              # Manifest exemplo com template
│
├── parte4/                   # E-mail e deliverability (Exim)
│   └── respostas.md          # Logs, DNS, blacklist, config vs reputação, comunicação
│
└── diferencial/              # Docker e CI/CD (opcional — fiz os dois)
    ├── docker-compose.yml    # Nginx + PHP-FPM + MariaDB
    ├── nginx/default.conf    # Configuração do Nginx para o compose
    ├── app/index.php         # Dashboard de infraestrutura: serviços, sistema, phpinfo()
    ├── .env.example          # Template de variáveis sensíveis
    ├── cicd_respostas.md     # Explicações sobre o pipeline e segurança de credenciais
    └── .github/workflows/
        └── deploy.yml        # Pipeline GitHub Actions: test + deploy via SSH
```

---

## Decisões tomadas

### Parte 1 — Diagnóstico 502

Optei por cobrir as três camadas (web, aplicação, rede) de forma sistemática, partindo do mais provável ao menos provável. O script `health_check.sh` detecta automaticamente a versão do PHP-FPM instalada para funcionar em diferentes ambientes sem ajuste manual.

**O que faria diferente com mais tempo:**
- Integrar o `health_check.sh` com uma ferramenta de alertas real (ex: envio de e-mail via `sendmail` ou webhook para Slack)
- Adicionar verificação de latência de resposta HTTP, não só status do processo
- Cobrir também o PHP-FPM pool (status via `fastcgi_pass`) e não apenas o socket

### Parte 2 — Script de provisionamento

Usei shell script por ser a ferramenta mais natural para operações de sistema Linux. A **idempotência** foi implementada verificando a existência de cada recurso antes de criar:
- Usuário: `id $USERNAME`
- Diretório: `[ -d $PUBLIC_HTML ]`
- Vhost: `[ -f $VHOST_FILE ]`
- Symlink: `[ -L $VHOST_LINK ]`

Permissões são sempre reaplicadas (idempotente por natureza), pois `chown` e `chmod` no mesmo valor não causam side-effects.

O Nginx só é recarregado após `nginx -t` ter sucesso — evita deixar o servidor sem serviço se a config gerada tiver problema.

**O que faria diferente com mais tempo:**
- Adicionar suporte a PHP-FPM pool separado por cliente (isolamento melhor)
- Configurar quota de disco por usuário (`setquota`)
- Suporte a HTTPS via Let's Encrypt (certbot)

### Parte 3 — Puppet

Segui o padrão **roles & profiles** porque é o mais adotado em frotas grandes: separa claramente o *o que o nó é* (role) do *como cada capacidade é implementada* (profile/module). Usei Hiera como mecanismo para diferenciar grupos de servidores — é mais flexível e menos acoplado do que condicionais no manifest.

**O que faria diferente com mais tempo:**
- Criar testes com `rspec-puppet`
- Usar r10k para gerenciar versões do módulo via Puppetfile

### Parte 4 — Exim e deliverability

Organizei a resposta em camadas de diagnóstico: primeiro os logs internos (o que o servidor sabe), depois o DNS (o que o mundo vê), depois ferramentas externas de reputação. A diferenciação config vs. reputação é importante porque o caminho de resolução é completamente diferente — configuração é resolvida em minutos, reputação de IP pode levar dias.

A seção de comunicação com o cliente foi escrita para ser usada como template real: linguagem acessível, sem jargão técnico, com alternativa paliativa enquanto o problema é resolvido.

**O que faria diferente com mais tempo:**
- Montar um ambiente Exim real para demonstrar a leitura de logs e testar as configurações de DNS na prática
- Automatizar a verificação de blacklist via script (consultando MXToolbox ou similar via API)
- Documentar o processo de saída de blacklist para os principais provedores (Spamhaus, Barracuda)

### Diferencial — Docker e CI/CD

Fiz as **duas opções** (A e B) porque a combinação Docker + CI/CD é um workflow muito comum em hospedagem moderna.

**Docker (Opção A):**
- Usei PHP-FPM separado do Nginx (padrão de produção) em vez de uma imagem monolítica
- Variáveis sensíveis (senhas do MariaDB) via `.env` — nunca hardcoded no `docker-compose.yml`
- Volume nomeado para persistência do MariaDB entre restarts/recreates

**CI/CD (Opção B):**
- Pipeline com dois jobs separados: `test` → `deploy` (deploy só roda se testes passarem)
- Credenciais **exclusivamente** via GitHub Secrets — o YAML não contém nenhuma informação sensível
- `rsync` em vez de `git pull` no servidor: mais seguro (servidor não precisa de acesso ao GitHub) e mais rápido (delta de arquivos)
- `environment: production` no GitHub para aprovação manual antes do deploy em produção

---

## Dificuldades encontradas e como foram resolvidas

Durante o desenvolvimento, algumas abordagens não funcionaram de primeira — registro aqui porque faz parte do processo real de trabalho.

### Ubuntu 25.04 sem suporte a php8.1-fpm

Ao testar os scripts no WSL2, o repositório padrão do Ubuntu 25.04 (Plucky) não oferece `php8.1-fpm`. O `apt install php8.1-fpm` retornava "package not found".

**O que tentei:** adicionar PPA externo (`ondrej/php`) para instalar manualmente.  
**O que funcionou:** reinstalar o WSL2 com Ubuntu 22.04 LTS, que tem suporte nativo ao PHP 8.1 nos repositórios oficiais.

---

### try_files duplicado no vhost gerava erro no nginx -t

O script `criar_hospedagem.sh` gerava um bloco `location ~ \.php$` com `try_files $uri =404;`, mas o arquivo `snippets/fastcgi-php.conf` incluído na mesma location já contém essa diretiva internamente. O `nginx -t` retornava erro de diretiva duplicada e o reload era cancelado (comportamento correto do script).

**O que tentei:** manter a linha e ajustar o include.  
**O que funcionou:** remover `try_files` do bloco PHP gerado pelo script, pois o snippet já o gerencia.

---

### phpinfo() com CSS vazando para a página

A primeira abordagem para exibir o `phpinfo()` foi capturar o HTML com `ob_start()`, remover as tags `<style>` com regex e injetar o conteúdo na página. O resultado era uma tabela quebrada visualmente.

**O que tentei:** filtrar os estilos do phpinfo() com `preg_replace`.  
**O que funcionou:** servir o phpinfo em um arquivo separado (`phpinfo_raw.php`) e renderizá-lo dentro de um `<iframe>`, isolando completamente o CSS.

---

### MariaDB "Access denied" ao usar .env.example sem editar

Ao rodar `docker compose up -d` sem alterar o arquivo `.env` (copiado direto do `.env.example`), o MariaDB inicializava com a senha padrão `troque_esta_senha`. O PHP tentava conectar com a senha padrão `apppass` e recebia "Access denied".

**O que tentei:** corrigir só a senha no PHP.  
**O que funcionou:** passar as variáveis de ambiente do `.env` também para o serviço `php-fpm` no `docker-compose.yml`, garantindo que ambos usem sempre a mesma senha, independente do que estiver no `.env`.

---

## Como testar localmente (Diferencial Docker)

### Opção A — Docker Desktop (Windows/Mac)

Instale o [Docker Desktop](https://www.docker.com/products/docker-desktop/) e siga:

```bash
cd diferencial/

# Copiar variáveis de ambiente
# Linux/WSL:
cp .env.example .env
# Windows (cmd/PowerShell):
copy .env.example .env

# Subir os containers
docker compose up -d

# Acessar no navegador
# http://localhost:8080 → dashboard de infraestrutura + conexão MariaDB

# Parar os containers (dados do MariaDB persistem no volume)
docker compose down

# Parar e remover o volume (limpa tudo)
docker compose down -v
```

### Opção B — Docker Engine no Linux / WSL2 (sem Docker Desktop)

Instale o Docker Engine diretamente no Ubuntu 22.04:

```bash
# Dependências e repositório oficial Docker
apt update && apt install -y ca-certificates curl gnupg
install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" \
  | tee /etc/apt/sources.list.d/docker.list

# Instalar Docker Engine + Compose plugin
apt update && apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin

# Iniciar o serviço
service docker start

# Verificar instalação
docker run hello-world
```

Depois clone e suba o projeto:

```bash
git clone https://github.com/gabrielgblbel/desafio-configr.git
cd desafio-configr/diferencial/
cp .env.example .env
docker compose up -d

# Acessar no navegador (WSL2 compartilha a rede com o Windows)
# http://localhost:8080
```

---

## Como testar os scripts na VM / WSL2 (Partes 1 e 2)

Requer Ubuntu 22.04 — VM local ou WSL2 (`wsl --install -d Ubuntu-22.04`).

```bash
# Clonar o repositório (token de acesso incluso — repositório privado)
git clone https://github.com/gabrielgblbel/desafio-configr.git
cd desafio-configr

# Instalar dependências
sudo apt update && sudo apt install -y nginx php8.1-fpm

# --- Parte 2: script de provisionamento ---
sudo bash parte2/criar_hospedagem.sh meusite.com.br

# Verificar o que foi criado
cat /var/log/criar_hospedagem.log
ls -la /home/cliente_meusite/
ls -la /home/cliente_meusite/public_html/
cat /etc/nginx/sites-available/meusite.com.br

# Testar idempotência (rodar 2x não deve quebrar nada)
sudo bash parte2/criar_hospedagem.sh meusite.com.br

# --- Parte 1: health check ---
sudo bash parte1/health_check.sh
cat /var/log/health_check.log
```

---

## Ambiente de teste

- OS: Ubuntu 22.04 LTS (VM local)
- Nginx: 1.24
- PHP-FPM: 8.1
- Puppet: 7.x (manifest compatível com Puppet 6+)
- Exim: 4.96
- Docker: 29.5.3 / Docker Compose: v5.1.4 (testado em Windows 11 + WSL2)

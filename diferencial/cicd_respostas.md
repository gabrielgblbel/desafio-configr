# Diferencial — CI/CD: Explicações e Decisões

## Pipeline implementado (`.github/workflows/deploy.yml`)

### Fluxo geral

```
push → main
  └─► Job: test
        └─► Job: deploy (só executa se test passar)
```

### Como as credenciais são protegidas

**Nunca** colocamos senhas, chaves SSH ou hostnames no arquivo YAML do pipeline. Todas as informações sensíveis ficam nos **GitHub Secrets** (Settings → Secrets and variables → Actions):

| Secret | Conteúdo |
|---|---|
| `SERVER_HOST` | IP ou hostname do servidor |
| `SERVER_USER` | Usuário SSH de deploy |
| `SSH_PRIVATE_KEY` | Conteúdo da chave privada SSH (id_rsa ou ed25519) |
| `DEPLOY_PATH` | Caminho do diretório da aplicação no servidor |

O `${{ secrets.NOME }}` é substituído pelo runner do GitHub em tempo de execução. O valor nunca aparece nos logs — o GitHub mascara automaticamente.

**Prática adicional:** A chave SSH usada para deploy deve ser uma chave dedicada (não a chave pessoal do desenvolvedor), com permissão restrita apenas ao diretório de deploy no `authorized_keys` do servidor:

```
command="rsync --server ...",no-port-forwarding,no-X11-forwarding,no-agent-forwarding ssh-ed25519 AAAA...
```

### Por que rsync + SSH em vez de git pull no servidor?

- Mais rápido: envia apenas arquivos modificados (delta)
- Mais seguro: o servidor não precisa ter acesso ao GitHub
- Exclui diretórios sensíveis (`.git/`, `tests/`, `.env`) da sincronização

### Alternativa para Laravel/Symfony (sem artisan)

Se a aplicação for PHP puro, remover os comandos `php artisan` do pós-deploy e adaptar para as necessidades reais (ex: limpar cache manualmente, reiniciar PHP-FPM, etc).

### Environment "production" no GitHub

O job `deploy` usa `environment: production`. Isso permite configurar no GitHub:
- Aprovação manual antes do deploy (um humano precisa confirmar)
- Regras de proteção de branch
- Secrets específicos por ambiente (staging vs production)

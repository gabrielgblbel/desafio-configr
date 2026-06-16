# Parte 4 — E-mail e Deliverability (Exim)

## Cenário: E-mails do cliente caindo em spam ou não chegando

---

## 1. Onde verificar os logs do Exim e o que procurar

### Localização dos logs

```bash
# Log principal de entrega (mainlog)
tail -500 /var/log/exim4/mainlog

# Log de rejeições/rejeitos
tail -200 /var/log/exim4/rejectlog

# Log de pânico (erros críticos do daemon)
tail -100 /var/log/exim4/paniclog

# Verificar se há mensagens na fila
exim -bp

# Detalhes de uma mensagem específica na fila (usar ID do mainlog)
exim -Mvl <message-id>

# Tentar reentregas das mensagens na fila
exim -qff
```

### O que procurar no mainlog

```bash
# Erros de autenticação SPF/DKIM
grep -i "dkim\|spf\|dmarc" /var/log/exim4/mainlog | tail -50

# Rejeições com código 550 (permanente) ou 421/451 (temporário)
grep -E " 550 | 421 | 451 " /var/log/exim4/mainlog | tail -50

# Mensagens do domínio do cliente
grep "from=<.*@dominio-cliente.com.br>" /var/log/exim4/mainlog | tail -100

# Tempo de entrega anormalmente alto (indica retry por rejeição temporária)
grep "== <" /var/log/exim4/mainlog | tail -50

# Blacklist explícita no log
grep -iE "blocked|blacklist|listed|spam|rejected" /var/log/exim4/mainlog | tail -50
```

**Padrões importantes no mainlog:**

| Padrão | Significado |
|---|---|
| `<= endereço@dominio` | Mensagem recebida para entrega |
| `=> endereço@destino` | Entrega realizada com sucesso |
| `** endereço@destino` | Entrega falhou permanentemente (bounce) |
| `== endereço@destino` | Entrega em retry (falha temporária) |
| `Frozen` | Mensagem congelada na fila (muitos retries sem sucesso) |

---

## 2. Configurações de DNS a verificar

### SPF (Sender Policy Framework)

Autoriza quais servidores podem enviar e-mail em nome do domínio.

```bash
dig TXT dominio-cliente.com.br | grep "v=spf1"

# Exemplo de registro SPF correto:
# "v=spf1 ip4:203.0.113.10 include:_spf.google.com ~all"

# ~all = softfail (marca como suspeito mas entrega)
# -all = hardfail (rejeita)
```

**O que verificar:**
- Registro SPF existe?
- O IP do servidor de envio está autorizado?
- Não há mais de um registro SPF (múltiplos registros SPF são inválidos)

---

### DKIM (DomainKeys Identified Mail)

Assina as mensagens com chave privada; o destinatário verifica com a chave pública no DNS.

```bash
# Verificar se o registro DKIM existe (seletor padrão "mail" ou "default")
dig TXT mail._domainkey.dominio-cliente.com.br
dig TXT default._domainkey.dominio-cliente.com.br

# Exemplo de registro válido:
# "v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA..."
```

**O que verificar:**
- Registro DKIM existe para o seletor correto?
- A chave pública no DNS corresponde à chave privada usada pelo Exim?
- Chave não expirada ou revogada (`p=` não está vazio)

---

### DMARC (Domain-based Message Authentication, Reporting & Conformance)

Política que define o que fazer com e-mails que falham em SPF ou DKIM.

```bash
dig TXT _dmarc.dominio-cliente.com.br

# Exemplo de registro DMARC:
# "v=DMARC1; p=quarantine; rua=mailto:relatorios@dominio-cliente.com.br; pct=100"
```

**O que verificar:**
- `p=none` → só monitora (não ativa filtro)
- `p=quarantine` → marca como spam
- `p=reject` → rejeita mensagem

---

### PTR / rDNS (Reverse DNS)

O IP do servidor de envio deve ter PTR record que resolva para o hostname do servidor.

```bash
# Descobrir o IP de saída
curl -s https://ipinfo.io/ip
# ou
hostname -I

# Verificar PTR do IP (ex: IP 203.0.113.10 → verificar 10.113.0.203.in-addr.arpa)
dig -x 203.0.113.10

# O PTR deve resolver para o hostname; e o hostname deve resolver de volta para o IP (FCrDNS)
dig A nome-retornado-pelo-ptr.exemplo.com
```

**O que verificar:**
- PTR existe e responde com hostname do servidor
- Hostname do PTR tem registro A apontando de volta para o IP (Forward-Confirmed rDNS)
- PTR não é genérico (ex: `static.203-0-113-10.provedor.com.br` pode ser aceito, mas hostname próprio é melhor)

---

## 3. Identificar se o IP está em blacklist

### Sintomas no mainlog

```
SMTP error from remote mail server after MAIL FROM:
    550 5.7.1 Service unavailable; Client host [203.0.113.10]
    blocked using zen.spamhaus.org
```

### Verificação via DNS (manual)

```bash
# Formatar o IP invertido: 203.0.113.10 → 10.113.0.203

# Spamhaus ZEN (combina SBL, XBL, PBL)
dig +short 10.113.0.203.zen.spamhaus.org
# Resposta vazia = não listado; 127.0.0.x = listado

# SpamCop
dig +short 10.113.0.203.bl.spamcop.net

# Barracuda
dig +short 10.113.0.203.b.barracudacentral.org
```

### Verificação via ferramentas web

- **MXToolbox:** https://mxtoolbox.com/blacklists.aspx (verifica 100+ listas de uma vez)
- **MultiRBL:** https://multirbl.valli.org/
- **Spamhaus lookup:** https://check.spamhaus.org/

---

## 4. Diferenciando problema de configuração vs. reputação de IP

### Problema de configuração

**Indicadores:**
- Erros consistentes em todos os destinos (Gmail, Outlook, Yahoo)
- Mensagens de erro relacionadas a SPF/DKIM/DMARC: `550 SPF check failed`, `DKIM signature missing`
- Exim rejeita na etapa de autenticação, não de entrega
- Erro mesmo para e-mails enviados para domínios pequenos sem filtros rígidos

**Diagnóstico:**
```bash
# Testar SPF online
# https://www.mail-tester.com (envia e-mail para endereço deles, mostra score)

# Verificar se Exim está assinando com DKIM
grep -i dkim /etc/exim4/exim4.conf.template
exim -bh IP_TESTE  # simula conexão SMTP

# Testar envio e verificar headers do e-mail recebido
# Header "Authentication-Results:" mostra resultado de SPF/DKIM/DMARC
```

---

### Problema de reputação de IP

**Indicadores:**
- Apenas grandes provedores rejeitam (Gmail, Outlook, Yahoo, Hotmail)
- Pequenos domínios recebem normalmente
- Bounce code: `550 5.7.1` com menção explícita a blacklist ou reputação
- IP recém-alocado (nunca teve reputação) ou IP compartilhado com spam histórico

**Diagnóstico:**
```bash
# Verificar se IP está em blacklists (comandos acima)
# Verificar histórico via MXToolbox

# Verificar se há volume anormal de envio saindo do servidor
grep "<=" /var/log/exim4/mainlog | awk '{print $5}' | sort | uniq -c | sort -rn | head -20
# Alto volume por um único remetente pode indicar spam ou formulário comprometido

# Verificar se há processos PHP enviando e-mail sem limite
grep "from=<>" /var/log/exim4/mainlog | tail -50
# Bounces excessivos de endereços inválidos indicam spam saindo do servidor
```

---

## 5. Comunicação com o cliente durante a investigação

*Exemplo de mensagem para cliente não técnico:*

---

> **Assunto:** Investigação em andamento — problema com envio de e-mails
>
> Olá [Nome do cliente],
>
> Recebi seu chamado e já iniciei a investigação. Entendo que isso está afetando diretamente o seu negócio e quero mantê-lo informado.
>
> **O que eu já sei:**
> Os e-mails estão sendo enviados pelo seu servidor, mas alguns provedores (como Gmail e Outlook) estão recusando ou marcando como spam antes de exibir para o destinatário.
>
> **O que estou verificando:**
> - Se o servidor tem as configurações de segurança de e-mail corretas (chamadas SPF, DKIM e DMARC — são como uma "assinatura digital" que prova que o e-mail realmente veio do seu domínio).
> - Se o endereço IP do servidor está em alguma lista de bloqueio (como uma lista negra usada por provedores para filtrar spam).
>
> **O que pode ter causado:**
> Às vezes isso acontece por configuração incompleta, ou porque o servidor compartilhou um IP que foi usado indevidamente no passado.
>
> **Próximos passos:**
> Vou concluir o diagnóstico nas próximas horas e voltar com um plano de ação. Enquanto isso, se precisar enviar e-mails urgentes, recomendo usar temporariamente uma conta de e-mail profissional (como Gmail Workspace ou Outlook 365) para garantir a entrega.
>
> Qualquer dúvida, estou à disposição.
>
> Atenciosamente,
> [Seu nome]

---

**Pontos-chave na comunicação:**
1. Confirmar recebimento imediato — cliente sente que foi ouvido
2. Linguagem acessível, sem jargão técnico (SPF/DKIM explicados por analogia)
3. Transparência sobre o que está sendo investigado
4. Alternativa paliativa enquanto o problema é resolvido
5. Prazo claro para retorno com diagnóstico completo

# Parte 3 — Gerenciamento de Configuração (Puppet)

---

## 1. Como organizar um manifest Puppet para Nginx em toda a frota

A abordagem mais organizada é usar o padrão **module + roles & profiles**:

```
modules/
├── nginx/
│   ├── manifests/
│   │   ├── init.pp          # class nginx principal
│   │   ├── vhost.pp         # defined type para virtual hosts
│   │   └── config.pp        # gerencia nginx.conf e conf.d/
│   ├── templates/
│   │   ├── nginx.conf.epp   # template EPP do nginx.conf
│   │   └── vhost.conf.epp   # template de virtual host
│   └── files/
│       └── default.conf     # arquivo estático de fallback
profiles/
├── manifests/
│   └── webserver.pp         # profile que instancia o módulo nginx
roles/
├── manifests/
│   └── web.pp               # role que compõe profiles
```

**Exemplo de `modules/nginx/manifests/init.pp`:**

```puppet
class nginx (
  String  $package_name   = 'nginx',
  String  $service_name   = 'nginx',
  String  $config_path    = '/etc/nginx/nginx.conf',
  String  $worker_processes = 'auto',
  Integer $worker_connections = 1024,
) {

  package { $package_name:
    ensure => installed,
  }

  file { $config_path:
    ensure  => file,
    owner   => 'root',
    group   => 'root',
    mode    => '0644',
    content => epp('nginx/nginx.conf.epp', {
      worker_processes   => $worker_processes,
      worker_connections => $worker_connections,
    }),
    require => Package[$package_name],
    notify  => Service[$service_name],
  }

  service { $service_name:
    ensure  => running,
    enable  => true,
    require => [Package[$package_name], File[$config_path]],
  }
}
```

**Profile que usa o módulo:**

```puppet
# profiles/manifests/webserver.pp
class profiles::webserver {
  include nginx
}
```

**Role que compõe profiles:**

```puppet
# roles/manifests/web.pp
class roles::web {
  include profiles::webserver
  include profiles::php_fpm
}
```

No `site.pp`:

```puppet
node /^web-/ {
  include roles::web
}
```

---

## 2. Como o Puppet garante idempotência

O Puppet opera no paradigma de **estado desejado** (*desired state*): cada resource descreve *como o sistema deve estar*, não *o que fazer*. O agente Puppet compara o estado atual do sistema com o declarado e age somente se houver divergência.

**Exemplos práticos:**

| Resource | Primeira execução | Segunda execução |
|---|---|---|
| `package { 'nginx': ensure => installed }` | Instala o Nginx | Verifica: já instalado → faz nada |
| `file { '/etc/nginx/nginx.conf': content => ... }` | Escreve o arquivo | Verifica checksum → igual → faz nada |
| `service { 'nginx': ensure => running }` | Inicia o serviço | Verifica: já rodando → faz nada |

Se o manifest rodar duas vezes num servidor já configurado, o Puppet simplesmente confirma que o estado desejado está satisfeito e não executa nenhuma ação. Isso é diferente de um shell script imperativo que recriaria arquivos ou reinstalaria pacotes.

A relação `notify`/`subscribe` entre resources também é inteligente: o serviço só é reiniciado se o arquivo de configuração **realmente mudou** (novo checksum), não a cada execução.

---

## 3. Como lidar com diferenças entre grupos de servidores

**Abordagem 1 — Hiera (recomendada)**

Hiera é o mecanismo nativo do Puppet para separar dados de código. Permite que o mesmo manifest produza comportamentos diferentes com base em fatos do nó (hostname, datacenter, OS, role customizada).

Hierarquia de exemplo (`hiera.yaml`):

```yaml
hierarchy:
  - name: "Nó específico"
    path: "nodes/%{trusted.certname}.yaml"
  - name: "Grupo por role"
    path: "roles/%{facts.custom_role}.yaml"
  - name: "Sistema operacional"
    path: "os/%{facts.os.name}.yaml"
  - name: "Comum"
    path: "common.yaml"
```

Dados por grupo:

```yaml
# data/roles/webserver_nginx.yaml
nginx::package_name: nginx

# data/roles/webserver_litespeed.yaml
# Não inclui nginx — inclui litespeed
litespeed::package_name: lsws
```

**Abordagem 2 — Condicional no manifest**

```puppet
# site.pp ou profile
case $facts['custom_server_role'] {
  'nginx':      { include nginx }
  'litespeed':  { include litespeed }
  default:      { notify { "Role desconhecida: ${facts['custom_server_role']}": } }
}
```

**Abordagem 3 — Roles distintas**

Criar roles separadas para cada grupo de servidor:

```puppet
# roles/manifests/web_nginx.pp
class roles::web_nginx {
  include profiles::webserver_nginx
}

# roles/manifests/web_litespeed.pp
class roles::web_litespeed {
  include profiles::webserver_litespeed
}
```

E classificar os nós via ENC (External Node Classifier) ou Hiera.

---

## Bônus — Manifest simples com template

Veja o arquivo [`nginx.pp`](./nginx.pp) nesta pasta.

# nginx.pp — Manifest Puppet que instala o Nginx, garante que o serviço está rodando
# e gerencia o arquivo de configuração principal a partir de um template EPP.
#
# Uso: puppet apply nginx.pp
# Requer: arquivo de template em templates/nginx.conf.epp (relativo ao módulo)
#         ou substituir 'content => epp(...)' por 'source => ...' para arquivo estático.

class nginx (
  String  $package_name      = 'nginx',
  String  $service_name      = 'nginx',
  String  $config_path       = '/etc/nginx/nginx.conf',
  Integer $worker_processes  = 1,
  Integer $worker_connections = 1024,
  String  $server_tokens     = 'off',
) {

  # 1. Garante que o pacote está instalado
  package { $package_name:
    ensure => installed,
  }

  # 2. Gerencia o arquivo de configuração principal via template inline
  #    Em produção, usar epp('nginx/nginx.conf.epp') apontando para o template do módulo.
  file { $config_path:
    ensure  => file,
    owner   => 'root',
    group   => 'root',
    mode    => '0644',
    content => @("NGINX_CONF"),
      # Gerenciado pelo Puppet — não editar manualmente
      user  nginx;
      worker_processes ${worker_processes};
      error_log /var/log/nginx/error.log warn;
      pid       /var/run/nginx.pid;

      events {
          worker_connections ${worker_connections};
      }

      http {
          server_tokens ${server_tokens};
          include       /etc/nginx/mime.types;
          default_type  application/octet-stream;

          log_format main '\$remote_addr - \$remote_user [\$time_local] "\$request" '
                          '\$status \$body_bytes_sent "\$http_referer" '
                          '"\$http_user_agent" "\$http_x_forwarded_for"';

          access_log /var/log/nginx/access.log main;

          sendfile    on;
          tcp_nopush  on;
          keepalive_timeout 65;
          gzip on;

          include /etc/nginx/conf.d/*.conf;
          include /etc/nginx/sites-enabled/*;
      }
      | NGINX_CONF
    require => Package[$package_name],
    notify  => Service[$service_name],
  }

  # Garante que o diretório sites-enabled existe
  file { '/etc/nginx/sites-enabled':
    ensure  => directory,
    owner   => 'root',
    group   => 'root',
    mode    => '0755',
    require => Package[$package_name],
  }

  # 3. Garante que o serviço está rodando e habilitado no boot
  #    O serviço só é reiniciado se o arquivo de configuração mudar (notify acima)
  service { $service_name:
    ensure  => running,
    enable  => true,
    require => [
      Package[$package_name],
      File[$config_path],
    ],
  }

}

# Instancia a classe com valores padrão
# Em um ambiente real, os parâmetros seriam fornecidos via Hiera
include nginx

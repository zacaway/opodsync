{
  description = "oPodSync development environment with nginx and php-fpm";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs = { self, nixpkgs, flake-utils }:
    flake-utils.lib.eachDefaultSystem (system:
      let
        pkgs = nixpkgs.legacyPackages.${system};

        php = pkgs.php83.buildEnv {
          extensions = ({ enabled, all }: enabled ++ (with all; [
            sqlite3
            pdo_sqlite
          ]));
          extraConfig = ''
            display_errors = On
            error_reporting = E_ALL
            date.timezone = UTC
          '';
        };

        # Runtime directory for sockets and pid files
        runtimeDir = "$PWD/.nix-php-runtime";

        # Start script for development server
        startScript = pkgs.writeShellScriptBin "dev-server" ''
          set -e

          RUNTIME_DIR="${runtimeDir}"
          PROJECT_DIR="$PWD"
          SERVER_DIR="$PROJECT_DIR/server"

          # Create runtime directory
          mkdir -p "$RUNTIME_DIR"

          # Create data directory if it doesn't exist
          mkdir -p "$SERVER_DIR/data"

          # Generate nginx config
          cat > "$RUNTIME_DIR/nginx.conf" << EOF
          worker_processes 1;
          error_log $RUNTIME_DIR/nginx-error.log;
          pid $RUNTIME_DIR/nginx.pid;
          daemon off;

          events {
              worker_connections 1024;
          }

          http {
              include ${pkgs.nginx}/conf/mime.types;
              default_type application/octet-stream;

              access_log $RUNTIME_DIR/nginx-access.log;

              server {
                  listen 8080;
                  server_name localhost;
                  root $SERVER_DIR;
                  index index.php index.html;

                  # Protect sensitive directories
                  location ~ ^/(lib|data|templates|sql)/ {
                      return 404;
                  }

                  location / {
                      try_files \$uri \$uri/ /index.php?\$query_string;
                  }

                  location ~ \.php$ {
                      fastcgi_pass unix:$RUNTIME_DIR/php-fpm.sock;
                      fastcgi_index index.php;
                      fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
                      include ${pkgs.nginx}/conf/fastcgi_params;
                      # Pass Authorization header to PHP
                      fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
                  }
              }
          }
          EOF

          # Generate php-fpm config
          cat > "$RUNTIME_DIR/php-fpm.conf" << EOF
          [global]
          error_log = $RUNTIME_DIR/php-fpm-error.log
          pid = $RUNTIME_DIR/php-fpm.pid
          daemonize = no

          [www]
          listen = $RUNTIME_DIR/php-fpm.sock
          listen.mode = 0666
          pm = dynamic
          pm.max_children = 5
          pm.start_servers = 2
          pm.min_spare_servers = 1
          pm.max_spare_servers = 3
          EOF

          # Cleanup function
          cleanup() {
              echo "Shutting down..."
              [ -f "$RUNTIME_DIR/nginx.pid" ] && kill $(cat "$RUNTIME_DIR/nginx.pid") 2>/dev/null || true
              [ -f "$RUNTIME_DIR/php-fpm.pid" ] && kill $(cat "$RUNTIME_DIR/php-fpm.pid") 2>/dev/null || true
              rm -rf "$RUNTIME_DIR"
              exit 0
          }
          trap cleanup SIGINT SIGTERM

          echo "Starting PHP-FPM..."
          ${php}/bin/php-fpm -F -y "$RUNTIME_DIR/php-fpm.conf" &
          PHP_FPM_PID=$!

          # Wait for socket
          for i in $(seq 1 30); do
              [ -S "$RUNTIME_DIR/php-fpm.sock" ] && break
              sleep 0.1
          done

          echo "Starting nginx on http://localhost:8080"
          ${pkgs.nginx}/bin/nginx -e "$RUNTIME_DIR/nginx-error.log" -c "$RUNTIME_DIR/nginx.conf" &
          NGINX_PID=$!

          echo "Development server running. Press Ctrl+C to stop."
          wait
        '';

        stopScript = pkgs.writeShellScriptBin "dev-server-stop" ''
          RUNTIME_DIR="${runtimeDir}"
          [ -f "$RUNTIME_DIR/nginx.pid" ] && kill $(cat "$RUNTIME_DIR/nginx.pid") 2>/dev/null
          [ -f "$RUNTIME_DIR/php-fpm.pid" ] && kill $(cat "$RUNTIME_DIR/php-fpm.pid") 2>/dev/null
          rm -rf "$RUNTIME_DIR"
          echo "Server stopped."
        '';

      in {
        devShells.default = pkgs.mkShell {
          buildInputs = [
            php
            pkgs.nginx
            php.packages.composer
            startScript
            stopScript
          ];

          shellHook = ''
            export PS1="\[\033[1;35m\](nix)\[\033[0m\] $PS1"

            echo "oPodSync development environment ready!"
            echo ""
            echo "Available commands:"
            echo "  dev-server      - Start nginx + php-fpm on http://localhost:8080"
            echo "  dev-server-stop - Stop the development server"
            echo "  php             - PHP CLI"
            echo "  composer        - Composer package manager"
            echo ""
            echo "First time setup:"
            echo "  1. Copy config.dist.php to server/data/config.local.php"
            echo "  2. Edit config.local.php as needed"
            echo "  3. Run dev-server"
            echo ""
          '';
        };
      });
}

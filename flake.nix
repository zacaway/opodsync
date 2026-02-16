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
            pdo_mysql
            mysqlnd
          ]));
          extraConfig = ''
            display_errors = On
            error_reporting = E_ALL
            date.timezone = UTC
          '';
        };

        mariadb = pkgs.mariadb;
        mysqlDataDir = "$PWD/.nix-mysql-data";
        mysqlSocket = "$PWD/.nix-mysql-data/mysql.sock";
        mysqlPort = "3307";

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

        # Start MariaDB server
        mysqlStartScript = pkgs.writeShellScriptBin "dev-mysql" ''
          set -e

          DATADIR="${mysqlDataDir}"
          SOCKET="${mysqlSocket}"
          PORT="${mysqlPort}"

          if [ -f "$DATADIR/mysqld.pid" ] && kill -0 $(cat "$DATADIR/mysqld.pid") 2>/dev/null; then
            echo "MariaDB is already running (PID $(cat "$DATADIR/mysqld.pid"))"
            echo "  Socket: $SOCKET"
            echo "  Port:   $PORT"
            exit 0
          fi

          if [ ! -d "$DATADIR/mysql" ]; then
            echo "Initializing MariaDB data directory..."
            ${mariadb}/bin/mysql_install_db \
              --datadir="$DATADIR" \
              --basedir="${mariadb}" \
              --auth-root-authentication-method=normal \
              2>&1 | tail -1
          fi

          echo "Starting MariaDB on port $PORT..."
          ${mariadb}/bin/mysqld \
            --datadir="$DATADIR" \
            --socket="$SOCKET" \
            --port="$PORT" \
            --pid-file="$DATADIR/mysqld.pid" \
            --skip-networking=0 \
            --bind-address=127.0.0.1 \
            &

          # Wait for server to be ready
          for i in $(seq 1 50); do
            if ${mariadb}/bin/mysqladmin --socket="$SOCKET" ping 2>/dev/null | grep -q alive; then
              break
            fi
            sleep 0.1
          done

          if ! ${mariadb}/bin/mysqladmin --socket="$SOCKET" ping 2>/dev/null | grep -q alive; then
            echo "ERROR: MariaDB failed to start"
            exit 1
          fi

          # Set root password and create test database
          # Try without password first (fresh init), then with password (existing data dir)
          MYSQL="${mariadb}/bin/mysql"
          if $MYSQL --socket="$SOCKET" -u root -e "SELECT 1" 2>/dev/null; then
            MYSQL_AUTH="$MYSQL --socket=$SOCKET -u root"
          else
            MYSQL_AUTH="$MYSQL --socket=$SOCKET -u root -proot"
          fi

          $MYSQL_AUTH -e "
            ALTER USER 'root'@'localhost' IDENTIFIED BY 'root';
            FLUSH PRIVILEGES;
            CREATE DATABASE IF NOT EXISTS opodsync_test;
          " 2>/dev/null

          echo "MariaDB is running."
          echo "  Socket: $SOCKET"
          echo "  Port:   $PORT"
          echo "  User:   root"
          echo "  Pass:   root"
          echo "  DB:     opodsync_test"
          echo ""
          echo "Run integration tests with MySQL:"
          echo "  DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_PORT=$PORT DB_USER=root DB_PASSWORD=root DB_NAME=opodsync_test php test/start.php"
          echo ""
          echo "Connect manually:"
          echo "  mysql --socket=$SOCKET -u root -proot opodsync_test"
        '';

        # Stop MariaDB server
        mysqlStopScript = pkgs.writeShellScriptBin "dev-mysql-stop" ''
          DATADIR="${mysqlDataDir}"
          SOCKET="${mysqlSocket}"

          if [ -f "$DATADIR/mysqld.pid" ]; then
            PID=$(cat "$DATADIR/mysqld.pid")
            if kill -0 "$PID" 2>/dev/null; then
              ${mariadb}/bin/mysqladmin --socket="$SOCKET" -u root -proot shutdown 2>/dev/null || kill "$PID" 2>/dev/null
              echo "MariaDB stopped."
            else
              echo "MariaDB is not running (stale pid file)."
              rm -f "$DATADIR/mysqld.pid"
            fi
          else
            echo "MariaDB is not running."
          fi
        '';

        # Run integration tests against MySQL
        mysqlTestScript = pkgs.writeShellScriptBin "dev-mysql-test" ''
          set -e

          SOCKET="${mysqlSocket}"
          PORT="${mysqlPort}"

          if ! ${mariadb}/bin/mysqladmin --socket="$SOCKET" -u root -proot ping 2>/dev/null | grep -q alive; then
            echo "MariaDB is not running. Starting it..."
            dev-mysql
          fi

          # Reset the test database
          echo "Resetting opodsync_test database..."
          ${mariadb}/bin/mysql --socket="$SOCKET" -u root -proot -e "DROP DATABASE IF EXISTS opodsync_test; CREATE DATABASE opodsync_test;"

          echo "Running integration tests with MySQL..."
          DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_PORT="$PORT" DB_USER=root DB_PASSWORD=root DB_NAME=opodsync_test \
            ${php}/bin/php test/start.php
        '';

      in {
        devShells.default = pkgs.mkShell {
          buildInputs = [
            php
            pkgs.nginx
            mariadb
            php.packages.composer
            startScript
            stopScript
            mysqlStartScript
            mysqlStopScript
            mysqlTestScript
          ];

          shellHook = ''
            export PS1="\[\033[1;35m\](nix)\[\033[0m\] $PS1"

            echo "oPodSync development environment ready!"
            echo ""
            echo "Available commands:"
            echo "  dev-server      - Start nginx + php-fpm on http://localhost:8080"
            echo "  dev-server-stop - Stop the development server"
            echo "  dev-mysql       - Start local MariaDB on port 3307"
            echo "  dev-mysql-stop  - Stop local MariaDB"
            echo "  dev-mysql-test  - Run integration tests against MariaDB"
            echo "  php             - PHP CLI"
            echo "  composer        - Composer package manager"
            echo "  mysql           - MariaDB client"
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

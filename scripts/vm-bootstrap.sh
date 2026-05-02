#!/usr/bin/env bash
# =============================================================================
# Emprenddi — VM Bootstrap Script
# Pegar en SSH de la VM una vez, recién creada (Debian 12).
# Idempotente: se puede re-ejecutar sin problemas.
# =============================================================================

set -euo pipefail

REPO_URL="https://github.com/tecmaxsas/laravel-emprenddi.git"
PROJECT_DIR="/opt/emprenddi"
DOMAIN="pos.emprenddi.com"

echo "==> 1. Actualizando sistema..."
sudo apt update
sudo apt upgrade -y

echo "==> 2. Instalando dependencias base..."
sudo apt install -y \
    ca-certificates \
    curl \
    gnupg \
    git \
    make \
    ufw \
    certbot

echo "==> 3. Instalando Docker (si no está)..."
if ! command -v docker &> /dev/null; then
    curl -fsSL https://get.docker.com | sudo sh
    sudo usermod -aG docker "$USER"
    echo "    Docker instalado. NECESITAS hacer logout/login para usar docker sin sudo."
else
    echo "    Docker ya instalado: $(docker --version)"
fi

echo "==> 4. Verificando Docker Compose plugin..."
if ! docker compose version &> /dev/null; then
    sudo apt install -y docker-compose-plugin
fi
docker compose version

echo "==> 5. Configurando UFW (firewall del sistema)..."
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw --force enable
sudo ufw status

echo "==> 6. Clonando repositorio en $PROJECT_DIR..."
if [ ! -d "$PROJECT_DIR" ]; then
    sudo mkdir -p "$PROJECT_DIR"
    sudo chown "$USER:$USER" "$PROJECT_DIR"
    git clone "$REPO_URL" "$PROJECT_DIR"
else
    echo "    Repo ya existe. Actualizando..."
    cd "$PROJECT_DIR"
    git pull origin main
fi

cd "$PROJECT_DIR"

echo "==> 7. Configurando .env.production..."
if [ ! -f .env.production ]; then
    cp .env.production.example .env.production
    echo "    .env.production creado desde template."
    echo "    !!! IMPORTANTE: edita /opt/emprenddi/.env.production y rellena:"
    echo "        - APP_KEY (lo generamos abajo)"
    echo "        - DB_PASSWORD (password fuerte para postgres)"
    echo "        - REDIS_PASSWORD (password fuerte para redis)"
    echo "        - MAIL_* (credenciales SMTP)"
else
    echo "    .env.production ya existe — no se sobrescribe."
fi

echo "==> 8. Preparando directorio para certbot webroot..."
sudo mkdir -p /var/www/certbot
sudo chown -R www-data:www-data /var/www/certbot

echo ""
echo "============================================================="
echo "✅ Bootstrap completado."
echo ""
echo "PRÓXIMOS PASOS (en orden):"
echo ""
echo "1. Si Docker se acaba de instalar, haz LOGOUT y vuelve a entrar"
echo "   por SSH para que tu usuario tenga permisos del grupo docker."
echo ""
echo "2. Edita /opt/emprenddi/.env.production con secretos reales:"
echo "   nano /opt/emprenddi/.env.production"
echo ""
echo "3. Genera APP_KEY:"
echo "   cd /opt/emprenddi"
echo "   docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show"
echo "   (copia el resultado a APP_KEY= en .env.production)"
echo ""
echo "4. Verifica que el DNS de $DOMAIN apunta a esta IP:"
echo "   dig +short $DOMAIN"
echo "   IP esperada: $(curl -s ifconfig.me)"
echo ""
echo "5. Cuando DNS esté propagado, obtén certificado SSL:"
echo "   sudo bash /opt/emprenddi/scripts/setup-ssl.sh"
echo ""
echo "6. Levanta el stack de producción:"
echo "   bash /opt/emprenddi/scripts/deploy.sh"
echo "============================================================="

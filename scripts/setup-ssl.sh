#!/usr/bin/env bash
# =============================================================================
# Emprenddi — Setup SSL (Let's Encrypt) para pos.emprenddi.com
# Pre-requisitos:
#   - DNS de pos.emprenddi.com debe apuntar a la IP pública de esta VM
#   - Puerto 80 abierto (UFW + GCP firewall)
#   - certbot instalado (lo hace vm-bootstrap.sh)
# Idempotente: si el cert ya existe y es válido, no hace nada.
# =============================================================================

set -euo pipefail

DOMAIN="pos.emprenddi.com"
EMAIL="${SSL_ADMIN_EMAIL:-juandavid@triadify.com}"
PROJECT_DIR="/opt/emprenddi"

if [ "$EUID" -ne 0 ]; then
    echo "Este script debe ejecutarse con sudo."
    exit 1
fi

echo "==> Verificando que DNS de $DOMAIN resuelve a la IP de esta VM..."
EXPECTED_IP=$(curl -s ifconfig.me)
DNS_IP=$(dig +short "$DOMAIN" | tail -n1)

if [ "$EXPECTED_IP" != "$DNS_IP" ]; then
    echo "  ⚠️  ADVERTENCIA: DNS no apunta correctamente."
    echo "     IP de esta VM:     $EXPECTED_IP"
    echo "     IP en DNS:         $DNS_IP"
    echo "     Espera unos minutos para propagación o revisa el registro A."
    echo "     Continuar de todos modos? (s/N)"
    read -r CONFIRM
    if [ "$CONFIRM" != "s" ] && [ "$CONFIRM" != "S" ]; then
        exit 1
    fi
fi

echo "==> Si nginx (Docker) está corriendo, lo detenemos temporalmente..."
if docker ps --format '{{.Names}}' | grep -q '^emprenddi_nginx$'; then
    cd "$PROJECT_DIR"
    docker compose -f docker-compose.prod.yml stop nginx
    NGINX_WAS_UP=1
else
    NGINX_WAS_UP=0
fi

echo "==> Solicitando certificado a Let's Encrypt (modo standalone, puerto 80)..."
certbot certonly \
    --standalone \
    --non-interactive \
    --agree-tos \
    --email "$EMAIL" \
    -d "$DOMAIN"

echo "==> Verificando certificado emitido..."
ls -la "/etc/letsencrypt/live/$DOMAIN/"

echo "==> Configurando renovación automática con hook para reiniciar nginx Docker..."
RENEW_HOOK="/etc/letsencrypt/renewal-hooks/deploy/reload-emprenddi-nginx.sh"
mkdir -p "$(dirname "$RENEW_HOOK")"
cat > "$RENEW_HOOK" <<'EOF'
#!/usr/bin/env bash
cd /opt/emprenddi && docker compose -f docker-compose.prod.yml restart nginx || true
EOF
chmod +x "$RENEW_HOOK"

echo "==> Probando renovación (--dry-run)..."
certbot renew --dry-run

if [ "$NGINX_WAS_UP" = "1" ]; then
    echo "==> Re-iniciando nginx..."
    cd "$PROJECT_DIR"
    docker compose -f docker-compose.prod.yml start nginx
fi

echo ""
echo "✅ SSL configurado. Cert válido hasta:"
openssl x509 -enddate -noout -in "/etc/letsencrypt/live/$DOMAIN/fullchain.pem"
echo ""
echo "Renovación automática: certbot.timer (systemd) corre 2x al día."
echo "Verificar con: systemctl list-timers certbot"

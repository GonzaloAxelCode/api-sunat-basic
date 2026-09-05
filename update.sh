#!/bin/bash

set -Eeuo pipefail

# ============================================================
# CONFIGURACIÓN
# ============================================================

APP_NAME="api-sunat-basic"
BRANCH="main"
COMPOSE_FILE="docker-compose.yml"
SERVICE_NAME="api"
LOG_LINES=50

# ============================================================
# COLORES
# ============================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ============================================================
# FUNCIONES
# ============================================================

log() {
    echo -e "${BLUE}[$(date '+%Y-%m-%d %H:%M:%S')]${NC} $1"
}

success() {
    echo -e "${GREEN}✅ $1${NC}"
}

warning() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

error() {
    echo -e "${RED}❌ $1${NC}"
}

fail() {
    error "$1"
    exit 1
}

# ============================================================
# DIRECTORIO DEL SCRIPT
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ============================================================
# ENTORNO
# ============================================================

log "Verificando entorno..."

[ -d ".git" ] || fail "Este directorio no es un repositorio Git."
[ -f "$COMPOSE_FILE" ] || fail "No se encontró $COMPOSE_FILE."

if [ ! -f ".env" ]; then
    warning "Archivo .env no encontrado."

    if [ -f ".env.example" ]; then
        echo
        echo "Puedes crear .env con:"
        echo
        echo "  cp .env.example .env"
        echo "  nano .env"
        echo
    fi

    exit 1
fi

success "Entorno verificado."

# ============================================================
# DOCKER
# ============================================================

log "Verificando Docker..."

command -v docker >/dev/null 2>&1 \
    || fail "Docker no está instalado."

docker info >/dev/null 2>&1 \
    || fail "Docker no está disponible o el usuario no tiene permisos."

docker compose version >/dev/null 2>&1 \
    || fail "Docker Compose no está disponible."

success "Docker disponible."

# ============================================================
# GIT
# ============================================================

log "Verificando Git..."

CURRENT_BRANCH="$(git branch --show-current)"

if [ "$CURRENT_BRANCH" != "$BRANCH" ]; then
    fail "No estás en la rama $BRANCH. Rama actual: $CURRENT_BRANCH"
fi

OLD_COMMIT="$(git rev-parse HEAD)"

echo
echo "Commit actual:"
echo "  $OLD_COMMIT"
echo

# ============================================================
# CAMBIOS LOCALES
# ============================================================

if [ -n "$(git status --porcelain)" ]; then

    warning "Hay cambios locales:"
    echo

    git status --short

    echo
    fail "No se continuará para evitar sobrescribir cambios locales."
fi

success "Working tree limpio."

# ============================================================
# FETCH
# ============================================================

log "Obteniendo cambios desde GitHub..."

if ! git fetch origin "$BRANCH"; then
    fail "No se pudieron obtener los cambios desde GitHub."
fi

REMOTE_COMMIT="$(git rev-parse "origin/$BRANCH")"

echo
echo "Commit local : $OLD_COMMIT"
echo "Commit remoto: $REMOTE_COMMIT"
echo

# ============================================================
# YA ESTÁ ACTUALIZADO
# ============================================================

if [ "$OLD_COMMIT" = "$REMOTE_COMMIT" ]; then

    success "El servidor ya está actualizado."

    echo
    echo "No es necesario reconstruir Docker."
    echo

    echo "============================================================"
    echo " ESTADO ACTUAL"
    echo "============================================================"
    echo

    docker compose ps || true

    echo
    echo "============================================================"
    echo " ÚLTIMOS LOGS"
    echo "============================================================"
    echo

    docker compose logs --tail="$LOG_LINES" "$SERVICE_NAME" || true

    echo
    success "No hay cambios nuevos para desplegar."
    exit 0
fi

# ============================================================
# VERIFICAR FAST-FORWARD
# ============================================================

if ! git merge-base --is-ancestor "$OLD_COMMIT" "$REMOTE_COMMIT"; then

    error "La actualización no es un fast-forward."

    echo
    echo "Tu servidor y GitHub tienen historias divergentes."
    echo "No se realizará ningún cambio automáticamente."
    echo
    echo "Revisa con:"
    echo
    echo "  git log --oneline --graph --decorate --all -20"
    echo

    exit 1
fi

# ============================================================
# ACTUALIZAR CÓDIGO
# ============================================================

log "Hay cambios nuevos en GitHub."
log "Actualizando código..."

if ! git merge --ff-only "origin/$BRANCH"; then
    fail "No se pudo actualizar el código."
fi

NEW_COMMIT="$(git rev-parse HEAD)"

success "Código actualizado."

echo
echo "Antes:"
echo "  $OLD_COMMIT"
echo
echo "Ahora:"
echo "  $NEW_COMMIT"
echo

# ============================================================
# VALIDAR ARCHIVOS
# ============================================================

log "Validando archivos del proyecto..."

REQUIRED_FILES=(
    "composer.json"
    "composer.lock"
    "Dockerfile"
    "$COMPOSE_FILE"
)

for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        fail "Falta el archivo requerido: $file"
    fi
done

success "Archivos requeridos encontrados."

# ============================================================
# BUILD
# ============================================================

log "Construyendo nueva imagen Docker..."

echo
echo "El contenedor actual NO será detenido durante el build."
echo

if ! docker compose build --pull; then
    error "El Docker build falló."
    echo
    echo "El contenedor anterior no fue detenido."
    echo "El servicio existente debería continuar funcionando."
    exit 1
fi

success "Nueva imagen construida correctamente."

# ============================================================
# DEPLOY
# ============================================================

log "Actualizando contenedor..."

if ! docker compose up -d --remove-orphans; then
    fail "No se pudo actualizar el contenedor."
fi

success "Contenedor iniciado."

# ============================================================
# ESPERAR CONTENEDOR
# ============================================================

log "Esperando que el contenedor esté listo..."

CONTAINER_ID="$(docker compose ps -q "$SERVICE_NAME")"

if [ -z "$CONTAINER_ID" ]; then
    error "No se pudo obtener el ID del contenedor."
    docker compose ps || true
    exit 1
fi

MAX_ATTEMPTS=30
ATTEMPT=1

while [ "$ATTEMPT" -le "$MAX_ATTEMPTS" ]; do

    STATUS="$(
        docker inspect \
            --format='{{.State.Status}}' \
            "$CONTAINER_ID" 2>/dev/null || true
    )"

    if [ "$STATUS" = "running" ]; then
        success "Contenedor ejecutándose."
        break
    fi

    if [ "$STATUS" = "exited" ] || [ "$STATUS" = "dead" ]; then

        error "El contenedor terminó inesperadamente."

        echo
        docker compose ps || true

        echo
        echo "============================================================"
        echo " LOGS DEL CONTENEDOR"
        echo "============================================================"
        echo

        docker compose logs --tail=100 "$SERVICE_NAME" || true

        exit 1
    fi

    echo "Esperando... ($ATTEMPT/$MAX_ATTEMPTS)"

    sleep 2
    ATTEMPT=$((ATTEMPT + 1))
done

if [ "$ATTEMPT" -gt "$MAX_ATTEMPTS" ]; then

    error "El contenedor no estuvo listo a tiempo."

    echo
    docker compose ps || true

    echo
    docker compose logs --tail=100 "$SERVICE_NAME" || true

    exit 1
fi

# ============================================================
# ESTADO FINAL
# ============================================================

echo
echo "============================================================"
echo " ESTADO DEL SERVICIO"
echo "============================================================"
echo

docker compose ps || true

echo
echo "============================================================"
echo " ÚLTIMOS LOGS"
echo "============================================================"
echo

docker compose logs --tail="$LOG_LINES" "$SERVICE_NAME" || true

echo
echo "============================================================"
success "Actualización completada correctamente."
echo "============================================================"

echo
echo "Commit desplegado:"
echo "  $NEW_COMMIT"
echo
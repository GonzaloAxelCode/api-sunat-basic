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

# ============================================================
# MANEJO DE ERRORES
# ============================================================

cleanup_on_error() {
    local exit_code=$?

    echo
    error "La actualización falló."

    if [ -n "${OLD_COMMIT:-}" ]; then
        echo
        echo "Commit inicial:"
        echo "  $OLD_COMMIT"
    fi

    if [ -n "${NEW_COMMIT:-}" ]; then
        echo
        echo "Commit actual:"
        echo "  $NEW_COMMIT"
    fi

    echo
    echo "El contenedor existente no fue detenido manualmente."
    echo "Si el fallo ocurrió durante Git o Docker build,"
    echo "el contenedor anterior debería seguir ejecutándose."

    exit "$exit_code"
}

trap cleanup_on_error ERR

# ============================================================
# DIRECTORIO DEL PROYECTO
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

log "Verificando entorno..."

if [ ! -d ".git" ]; then
    error "Este directorio no parece ser un repositorio Git."
    exit 1
fi

if [ ! -f "$COMPOSE_FILE" ]; then
    error "No se encontró $COMPOSE_FILE."
    exit 1
fi

if [ ! -f ".env" ]; then
    warning "Archivo .env no encontrado."

    if [ -f ".env.example" ]; then
        echo
        echo "Se encontró .env.example."
        echo
        echo "Crea el archivo .env antes de continuar:"
        echo
        echo "  cp .env.example .env"
        echo "  nano .env"
        echo
    fi

    exit 1
fi

success "Entorno verificado."

# ============================================================
# VERIFICAR DOCKER
# ============================================================

log "Verificando Docker..."

if ! command -v docker >/dev/null 2>&1; then
    error "Docker no está instalado."
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    error "Docker no está disponible o el usuario no tiene permisos."
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    error "Docker Compose no está disponible."
    exit 1
fi

success "Docker disponible."

# ============================================================
# VERIFICAR GIT
# ============================================================

log "Verificando Git..."

CURRENT_BRANCH="$(git branch --show-current)"

if [ "$CURRENT_BRANCH" != "$BRANCH" ]; then
    error "No estás en la rama $BRANCH."
    echo "Rama actual: $CURRENT_BRANCH"
    exit 1
fi

OLD_COMMIT="$(git rev-parse HEAD)"

echo
echo "Commit actual:"
echo "  $OLD_COMMIT"
echo

# ============================================================
# VERIFICAR CAMBIOS LOCALES
# ============================================================

if [ -n "$(git status --porcelain)" ]; then
    warning "Hay cambios locales:"
    echo
    git status --short
    echo

    error "No se continuará para evitar sobrescribir cambios locales."
    echo
    echo "Revisa los cambios y vuelve a ejecutar el script."
    exit 1
fi

success "Working tree limpio."

# ============================================================
# OBTENER CAMBIOS DE GITHUB
# ============================================================

log "Obteniendo cambios desde GitHub..."

git fetch origin "$BRANCH"

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

    docker compose ps

    echo
    echo "============================================================"
    echo " ÚLTIMOS LOGS"
    echo "============================================================"
    echo

    docker compose logs --tail="$LOG_LINES" "$SERVICE_NAME"

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
    echo "Tu servidor y origin/$BRANCH tienen historias divergentes."
    echo "No se hará ningún cambio automáticamente."
    echo
    echo "Puedes revisar con:"
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

git merge --ff-only "origin/$BRANCH"

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
        error "Falta el archivo requerido: $file"
        exit 1
    fi
done

success "Archivos requeridos encontrados."

# ============================================================
# CONSTRUIR NUEVA IMAGEN
# ============================================================

log "Construyendo nueva imagen Docker..."

echo
echo "IMPORTANTE:"
echo "El contenedor actual NO será detenido durante el build."
echo

docker compose build --pull

success "Nueva imagen construida correctamente."

# ============================================================
# ACTUALIZAR CONTENEDOR
# ============================================================

log "Actualizando contenedor..."

docker compose up -d --remove-orphans

success "Contenedor iniciado."

# ============================================================
# ESPERAR CONTENEDOR
# ============================================================

log "Esperando que el contenedor esté listo..."

CONTAINER_ID="$(docker compose ps -q "$SERVICE_NAME")"

if [ -z "$CONTAINER_ID" ]; then
    error "No se pudo obtener el ID del contenedor."
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
        docker compose ps

        echo
        echo "============================================================"
        echo " LOGS DEL CONTENEDOR"
        echo "============================================================"
        echo

        docker compose logs --tail=100 "$SERVICE_NAME"

        exit 1
    fi

    echo "Esperando... ($ATTEMPT/$MAX_ATTEMPTS)"

    sleep 2

    ATTEMPT=$((ATTEMPT + 1))
done

if [ "$ATTEMPT" -gt "$MAX_ATTEMPTS" ]; then

    error "El contenedor no estuvo listo a tiempo."

    echo
    docker compose ps

    echo
    echo "============================================================"
    echo " LOGS DEL CONTENEDOR"
    echo "============================================================"
    echo

    docker compose logs --tail=100 "$SERVICE_NAME"

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

docker compose ps

echo
echo "============================================================"
echo " ÚLTIMOS LOGS"
echo "============================================================"
echo

docker compose logs --tail="$LOG_LINES" "$SERVICE_NAME"

echo
echo "============================================================"
success "Actualización completada correctamente."
echo "============================================================"

echo
echo "Commit desplegado:"
echo "  $NEW_COMMIT"

echo
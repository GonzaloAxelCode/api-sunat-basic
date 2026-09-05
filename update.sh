#!/bin/bash

set -Eeuo pipefail

# ============================================================
# CONFIGURACIÓN
# ============================================================

APP_NAME="api-sunat-basic"
BRANCH="main"
COMPOSE_FILE="docker-compose.yml"

# Cantidad de logs que se mostrarán al final
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
    error "La actualización falló."

    if [ -n "${OLD_COMMIT:-}" ]; then
        echo
        echo "Commit anterior:"
        echo "  $OLD_COMMIT"
    fi

    echo
    echo "El contenedor anterior no debería haberse detenido"
    echo "si el fallo ocurrió durante git o build."
}

trap cleanup_on_error ERR

# ============================================================
# VERIFICAR DIRECTORIO
# ============================================================

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
        echo "Se encontró .env.example."
        echo
        echo "No se creará automáticamente .env para evitar"
        echo "sobrescribir o generar una configuración incorrecta."
        echo
        echo "Crea .env y vuelve a ejecutar:"
        echo
        echo "    cp .env.example .env"
        echo "    nano .env"
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
# VERIFICAR ESTADO GIT
# ============================================================

log "Verificando Git..."

if [ "$(git branch --show-current)" != "$BRANCH" ]; then
    error "No estás en la rama $BRANCH."
    echo "Rama actual: $(git branch --show-current)"
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
    warning "Hay cambios locales."

    git status --short

    echo
    error "No se continuará para evitar sobrescribir cambios locales."
    echo
    echo "Si estás seguro de que esos cambios no deben conservarse,"
    echo "revísalos y elimínalos manualmente antes de ejecutar este script."
    exit 1
fi

success "Working tree limpio."

# ============================================================
# GIT FETCH
# ============================================================

log "Obteniendo cambios desde GitHub..."

git fetch origin "$BRANCH"

REMOTE_COMMIT="$(git rev-parse origin/$BRANCH)"

echo
echo "Commit local : $OLD_COMMIT"
echo "Commit remoto: $REMOTE_COMMIT"
echo

# ============================================================
# VERIFICAR SI YA ESTÁ ACTUALIZADO
# ============================================================

if [ "$OLD_COMMIT" = "$REMOTE_COMMIT" ]; then
    success "El servidor ya está actualizado."
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
    echo "Revisa con:"
    echo "  git log --oneline --graph --decorate --all -20"

    exit 1
fi

# ============================================================
# ACTUALIZAR GIT
# ============================================================

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
# VALIDAR ARCHIVOS IMPORTANTES
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
# CONSTRUIR IMAGEN NUEVA
# ============================================================

log "Construyendo nueva imagen Docker..."

# IMPORTANTE:
# Todavía NO hacemos docker compose down.
#
# Si el build falla, el contenedor actual continúa funcionando.

docker compose build --pull

success "Nueva imagen construida correctamente."

# ============================================================
# LEVANTAR / ACTUALIZAR SERVICIO
# ============================================================

log "Actualizando contenedor..."

docker compose up -d --remove-orphans

success "Contenedor iniciado."

# ============================================================
# ESPERAR CONTENEDOR
# ============================================================

log "Esperando que el contenedor esté listo..."

CONTAINER_ID="$(docker compose ps -q api)"

if [ -z "$CONTAINER_ID" ]; then
    error "No se pudo obtener el ID del contenedor."
    exit 1
fi

MAX_ATTEMPTS=30
ATTEMPT=1

while [ "$ATTEMPT" -le "$MAX_ATTEMPTS" ]; do

    STATUS="$(docker inspect \
        --format='{{.State.Status}}' \
        "$CONTAINER_ID" 2>/dev/null || true)"

    if [ "$STATUS" = "running" ]; then
        success "Contenedor ejecutándose."
        break
    fi

    if [ "$STATUS" = "exited" ] || [ "$STATUS" = "dead" ]; then
        error "El contenedor terminó inesperadamente."

        echo
        docker compose ps
        echo
        docker compose logs --tail=100 api

        exit 1
    fi

    echo "Esperando... ($ATTEMPT/$MAX_ATTEMPTS)"
    sleep 2

    ATTEMPT=$((ATTEMPT + 1))
done

if [ "$ATTEMPT" -gt "$MAX_ATTEMPTS" ]; then
    error "El contenedor no estuvo listo a tiempo."

    docker compose ps
    docker compose logs --tail=100 api

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

docker compose logs --tail="$LOG_LINES" api

echo
echo "============================================================"

success "Actualización completada correctamente."

echo
echo "Commit desplegado:"
echo "  $NEW_COMMIT"
echo
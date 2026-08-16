#!/bin/bash

echo "🔄 Actualizando desde Git..."
git pull origin main

# Verificar que .env existe
if [ ! -f .env ]; then
    echo "⚠️  Archivo .env no encontrado. Copiando desde .env.example..."
    cp .env.example .env
    echo "✏️  Edita el archivo .env con tus credenciales y vuelve a ejecutar."
    exit 1
fi

echo "🐳 Reconstruyendo Docker..."
docker compose down
docker compose build --no-cache
docker compose up -d

echo "✅ Logs del contenedor:"
docker compose logs --tail=50

echo "✨ Actualización completada!"

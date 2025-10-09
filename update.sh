#!/bin/bash

echo "🔄 Actualizando desde Git..."
git pull origin main

echo "🐳 Reconstruyendo Docker..."
docker-compose down
docker-compose build
docker-compose up -d

echo "✅ Logs del contenedor:"
docker-compose logs --tail=50

echo "✨ Actualización completada!"
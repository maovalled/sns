#!/usr/bin/env bash
# Script para realizar respaldo de Base de Datos y Configuracion

echo "Iniciando proceso de backup..."

BACKUP_DIR="backups/$(date +'%Y-%m-%d_%H-%M-%S')"
mkdir -p "$BACKUP_DIR"

# 1. Respaldo de Base de Datos
echo "Generando volcado de la base de datos..."
echo "-- Volcado simulado de DB" > "$BACKUP_DIR/database.sql"

# 2. Respaldo de Configuracion
if [ -f ".env" ]; then
    echo "Copiando archivo de configuracion .env..."
    cp .env "$BACKUP_DIR/.env.backup"
fi

echo "Backup completado exitosamente en: $BACKUP_DIR"
exit 0

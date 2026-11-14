# 1. Limpiar assets antiguos y compilar el frontend actualizado
rm -rf public/assets/*
cd frontend && npm run build && cd ..

# 2. Instalar dependencias PHP de producción (sin paquetes de desarrollo)
composer install --no-dev --optimize-autoloader

# 3. Generar el archivo ZIP listo para Shared Hosting / cPanel
zip -r p8p-v1.1.0.zip . \
  -x "*.git*" \
  -x "frontend/*" \
  -x "node_modules/*" \
  -x "storage/*.sqlite" \
  -x "storage/logs/*" \
  -x "*.env" \
  -x "config.php" \
  -x "p8p-*.zip" \
  -x ".DS_Store"
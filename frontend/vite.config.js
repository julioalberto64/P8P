import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
  ],
  build: {
    // Exporta los archivos compilados directo a la carpeta public del backend PHP
    outDir: '../public',
    // IMPORTANTE: Ponlo en false para evitar que Vite borre tus archivos .php (api.php, cron.php, etc.) al compilar
    emptyOutDir: false,
  },
})
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
  ],
  server: {
    proxy: {
      // En desarrollo, las rutas PHP relativas se sirven desde el backend local.
      '^/.*\\.php(?:\\?|$)': 'http://localhost:8000',
    },
  },
  build: {
    // Exporta los archivos compilados directo a la carpeta public del backend PHP
    outDir: '../public',
    // IMPORTANTE: Ponlo en false para evitar que Vite borre tus archivos .php (api.php, cron.php, etc.) al compilar
    emptyOutDir: false,
    chunkSizeWarningLimit: 600,
    rolldownOptions: {
      output: {
        codeSplitting: {
          groups: [
            {
              name: 'vendor-react',
              test: /node_modules[\\/](react|react-dom)/,
            },
            {
              name: 'vendor-xyflow',
              test: /node_modules[\\/]@xyflow/,
            },
            {
              name: 'vendor-icons',
              test: /node_modules[\\/]lucide-react/,
            },
          ],
        },
      },
    },
  },
})
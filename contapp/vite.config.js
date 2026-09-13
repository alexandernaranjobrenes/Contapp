import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.scss', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: 'http://localhost:5173',
        // Sin esto, Vite refleja su propia URL (server.origin) como
        // Access-Control-Allow-Origin en vez del origen real de la pestaña
        // (localhost:8000, servido por nginx) y el navegador bloquea
        // @vite/client por CORS. Con una lista explícita, Vite compara
        // contra el Origin real de cada request.
        cors: {
            origin: ['http://localhost:8000', 'http://127.0.0.1:8000'],
        },
        hmr: {
            host: 'localhost',
        },
        watch: {
            usePolling: true,
            // interval por defecto de chokidar en modo polling: 100ms. Medido
            // en este entorno (Docker Desktop + bind mount en Windows): ese
            // ritmo satura el único canal de I/O compartido con el contenedor
            // app, y las páginas del backend tardan casi el doble mientras
            // node está corriendo (medido: ~1.0s con node activo vs ~0.5s con
            // node detenido, para la misma request). 1000ms sigue sintiéndose
            // instantáneo para el flujo humano de guardar-y-refrescar, pero
            // reduce la frecuencia de barrido 10x.
            interval: 1000,
            // Sin excluir vendor/ (15000+ archivos PHP de Composer) y storage/,
            // el polling barre TODO el árbol del proyecto en cada ciclo.
            ignored: [
                '**/vendor/**',
                '**/storage/**',
                '**/node_modules/**',
                '**/public/build/**',
            ],
        },
    },
});

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    build: {
        /**
         * The only chunk above the default 500 kB is `echarts`, and it is
         * already as small as it can honestly be made: the chart component
         * imports `echarts/core` plus just the line chart, grid, tooltip, aria
         * and SVG renderer modules, and the split below keeps it out of every
         * page bundle. Inertia loads a page chunk on demand, so it reaches a
         * visitor only when they open a station's charts.
         *
         * The limit is raised just past that chunk rather than far above it, so
         * a genuinely new oversized bundle still trips the warning.
         */
        chunkSizeWarningLimit: 520,
        rollupOptions: {
            output: {
                /**
                 * Give the two heavy visualisation libraries their own chunks.
                 *
                 * Inertia already resolves pages lazily, so ECharts only ever
                 * loads with the station page and Leaflet only with a page that
                 * draws the map — that part is not what this changes. What it
                 * changes is that the library no longer sits *inside* the page
                 * chunk: the station page bundle drops back under the 500 kB
                 * warning threshold, and a visitor who moves between two
                 * map-bearing pages downloads Leaflet once instead of once per
                 * page bundle.
                 *
                 * No import is rewritten and no component becomes async, so
                 * runtime behaviour is unchanged.
                 */
                manualChunks: (id: string): string | undefined => {
                    // Matched on the resolved path rather than a package list,
                    // because rolldown only accepts the function form.
                    if (id.includes('node_modules/echarts')) {
                        return 'echarts';
                    }

                    if (
                        id.includes('node_modules/leaflet') ||
                        id.includes('node_modules/react-leaflet') ||
                        id.includes('node_modules/@react-leaflet')
                    ) {
                        return 'leaflet';
                    }

                    return undefined;
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**', '**/vendor/**'],
        },
    },
});

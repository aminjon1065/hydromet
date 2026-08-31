import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        globals: false,
        setupFiles: ['./tests/frontend/setup.ts'],
        include: ['tests/frontend/**/*.test.tsx', 'tests/frontend/**/*.test.ts'],
        css: false,
    },
});

import '../css/app.css';

import { cspNonce } from '@/lib/csp-nonce';
import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME ?? 'Hydromet';

const nonce = cspNonce();

if (nonce !== undefined) {
    /*
     * The scroll lock Radix applies while a dropdown is open injects a `<style>`
     * element through `react-style-singleton`, which reads its nonce from this
     * global. Setting it here rather than importing `get-nonce` keeps a
     * transitive package out of the portal's own import graph.
     */
    (window as Window & { __webpack_nonce__?: string }).__webpack_nonce__ = nonce;
}

void createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),
    // Applied to the `<style>` element Inertia injects for the progress bar.
    nonce,
    resolve: (name) =>
        resolvePageComponent<ResolvedComponent>(
            `./pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: 'var(--color-primary)',
    },
});

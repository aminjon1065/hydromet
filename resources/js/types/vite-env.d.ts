/// <reference types="vite/client" />

/**
 * Typed build-time variables. Vite's default `ImportMetaEnv` uses an index
 * signature that resolves to `any`, which strict TypeScript rejects.
 */
interface ImportMetaEnv {
    readonly VITE_APP_NAME?: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}

/**
 * The Content Security Policy nonce this page was rendered with.
 *
 * Read from the entry script tag rather than from a `<meta>` element, so the
 * value is not exposed in a readable attribute. Browsers hide the `nonce`
 * content attribute after parsing but keep the real value on the `nonce` IDL
 * property, which is exactly what this reads.
 *
 * It is needed because two libraries create `<style>` elements at runtime and
 * a nonce cannot be attached after the fact: Inertia's navigation progress bar,
 * and the scroll lock Radix applies while a dropdown is open. Both accept a
 * nonce, so both are told the page's.
 *
 * Returns `undefined` when the page carries no policy — a test environment, or
 * a deployment that has not enabled one. Callers then behave as before.
 */
export function cspNonce(): string | undefined {
    if (typeof document === 'undefined') {
        return undefined;
    }

    const nonce = document.querySelector<HTMLScriptElement>('script[nonce]')?.nonce;

    return nonce !== undefined && nonce !== '' ? nonce : undefined;
}

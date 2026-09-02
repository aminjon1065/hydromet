<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Public responses always carry a per-request nonce in `script-src`. This
    | switch governs `style-src` only.
    |
    | A nonce cannot be attached to an inline `style` attribute, and Leaflet
    | positions every map pane with one, so a style nonce only works together
    | with `style-src-attr 'unsafe-inline'`. That companion directive is CSP
    | Level 3: a browser that has not implemented it ignores it and falls back
    | to `style-src`, where the nonce blocks those same style attributes and the
    | map stops rendering.
    |
    | The default is therefore the compatible policy — `style-src 'self'
    | 'unsafe-inline'`, which still refuses stylesheets from any other origin.
    | Turn this on once the audience's browsers are known to support
    | `style-src-attr`, and verify the station map and the charts in each of
    | them before releasing.
    |
    */

    'csp' => [
        'style_nonce' => (bool) env('CSP_STYLE_NONCE', false),
    ],

];

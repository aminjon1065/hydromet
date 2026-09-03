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

    /*
    |--------------------------------------------------------------------------
    | Map tile origin
    |--------------------------------------------------------------------------
    |
    | The one third-party origin the portal loads images from. `img-src` names
    | it explicitly instead of opening the directive to `https:`, so a page can
    | still draw the station map and nothing else can smuggle data out through
    | an image URL.
    |
    | It must match the tile URL requested in
    | `resources/js/components/station-map.tsx`. A wildcard covers the `{s}`
    | subdomain rotation Leaflet performs (`a.`, `b.`, `c.`).
    |
    */

    'csp' => [
        'style_nonce' => (bool) env('CSP_STYLE_NONCE', false),
        'map_tile_origin' => env('CSP_MAP_TILE_ORIGIN', 'https://*.tile.openstreetmap.org'),
    ],

];

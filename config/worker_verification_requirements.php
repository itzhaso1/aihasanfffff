<?php

/*
| Country-aware verification catalog.
| Phase 1 does not define mandatory JO/SA document types, so required lists stay empty
| until a reviewed catalog is added. Unsupported countries are rejected by the catalog service.
*/

return [
    'countries' => [
        'JO' => [
            'required' => [],
        ],
        'SA' => [
            'required' => [],
        ],
    ],
];

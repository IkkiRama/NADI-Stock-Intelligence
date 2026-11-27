<?php

return [
    'abilities' => [
        'access-admin',
        'manage-users',
        'manage-data-sources',
        'manage-feature-flags',
        'view-audit-logs',
    ],

    'roles' => [
        'admin' => [
            'access-admin',
            'manage-users',
            'manage-data-sources',
            'manage-feature-flags',
            'view-audit-logs',
        ],
        'editor' => [],
        'user' => [],
    ],
];

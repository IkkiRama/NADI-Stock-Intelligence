<?php

return [
    'abilities' => [
        'access-admin',
        'manage-users',
        'manage-data-sources',
        'manage-feature-flags',
        'view-audit-logs',
        'manage-imports',
        'manage-scoring',
        'manage-safety',
    ],

    'roles' => [
        'admin' => [
            'access-admin',
            'manage-users',
            'manage-data-sources',
            'manage-feature-flags',
            'view-audit-logs',
            'manage-imports',
            'manage-scoring',
            'manage-safety',
        ],
        'editor' => [],
        'user' => [],
    ],
];

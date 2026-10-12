<?php

return [
    [
        'capability_key' => 'inbox.messages.read',
        'display_name' => 'Read inbox messages',
        'description' => 'Read workspace inbox messages assigned to the worker.',
        'sort_order' => 10,
    ],
    [
        'capability_key' => 'inbox.messages.send',
        'display_name' => 'Send inbox messages',
        'description' => 'Send replies from the workspace inbox.',
        'sort_order' => 20,
    ],
    [
        'capability_key' => 'customers.view',
        'display_name' => 'View customers',
        'description' => 'View workspace customer records.',
        'sort_order' => 30,
    ],
    [
        'capability_key' => 'customers.phone.view',
        'display_name' => 'View customer phone numbers',
        'description' => 'View customer phone numbers when separately granted.',
        'sort_order' => 40,
    ],
    [
        'capability_key' => 'customers.email.view',
        'display_name' => 'View customer email addresses',
        'description' => 'View customer email addresses when separately granted.',
        'sort_order' => 50,
    ],
    [
        'capability_key' => 'catalog.products.view',
        'display_name' => 'View products',
        'description' => 'View the workspace product catalog.',
        'sort_order' => 60,
    ],
    [
        'capability_key' => 'commerce.orders.view',
        'display_name' => 'View orders',
        'description' => 'View workspace order information.',
        'sort_order' => 70,
    ],
    [
        'capability_key' => 'commerce.orders.create',
        'display_name' => 'Create orders',
        'description' => 'Create orders through an authorized workspace operation.',
        'sort_order' => 80,
    ],
    [
        'capability_key' => 'commerce.payment_links.create',
        'display_name' => 'Create payment links',
        'description' => 'Create payment links through an authorized workspace operation.',
        'sort_order' => 90,
    ],
    [
        'capability_key' => 'reports.view',
        'display_name' => 'View reports',
        'description' => 'View workspace reports.',
        'sort_order' => 100,
    ],
    [
        'capability_key' => 'email.view',
        'display_name' => 'View workspace email',
        'description' => 'View workspace email messages and related read-only email data.',
        'sort_order' => 110,
    ],
    [
        'capability_key' => 'stories.view',
        'display_name' => 'View workspace updates',
        'description' => 'View workspace stories and updates without publishing or deleting them.',
        'sort_order' => 120,
    ],
];

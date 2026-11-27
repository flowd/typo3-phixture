<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Item',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // the tests store records on standard pages, not only in folders
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input'],
        ],
        // inline with a parent field on the child
        'children' => [
            'label' => 'Children',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_phixturefixture_child',
                'foreign_field' => 'item',
            ],
        ],
        // select with an MM table, on read-only static records
        'tags' => [
            'label' => 'Tags',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'tx_phixturefixture_tag',
                'MM' => 'tx_phixturefixture_item_tag_mm',
            ],
        ],
        // group on one table: plain uids
        'page' => [
            'label' => 'Page',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'maxitems' => 1,
            ],
        ],
        // group on several tables: uids prefixed with the table
        'related' => [
            'label' => 'Related',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages,tx_phixturefixture_child',
            ],
        ],
        'image' => [
            'label' => 'Image',
            'config' => [
                'type' => 'file',
                'allowed' => 'common-image-types',
            ],
        ],
        'document' => [
            'label' => 'Document',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'hidden, title, children, tags, page, related, image, document'],
    ],
];

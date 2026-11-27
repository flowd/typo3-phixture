<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Child',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // the tests store records on standard pages, not only in folders
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input'],
        ],
        'item' => [
            'label' => 'Item',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_phixturefixture_item',
                'default' => 0,
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, item'],
    ],
];

<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'Tag',
        'label' => 'title',
        // static data of the extension: DataHandler does not write this table
        'readOnly' => true,
        'rootLevel' => 1,
    ],
    'columns' => [
        'title' => [
            'label' => 'Title',
            'config' => ['type' => 'input'],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title'],
    ],
];

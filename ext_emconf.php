<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Blog extension',
    'description' => 'A page based blog extension.',
    'category' => 'plugin',
    'author' => 'Raphael Thanner',
    'author_email' => 'r.thanner@zeroseven.de',
    'author_company' => 'zeroseven design studios GmbH',
    'state' => 'stable',
    'clearCacheOnLoad' => 1,
    'version' => '1.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-12.4.99',
            'pagebased' => '1.2.0-1.99.99'
        ],
        'conflicts' => [
            'z7_blog' => ''
        ]
    ]
];

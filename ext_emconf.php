<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Websocket',
    'description' => 'Generic Redis-backed websocket server extension for TYPO3',
    'category' => 'plugin',
    'author' => 'Sven Liebert',
    'author_email' => 'mail@sven-liebert.de',
    'author_company' => '',
    'state' => 'stable',
    'version' => '0.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'frontend' => '13.4.0-13.4.99',
        ],
        'conflicts' => [
        ],
        'suggests' => [
        ],
    ],
];

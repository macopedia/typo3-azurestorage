<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Azure Storage',
    'description' => 'Microsoft Azure Blob Storage for TYPO3',
    'category' => 'be',
    'version' => '0.4.1',
    'state' => 'beta',
    'clearcacheonload' => 1,
    'author' => 'Benjamin Hirsch',
    'author_email' => 'mail@benjaminhirsch.net',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '7.6.0-9.9.99'
        ],
    ],
];
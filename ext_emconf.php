<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Azure Storage',
    'description' => 'Microsoft Azure Blob Storage to TYPO3',
    'category' => 'be',
    'version' => '0.2.0',
    'state' => 'beta',
    'clearcacheonload' => 1,
    'author' => 'Benjamin Hirsch',
    'author_email' => 'mail@benjaminhirsch.net',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '7.6.0-8.9.99'
        ],
    ],
];
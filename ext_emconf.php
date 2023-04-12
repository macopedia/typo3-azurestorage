<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Azure Storage',
    'description' => 'Microsoft Azure Blob Storage for TYPO3',
    'category' => 'be',
    'version' => '0.6.1',
    'state' => 'beta',
    'clearcacheonload' => 1,
    'author' => 'Macopedia.com team',
    'author_email' => 'extensions@macopedia.pl',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '7.6.0-12.4.99'
        ],
    ],
];

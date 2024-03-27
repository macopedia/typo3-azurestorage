<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Azure Storage',
    'description' => 'Microsoft Azure Blob Storage for TYPO3',
    'category' => 'be',
    'version' => '1.0.2',
    'state' => 'stable',
    'clearcacheonload' => 1,
    'author' => 'Macopedia.com team',
    'author_email' => 'extensions@macopedia.pl',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '11.5.0-12.4.99'
        ],
    ],
];

<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Azure Storage',
    'description' => 'Microsoft Azure Blob Storage for TYPO3',
    'category' => 'be',
    'version' => '2.0.0',
    'state' => 'stable',
    'clearcacheonload' => 1,
    'author' => 'Macopedia.com team',
    'author_email' => 'extensions@macopedia.pl',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '13.0.0-14.5.99'
        ],
    ],
];

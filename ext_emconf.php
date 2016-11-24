<?php

$EM_CONF[$_EXTKEY] = array(
    'title' => 'Azure Storage',
    'description' => 'Microsoft Azure Blob Storage to TYPO3',
    'category' => 'be',
    'version' => '0.1.1',
    'state' => 'beta',
    'clearcacheonload' => 1,
    'author' => 'Benjamin Hirsch',
    'author_email' => 'mail@benjaminhirsch.net',
    'author_company' => '',
    'constraints' => array(
        'depends' => array(
            'typo3' => '7.6.0-8.4.99'
        ),
    ),
);
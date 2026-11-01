<?php
declare(strict_types=1);
if (!defined('TYPO3')) {
    die('Access denied.');
}

// register driver, see https://typo3.slack.com/archives/C03AM9R17/p1538658116000100
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers'][\B3N\AzureStorage\TYPO3\Driver\StorageDriver::class] = [
    'class' => \B3N\AzureStorage\TYPO3\Driver\StorageDriver::class,
    'shortName' => \B3N\AzureStorage\TYPO3\Driver\StorageDriver::class,
    'label' => 'Azure Storage',
    'flexFormDS' => 'FILE:EXT:azurestorage/Configuration/FlexForms/AzureStorage.xml'
];

// Cache configuration, see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/CachingFramework/Developer/Index.html
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['azurestorage'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['azurestorage']['backend'] ??= 'TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend';
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['azurestorage']['options'] ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['azurestorage']['options']['defaultLifetime'] ??= 0;

$extractorRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\Index\ExtractorRegistry::class);
$extractorRegistry->registerExtractionService(\B3N\AzureStorage\TYPO3\Index\Extractor::class);

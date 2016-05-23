<?php

if (!defined('TYPO3_MODE')) {
    die('Access denied.');
}

/** @var \TYPO3\CMS\Core\Resource\Driver\DriverRegistry $driverRegistry */
$driverRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\Driver\\DriverRegistry');
$driverRegistry->registerDriverClass(
    \B3N\AzureStorage\TYPO3\Driver\StorageDriver::class,
    \B3N\AzureStorage\TYPO3\Driver\StorageDriver::class,
    'Azure Storage',
    'FILE:EXT:azurestorage/Configuration/TCA/AzureStorage.xml'
);

// Cache configuration, see http://wiki.typo3.org/Caching_Framework
if (!is_array($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['azurestorage'])) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['azurestorage'] = array(
        'backend' => 'TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend',
        'options' => [
            'defaultLifetime' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::UNLIMITED_LIFETIME
        ],
    );
}

\TYPO3\CMS\Core\Resource\Index\ExtractorRegistry::getInstance()->registerExtractionService(\B3N\AzureStorage\TYPO3\Index\Extractor::class);

/* @var $signalSlotDispatcher \TYPO3\CMS\Extbase\SignalSlot\Dispatcher */
$signalSlotDispatcher = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher::class);
$signalSlotDispatcher->connect(\TYPO3\CMS\Core\Resource\Index\FileIndexRepository::class, 'recordUpdated', \B3N\AzureStorage\TYPO3\Signal\FileIndexRepository::class, 'recordUpdatedOrCreated');
$signalSlotDispatcher->connect(\TYPO3\CMS\Core\Resource\Index\FileIndexRepository::class, 'recordCreated', \B3N\AzureStorage\TYPO3\Signal\FileIndexRepository::class, 'recordUpdatedOrCreated');
<?php

declare(strict_types=1);

namespace B3N\AzureStorage\TYPO3\EventListener;

use B3N\AzureStorage\TYPO3\Driver\StorageDriver;
use B3N\AzureStorage\TYPO3\Index\Extractor;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Index\MetaDataRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractFileIndexEventListener
{
    protected function recordUpdatedOrCreated($data)
    {
        if ($data['type'] === File::FILETYPE_IMAGE) {
            /** @var ResourceStorage $storage */
            $storage = GeneralUtility::makeInstance(ResourceFactory::class)->getStorageObject((int)$data['storage']);

            // only process our driver
            if ($storage->getDriverType() !== StorageDriver::class) {
                return null;
            }

            $file = $storage->getFile($data['identifier']);
            $imageDimensions = Extractor::getImageDimensions($file);

            if ($imageDimensions !== null) {
                /** @var MetaDataRepository $metaDataRepository */
                $metaDataRepository = GeneralUtility::makeInstance(MetaDataRepository::class);
                $metaData = $metaDataRepository->findByFileUid($data['uid']);

                $metaData['width'] = $imageDimensions[0];
                $metaData['height'] = $imageDimensions[1];

                if (isset($metaData['uid'])) {
                    $metaDataRepository->update($data['uid'], $metaData);
                } else {
                    $metaDataRepository->createMetaDataRecord($data['uid'], $metaData);
                }
            }
        }
    }
}

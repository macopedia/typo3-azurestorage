<?php

namespace B3N\AzureStorage\TYPO3\Signal;

use B3N\AzureStorage\TYPO3\Driver\StorageDriver;
use B3N\AzureStorage\TYPO3\Index\Extractor;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Index\MetaDataRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;

class FileIndexRepository
{
    public function recordUpdatedOrCreated($data)
    {
        if ($data['type'] === File::FILETYPE_IMAGE) {
            /* @var $storage \TYPO3\CMS\Core\Resource\ResourceStorage */
            $storage = ResourceFactory::getInstance()->getStorageObject($data['storage']);

            // only process our driver
            if ($storage->getDriverType() !== StorageDriver::class) {
                return null;
            }

            $file = $storage->getFile($data['identifier']);
            $imageDimensions = Extractor::getImageDimensions($file);

            if ($imageDimensions !== null) {
                /* @var $metaDataRepository MetaDataRepository */
                $metaDataRepository = MetaDataRepository::getInstance();
                $metaData = $metaDataRepository->findByFileUid($data['uid']);

                $metaData['width'] = $imageDimensions[0];
                $metaData['height'] = $imageDimensions[1];
                $metaDataRepository->update($data['uid'], $metaData);
            }
        }
    }
}
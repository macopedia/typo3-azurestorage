<?php

namespace B3N\AzureStorage\TYPO3\Index;

use B3N\AzureStorage\TYPO3\Driver\StorageDriver;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Index\ExtractorInterface;
use TYPO3\CMS\Core\Type\File\ImageInfo;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class Extractor implements ExtractorInterface
{

    /**
     * Returns an array of supported file types;
     * An empty array indicates all filetypes
     *
     * @return array<int>
     */
    public function getFileTypeRestrictions(): array
    {
        return [File::FILETYPE_IMAGE];
    }

    /**
     * Get all supported DriverClasses
     *
     * Since some extractors may only work for local files, and other extractors
     * are especially made for grabbing data from remote.
     *
     * Returns array of string with driver names of Drivers which are supported,
     * If the driver did not register a name, it's the classname.
     * empty array indicates no restrictions
     *
     * @return array<string>
     */
    public function getDriverRestrictions(): array
    {
        return [StorageDriver::class];
    }

    /**
     * Returns the data priority of the extraction Service.
     * Defines the precedence of Data if several extractors
     * extracted the same property.
     *
     * Should be between 1 and 100, 100 is more important than 1
     *
     * @return int
     */
    public function getPriority()
    {
        return 50;
    }

    /**
     * Returns the execution priority of the extraction Service
     * Should be between 1 and 100, 100 means runs as first service, 1 runs at last service
     *
     * @return int
     */
    public function getExecutionPriority()
    {
        return 50;
    }

    /**
     * Checks if the given file can be processed by this Extractor
     *
     * @param File $file
     * @return bool
     */
    public function canProcess(File $file)
    {
        if ($file->getType() === File::FILETYPE_IMAGE && $file->getStorage()->getDriverType() === StorageDriver::class) {
            return true;
        }

        return false;
    }

    /**
     * The actual processing TASK
     *
     * Should return an array with database properties for sys_file_metadata to write
     *
     * @param File $file
     * @param array{width?: int, height?: int} $previousExtractedData optional, contains the array of already extracted data
     * @return array{width?: int, height?: int}
     */
    public function extractMetaData(File $file, array $previousExtractedData = []): array
    {
        if (!isset($previousExtractedData['width']) || !isset($previousExtractedData['height'])) {
            $imageDimensions = self::getImageDimensions($file);
            if ($imageDimensions !== null) {
                $previousExtractedData['width'] = $imageDimensions[0];
                $previousExtractedData['height'] = $imageDimensions[1];
            }
        }

        return $previousExtractedData;
    }

    /**
     * @param File $file
     * @return array<int>|NULL
     */
    public static function getImageDimensions(File $file): ?array
    {
        /** @var ImageInfo $imageInfo */
        $imageInfo = GeneralUtility::makeInstance(ImageInfo::class, $file->getForLocalProcessing(false));

        return [
            $imageInfo->getWidth(),
            $imageInfo->getHeight(),
        ];
    }
}
<?php

namespace B3N\AzureStorage\TYPO3\Driver;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\Internal\BlobResources as Resources;
use MicrosoftAzure\Storage\Blob\Internal\IBlob;
use MicrosoftAzure\Storage\Blob\Models\Blob;
use MicrosoftAzure\Storage\Blob\Models\BlobProperties;
use MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\GetBlobPropertiesResult;
use MicrosoftAzure\Storage\Blob\Models\GetBlobResult;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsResult;
use MicrosoftAzure\Storage\Blob\Models\SetBlobPropertiesOptions;
use MicrosoftAzure\Storage\Blob\Models\SetBlobPropertiesResult;
use MicrosoftAzure\Storage\Common\Internal\Utilities;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Exception;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\StorageRepository;


class StorageDriver extends AbstractHierarchicalFilesystemDriver
{

    /**
     * @var string
     */
    private $container;

    /**
     * @var string
     */
    private $account;

    /**
     * @var string
     */
    private $accesskey;

    /**
     * @var IBlob
     */
    private $blobService;

    /**
     * @var null|string
     */
    private $endpoint;

    /**
     * @var string
     */
    private $protocol = 'http';

    /**
     * @var string
     */
    private $cacheControl = '';

    /**
     * @var \TYPO3\CMS\Core\Resource\ResourceStorage
     */
    private $storage;

    /**
     * @var VariableFrontend
     */
    private $cache;

    /**
     * @var array
     */
    private $temporaryPaths = [];

    /**
     * Initialize this driver and expose the capabilities for the repository to use
     *
     * @param array $configuration
     *
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);

        $this->capabilities = ResourceStorage::CAPABILITY_BROWSABLE | ResourceStorage::CAPABILITY_PUBLIC | ResourceStorage::CAPABILITY_WRITABLE | ResourceStorage::CAPABILITY_HIERARCHICAL_IDENTIFIERS;
        $this->cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('azurestorage');
    }

    /**
     * Remove temporary used files.
     * This is a poor software architecture style: temp files should be deleted by the FAL users and not by the FAL drivers
     * @see https://forge.typo3.org/issues/56982
     * @see https://review.typo3.org/#/c/36446/
     */
    public function __destruct()
    {
        foreach ($this->temporaryPaths as $temporaryPath) {
            @unlink($temporaryPath);
        }
    }

    /**
     * Processes the configuration for this driver.
     */
    public function processConfiguration(): void
    {
        $this->account = $this->configuration['accountName'];
        $this->accesskey = $this->configuration['accountKey'];
        $this->container = $this->configuration['containerName'];

        if ((bool)$this->configuration['usehttps'] === true) {
            $this->protocol = 'https';
        }

        if (!empty($this->configuration['cdnendpoint'])) {
            $this->endpoint = $this->configuration['cdnendpoint'];
        }

        if (!empty($this->configuration['cacheControl'])) {
            $this->cacheControl = $this->configuration['cacheControl'];
        }
    }

    /**
     * Initializes this object. This is called by the storage after the driver
     * has been attached.
     *
     * @return void
     */
    public function initialize(): void
    {
        if (!empty($this->account) && !empty($this->accesskey) && !empty($this->container)) {
            $this->blobService = BlobRestProxy::createBlobService('
            DefaultEndpointsProtocol=' . $this->protocol . ';AccountName=' . $this->account . ';AccountKey=' . $this->accesskey);
        }
    }

    /**
     * Merges the capabilities merged by the user at the storage
     * configuration into the actual capabilities of the driver
     * and returns the result.
     *
     * @param int $capabilities
     * @return int
     */
    public function mergeConfigurationCapabilities($capabilities)
    {
        $this->capabilities &= $capabilities;

        return $this->capabilities;
    }

    /**
     * Returns the identifier of the root level folder of the storage.
     *
     * @return string
     */
    public function getRootLevelFolder()
    {
        return '';
    }

    /**
     * Returns the identifier of the default folder new files should be put into.
     *
     * @return string
     */
    public function getDefaultFolder()
    {
        return $this->getRootLevelFolder();
    }

    /**
     * Returns the public URL to a file.
     * Either fully qualified URL or relative to PATH_site (rawurlencoded).
     *
     * @param string $identifier
     * @return string
     */
    public function getPublicUrl($identifier)
    {
        $uriParts = GeneralUtility::trimExplode('/', ltrim($identifier, '/'), true);
        $uriParts = array_map('rawurlencode', $uriParts);
        $identifier = implode('/', $uriParts);

        if ($this->endpoint !== null) {
            if (substr($this->endpoint, -1) === '/') {
                $url = $this->protocol . '://' . $this->endpoint . $this->container . '/' . $identifier;
            } else {
                $url = $this->protocol . '://' . $this->endpoint . '/' . $this->container . '/' . $identifier;
            }

            return $url;
        }

        return $this->protocol . '://' . $this->account . '.blob.core.windows.net/' . $this->container . '/' . $identifier;
    }

    /**
     * Creates a folder, within a parent folder.
     * If no parent folder is given, a root level folder will be created
     *
     * @param string $newFolderName
     * @param string $parentFolderIdentifier
     * @param bool $recursive
     * @return string the Identifier of the new folder
     */
    public function createFolder($newFolderName, $parentFolderIdentifier = '', $recursive = false)
    {
        $parentFolderIdentifier = $this->normalizeFolderName($parentFolderIdentifier);
        $newFolderName = $this->normalizeFolderName($newFolderName);
        $newFolderIdentifier = $this->normalizeFolderName($parentFolderIdentifier . $newFolderName);
        $this->createBlockBlob($newFolderIdentifier);

        return $newFolderIdentifier;
    }

    /**
     * Renames a folder in this storage.
     *
     * @param string $folderIdentifier
     * @param string $newName
     * @return array A map of old to new file identifiers of all affected resources
     */
    public function renameFolder($folderIdentifier, $newName)
    {
        $newTargetParentFolderName = $this->normalizeFolderName(dirname($folderIdentifier));
        $newTargetFolderName = $this->normalizeFolderName($newName);
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        return $this->moveFolderWithinStorage($folderIdentifier, $newTargetParentFolderName, $newTargetFolderName);
    }

    /**
     * Removes a folder in filesystem.
     *
     * @param string $folderIdentifier
     * @param bool $deleteRecursively
     * @return bool
     */
    public function deleteFolder($folderIdentifier, $deleteRecursively = false)
    {
        $sourceFolderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $blobs = $this->getBlobsFromFolder($sourceFolderIdentifier);
        foreach ($blobs as $blob) {
            $this->blobService->deleteBlob($this->container, $blob->getName());
        }

        return true;
    }

    /**
     * Checks if a file exists.
     *
     * @param string $fileIdentifier
     * @return bool
     */
    public function fileExists($fileIdentifier)
    {
        if ($this->isFolder($fileIdentifier)) {
            return false;
        }

        return (bool)$this->getBlobProperties($fileIdentifier);
    }

    /**
     * Checks if a folder exists.
     *
     * @param string $folderIdentifier
     * @return bool
     */
    public function folderExists($folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        if ($folderIdentifier === $this->normalizeFolderName($this->getRootLevelFolder())) {
            return true;
        }

        $blob = $this->getBlobProperties($folderIdentifier);

        return $blob instanceof GetBlobPropertiesResult;
    }

    /**
     * Checks if a folder contains files and (if supported) other folders.
     *
     * @param string $folderIdentifier
     * @return bool TRUE if there are no files and folders within $folder
     */
    public function isFolderEmpty($folderIdentifier)
    {
        return ((int)$this->countFilesInFolder($folderIdentifier, true) + (int)$this->countFoldersInFolder($folderIdentifier, true) === 0);
    }

    /**
     * Adds a file from the local server hard disk to a given path in TYPO3s
     * virtual file system. This assumes that the local file exists, so no
     * further check is done here! After a successful the original file must
     * not exist anymore.
     *
     * @param string $localFilePath (within PATH_site)
     * @param string $targetFolderIdentifier
     * @param string $newFileName optional, if not given original name is used
     * @param bool $removeOriginal if set the original file will be removed
     *                                after successful operation
     * @return string the identifier of the new file
     */
    public function addFile($localFilePath, $targetFolderIdentifier, $newFileName = '', $removeOriginal = true)
    {
        if ($newFileName === '') {
            $newFileName = basename($localFilePath);
        }

        $targetFolderIdentifier = $this->normalizeFolderName($targetFolderIdentifier);
        $fileIdentifier = $targetFolderIdentifier . $newFileName;

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $contentType = finfo_file($fileInfo, $localFilePath);
        finfo_close($fileInfo);

        $pathInfo = pathinfo($newFileName);

        // Special mapping
        $fileExtensionToMimeTypeMapping = $GLOBALS['TYPO3_CONF_VARS']['SYS']['FileInfo']['fileExtensionToMimeType'];

        if (isset($pathInfo['extension']) && array_key_exists($pathInfo['extension'], $fileExtensionToMimeTypeMapping)) {
            $contentType = $fileExtensionToMimeTypeMapping[$pathInfo['extension']];
        }

        $options = new CreateBlockBlobOptions();
        $options->setContentType($contentType);
        $options->setCacheControl($this->cacheControl);

        $this->createBlockBlob($fileIdentifier, fopen($localFilePath, 'rb'), $options);

        if ($removeOriginal === true) {
            @unlink($localFilePath);
        }

        return $fileIdentifier;
    }

    /**
     * Creates a new (empty) file and returns the identifier.
     *
     * @param string $fileName
     * @param string $parentFolderIdentifier
     * @return string
     */
    public function createFile($fileName, $parentFolderIdentifier)
    {
        $parentFolderIdentifier = $this->normalizeFolderName($parentFolderIdentifier);
        $newIdentifier = $parentFolderIdentifier . $fileName;
        $this->createBlockBlob($newIdentifier);

        return $newIdentifier;
    }

    /**
     * Copies a file *within* the current storage.
     * Note that this is only about an inner storage copy action,
     * where a file is just copied to another folder in the same storage.
     *
     * @param string $fileIdentifier
     * @param string $targetFolderIdentifier
     * @param string $fileName
     * @return string the Identifier of the new file
     */
    public function copyFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $fileName)
    {
        $targetFolderIdentifier = $this->normalizeFolderName($targetFolderIdentifier);
        $targetFileName = $targetFolderIdentifier . $fileName;

        $this->copy($fileIdentifier, $targetFileName);

        return $targetFileName;
    }

    /**
     * Renames a file in this storage.
     *
     * @param string $fileIdentifier
     * @param string $newName The target path (including the file name!)
     * @return string The identifier of the file after renaming
     */
    public function renameFile($fileIdentifier, $newName)
    {
        $targetFolder = $this->normalizeFolderName(dirname($fileIdentifier));
        $this->moveFileWithinStorage($fileIdentifier, $targetFolder, $newName);

        return $targetFolder . $newName;
    }

    /**
     * Replaces a file with file in local file system.
     *
     * @param string $fileIdentifier
     * @param string $localFilePath
     * @return bool TRUE if the operation succeeded
     */
    public function replaceFile($fileIdentifier, $localFilePath)
    {
        $targetFolder = $this->normalizeFolderName(dirname($fileIdentifier));
        $newName = basename($fileIdentifier);
        $this->addFile($localFilePath, $targetFolder, $newName);

        return true;
    }

    /**
     * Removes a file from the filesystem. This does not check if the file is
     * still used or if it is a bad idea to delete it for some other reason
     * this has to be taken care of in the upper layers (e.g. the Storage)!
     *
     * @param string $fileIdentifier
     * @return bool TRUE if deleting the file succeeded
     */
    public function deleteFile($fileIdentifier)
    {
        try {
            if ($this->fileExists($fileIdentifier)) {
                $this->blobService->deleteBlob($this->container, $fileIdentifier);
                $this->cache->remove($this->hash($fileIdentifier));
            }

            return true;

        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param string $fileIdentifier
     * @param string $hashAlgorithm
     * @return string
     */
    public function hash($fileIdentifier, $hashAlgorithm = '')
    {
        return $this->hashIdentifier($fileIdentifier);
    }

    /**
     * Moves a file *within* the current storage.
     * Note that this is only about an inner-storage move action,
     * where a file is just moved to another folder in the same storage.
     *
     * @param string $fileIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFileName
     * @return string
     */
    public function moveFileWithinStorage($fileIdentifier, $targetFolderIdentifier, $newFileName)
    {
        $targetFolderIdentifier = $this->normalizeFolderName($targetFolderIdentifier);
        $targetName = $this->normalizeFolderName($targetFolderIdentifier) . $newFileName;
        $this->move($fileIdentifier, $targetName);

        if ($this->cache->has($this->hash($fileIdentifier))) {
            $this->cache->remove($this->hash($fileIdentifier));
        }

        return $targetName;
    }

    /**
     * Folder equivalent to moveFileWithinStorage().
     *
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     * @return array All files which are affected, map of old => new file identifiers
     */
    public function moveFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        return $this->moveOrCopyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName,
            'move');
    }

    /**
     * Folder equivalent to copyFileWithinStorage().
     *
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     * @return bool
     */
    public function copyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName)
    {
        return count($this->moveOrCopyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier,
            $newFolderName,
            'copy')) ? true : false;
    }

    /**
     * Returns the contents of a file. Beware that this requires to load the
     * complete file into memory and also may require fetching the file from an
     * external location. So this might be an expensive operation (both in terms
     * of processing resources and money) for large files.
     *
     * @param string $fileIdentifier
     * @return string The file contents
     */
    public function getFileContents($fileIdentifier)
    {
        $content = '';

        /** @var GetBlobResult $blob */
        $blob = $this->getBlob($fileIdentifier);

        if ($blob instanceof GetBlobResult) {
            $content = stream_get_contents($blob->getContentStream());
        }

        return $content;
    }

    /**
     * Sets the contents of a file to the specified value.
     *
     * @param string $fileIdentifier
     * @param string $contents
     * @return int The number of bytes written to the file
     */
    public function setFileContents($fileIdentifier, $contents)
    {
        $this->blobService->createBlockBlob($this->container, $fileIdentifier, $contents);

        return strlen($contents);
    }

    /**
     * Checks if a file inside a folder exists
     *
     * @param string $fileName
     * @param string $folderIdentifier
     * @return bool
     */
    public function fileExistsInFolder($fileName, $folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $blob = $this->getBlobProperties($folderIdentifier . $fileName);

        return $blob instanceof GetBlobPropertiesResult;
    }

    /**
     * Checks if a folder inside a folder exists.
     *
     * @param string $folderName
     * @param string $folderIdentifier
     * @return bool
     */
    public function folderExistsInFolder($folderName, $folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $folderName = $this->normalizeFolderName($folderName);
        $blob = $this->getBlobProperties($this->normalizeFolderName($folderIdentifier . $folderName));

        return $blob instanceof GetBlobPropertiesResult;
    }

    /**
     * Returns a path to a local copy of a file for processing it. When changing the
     * file, you have to take care of replacing the current version yourself!
     *
     * @param string $fileIdentifier
     * @param bool $writable Set this to FALSE if you only need the file for read
     *                       operations. This might speed up things, e.g. by using
     *                       a cached local version. Never modify the file if you
     *                       have set this flag!
     * @return string The path to the file on the local disk
     */
    public function getFileForLocalProcessing($fileIdentifier, $writable = true)
    {
        $temporaryPath = '';

        /** @var GetBlobResult $blob */
        $blob = $this->getBlob($fileIdentifier);

        if ($blob !== false) {
            $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);

            // Running inside Azure web app? Use local temp directory for performance reasons dir
            if (getenv('TEMP') && getenv('WEBSITE_SKU')) {
                $temporaryPath = getenv('TEMP') . '\\' . basename($temporaryPath);
            }

            $result = file_put_contents($temporaryPath, stream_get_contents($blob->getContentStream()));
            if ($result === false) {
                throw new \RuntimeException('Writing file ' . $fileIdentifier . ' to temporary path failed.',
                    1320577649);
            }
        }

        if (!isset($this->temporaryPaths[$temporaryPath])) {
            $this->temporaryPaths[$temporaryPath] = $temporaryPath;
        }

        return $temporaryPath;
    }

    /**
     * Returns the permissions of a file/folder as an array
     * (keys r, w) of boolean flags
     *
     * @param string $identifier
     * @return array
     */
    public function getPermissions($identifier)
    {
        return ['r' => true, 'w' => true];
    }

    /**
     * Directly output the contents of the file to the output
     * buffer. Should not take care of header files or flushing
     * buffer before. Will be taken care of by the Storage.
     *
     * @param string $identifier
     * @return void
     */
    public function dumpFileContents($identifier)
    {
        try {
            /** @var GetBlobResult $blob */
            $blob = $this->getBlob($identifier);
            fpassthru($blob->getContentStream());
        } catch (\Throwable $e) {
        }
    }

    /**
     * Checks if a given identifier is within a container, e.g. if
     * a file or folder is within another folder.
     * This can e.g. be used to check for web-mounts.
     *
     * Hint: this also needs to return TRUE if the given identifier
     * matches the container identifier to allow access to the root
     * folder of a filemount.
     *
     * @param string $folderIdentifier
     * @param string $identifier identifier to be checked against $folderIdentifier
     * @return bool TRUE if $content is within or matches $folderIdentifier
     */
    public function isWithin($folderIdentifier, $identifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);

        if ($folderIdentifier === '') {
            return true;
        }

        return str_starts_with($identifier, $folderIdentifier);
    }

    /**
     * Returns information about a file.
     *
     * @param string $fileIdentifier
     * @param array  $propertiesToExtract Array of properties which are be extracted
     *                                   If empty all will be extracted
     *
     * @return array
     * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidPathException
     * @throws \InvalidArgumentException
     */
    public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = [])
    {
        $fileInfo = [];
        if ($fileIdentifier === '') {
            $properties = $this->blobService->getContainerProperties($this->container);
        } else {
            /** @var GetBlobPropertiesResult $blob */
            $blob = $this->getBlobProperties($fileIdentifier);

            if ($blob === false) {
                if ($this->isFolder($fileIdentifier)) {
                    throw new Exception\FolderDoesNotExistException(
                        'Folder "' . $fileIdentifier . '" does not exist.',
                        1587367307
                    );
                }
                throw new \InvalidArgumentException('File ' . $fileIdentifier . ' does not exist.', 1587367308);
            }

            $properties = $blob->getProperties();
            $fileInfo['size'] = $properties->getContentLength();
            $fileInfo['mimetype'] = $properties->getContentType();
        }

        $fileInfo = array_merge($fileInfo, [
            'identifier' => $fileIdentifier,
            'name' => basename(rtrim($fileIdentifier, '/')),
            'storage' => $this->storageUid,
            'identifier_hash' => $this->hashIdentifier($fileIdentifier),
            'folder_hash' => $this->hashIdentifier($this->getParentFolderIdentifierOfIdentifier($fileIdentifier)),
            'mtime' => $properties->getLastModified()->format('U'),
        ]);

        $fileInfoToExtract = [];

        foreach ($propertiesToExtract as $propertyName) {
            $fileInfoToExtract[$propertyName] = $fileInfo[$propertyName];
        }

        return $fileInfoToExtract ?: $fileInfo;
    }

    /**
     * Returns information about a file.
     *
     * @param string $folderIdentifier
     *
     * @return array
     * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidPathException
     * @throws Exception\FolderDoesNotExistException
     */
    public function getFolderInfoByIdentifier($folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);

        return $this->getFileInfoByIdentifier($folderIdentifier);
    }

    /**
     * Returns the identifier of a file inside the folder
     *
     * @param string $fileName
     * @param string $folderIdentifier
     * @return string file identifier
     */
    public function getFileInFolder($fileName, $folderIdentifier)
    {
        return $this->normalizeFolderName($folderIdentifier) . $fileName;
    }

    /**
     * Returns a list of files inside the specified path
     *
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $filenameFilterCallbacks callbacks for filtering the items
     * @param string $sort Property name used to sort the items.
     *                     Among them may be: '' (empty, no sorting), name,
     *                     fileext, size, tstamp and rw.
     *                     If a driver does not support the given property, it
     *                     should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     * @return array of FileIdentifiers
     */
    public function getFilesInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $filenameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ) {

        $files = [];
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);

        try {

            $iterator = new \ArrayIterator($this->getListBlobs($folderIdentifier, $recursive));

            if ($iterator->count() === 0) {
                return [];
            }

            // $c is the counter for how many items we still have to fetch (-1 is unlimited)
            $c = $numberOfItems > 0 ? $numberOfItems : - 1;

            while ($iterator->valid() && ($numberOfItems === 0 || $c > 0)) {

                /** @var Blob $blob */
                $blob = $iterator->current();
                $fileName = $blob->getName();
                // go on to the next iterator item now as we might skip this one early
                $iterator->next();

                if (substr($fileName, -1) === '/') {
                    // folder
                    continue;
                }

                if ($recursive === false && substr_count($fileName, '/') > substr_count($folderIdentifier, '/')) {
                    // in sub-folders
                    continue;
                }


                if ($start > 0) {
                    $start--;
                } else {
                    $files[$blob->getName()] = $blob->getName();
                    // Decrement item counter to make sure we only return $numberOfItems
                    // we cannot do this earlier in the method (unlike moving the iterator forward) because we only add the
                    // item here
                    --$c;
                }
            }
            uksort($files, 'strnatcasecmp');
            if ($sortRev) {
                $files = array_reverse($files);
            }
            return $files;

        } catch (\Throwable $e) { }

        return $files;

    }

    /**
     * Function to get all blobs (files and folders)
     *
     * @param string $identifier
     * @param bool $recursive
     * @return array
     */

    private function getListBlobs($identifier, $recursive=false)
    {
        $options = new ListBlobsOptions();
        $options->setPrefix($identifier);
        $options->setIncludeUncommittedBlobs(false);
        $options->setIncludeSnapshots(false);
        $options->setIncludeCopy(false);
        $options->setIncludeMetadata(false);

        if (!$recursive) {
            //just fetch one level
            $options->setDelimiter('/');
        }
        $result = [];
        do {
             /** @var ListBlobsResult $blobList */
            $blobList = $this->blobService->listBlobs($this->container, $options);
            $result = array_merge($result, $blobList->getBlobPrefixes(), $blobList->getBlobs());
            $nextMarker = $blobList->getNextMarker();
            $options->setMarker($nextMarker);
        } while (!empty($nextMarker));

        return $result;
    }

    /**
     * Returns the identifier of a folder inside the folder
     *
     * @param string $folderName The name of the target folder
     * @param string $folderIdentifier
     * @return string folder identifier
     */
    public function getFolderInFolder($folderName, $folderIdentifier)
    {
        return $this->normalizeFolderName($this->normalizeFolderName($folderIdentifier) . $folderName);
    }

    /**
     * Returns a list of folders inside the specified path
     *
     * @param string $folderIdentifier
     * @param int $start
     * @param int $numberOfItems
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks callbacks for filtering the items
     * @param string $sort Property name used to sort the items.
     *                     Among them may be: '' (empty, no sorting), name,
     *                     fileext, size, tstamp and rw.
     *                     If a driver does not support the given property, it
     *                     should fall back to "name".
     * @param bool $sortRev TRUE to indicate reverse sorting (last to first)
     * @return array of Folder Identifier
     */
    public function getFoldersInFolder(
        $folderIdentifier,
        $start = 0,
        $numberOfItems = 0,
        $recursive = false,
        array $folderNameFilterCallbacks = [],
        $sort = '',
        $sortRev = false
    ) {

        $folders = [];
        try {

            $folderIdentifier = $this->normalizeFolderName($folderIdentifier);

            $iterator = new \ArrayIterator($this->getListBlobs($folderIdentifier, $recursive));

            if ($iterator->count() === 0) {
                return [];
            }

            // $c is the counter for how many items we still have to fetch (-1 is unlimited)
            $c = $numberOfItems > 0 ? $numberOfItems : - 1;

            while ($iterator->valid() && ($numberOfItems === 0 || $c > 0)) {

                /** @var Blob $blob */
                $blob = $iterator->current();
                $blobName = $blob->getName();
                // go on to the next iterator item now as we might skip this one early
                $iterator->next();

                if ($blobName === $folderIdentifier) {
                    continue;
                }
                if (substr($blobName, -1) === '/') {
                    if ($recursive === false && $this->isSubSubFolder($blobName, $folderIdentifier)) {
                        continue;
                    }
                    $folders[$blobName] = $blobName;
                }
            }
            uksort($folders, 'strnatcasecmp');
            if ($sortRev) {
                $folders = array_reverse($folders);
            }
            return $folders;

        } catch (\Throwable $e) { }

        return $folders;
    }

    /**
     * Returns the number of files inside the specified path
     *
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $filenameFilterCallbacks callbacks for filtering the items
     * @return int Number of files in folder
     */
    public function countFilesInFolder($folderIdentifier, $recursive = false, array $filenameFilterCallbacks = [])
    {
        $files = 0;
        $iterator = new \ArrayIterator($this->getListBlobs($folderIdentifier, $recursive));

        if ($iterator->count() === 0) {
            return $files;
        }

        while ($iterator->valid()) {

            /** @var Blob $blob */
            $blob = $iterator->current();
            // go on to the next iterator item now as we might skip this one early
            $iterator->next();

            // Skip sub folders if necessary
            if (!$recursive && strpos(str_replace($folderIdentifier, '', $blob->getName()), '/') !== false) {
                continue;
            }

            // Skip folders
            if ($this->isFolder($blob->getName())) {
                continue;
            }

            $fileName = basename($blob->getName());

            // check filter
            if (!$this->applyFilterMethodsToDirectoryItem($filenameFilterCallbacks, $fileName,
                $blob->getName(), $folderIdentifier)
            ) {
                continue;
            }

            $files++;
        }

        return $files;

    }

    /**
     * Applies a set of filter methods to a file name to find out if it should be used or not. This is e.g. used by
     * directory listings.
     *
     * @param array $filterMethods The filter methods to use
     * @param string $itemName
     * @param string $itemIdentifier
     * @param string $parentIdentifier
     * @throws \RuntimeException
     * @return bool
     */
    protected function applyFilterMethodsToDirectoryItem(
        array $filterMethods,
        $itemName,
        $itemIdentifier,
        $parentIdentifier
    ) {
        foreach ($filterMethods as $filter) {
            if (is_array($filter)) {
                $result = $filter($itemName, $itemIdentifier, $parentIdentifier, [], $this);
                // We have to use -1 as the „don't include“ return value, as call_user_func() will return FALSE
                // If calling the method succeeded and thus we can't use that as a return value.
                if ($result === -1) {
                    return false;
                }

                if ($result === false) {
                    throw new \RuntimeException('Could not apply file/folder name filter ' . $filter[0] . '::' . $filter[1]);
                }
            }
        }

        return true;
    }

    /**
     * Returns the number of folders inside the specified path
     *
     * @param string $folderIdentifier
     * @param bool $recursive
     * @param array $folderNameFilterCallbacks callbacks for filtering the items
     * @return int Number of folders in folder
     */
    public function countFoldersInFolder($folderIdentifier, $recursive = false, array $folderNameFilterCallbacks = [])
    {
        $folders = 0;
        $iterator = new \ArrayIterator($this->getListBlobs($folderIdentifier, $recursive));

        if ($iterator->count() === 0) {
            return $folders;
        }

        /** @var Blob $l */
        while ($iterator->valid()) {
            /** @var Blob $blob */
            $blob = $iterator->current();
            // go on to the next iterator item now as we might skip this one early
            $iterator->next();

            if ($folderIdentifier === $this->getDefaultFolder()) {
                if ($this->isFolder($blob->getName()) && substr_count($blob->getName(), '/') === 1) {
                    $folders++;
                }
            } else if ($this->isFolder(str_replace($folderIdentifier, '', $blob->getName()))) {
                $folders++;
            }
        }

        return $folders;
    }

    /**
     * @param string $folderName
     * @return string
     */
    private function normalizeFolderName($folderName)
    {
        $folderName = trim($folderName, '/');
        if ($folderName === '.' || $folderName === '') {
            return '';
        }

        return $folderName . '/';
    }

    /**
     * Returns the identifier of the folder the file resides in
     *
     * @param string $fileIdentifier
     *
     * @return string
     * @throws \TYPO3\CMS\Core\Resource\Exception\InvalidPathException
     */
    public function getParentFolderIdentifierOfIdentifier(string $fileIdentifier): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        return str_replace('\\', '/', dirname($fileIdentifier));
    }

    /**
     * @return \TYPO3\CMS\Core\Resource\ResourceStorage
     */
    protected function getStorage()
    {
        if (!$this->storage) {
            /** @var $storageRepository \TYPO3\CMS\Core\Resource\StorageRepository */
            $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
            $this->storage = $storageRepository->findByUid($this->storageUid);
        }

        return $this->storage;
    }

    /**
     * @return string
     */
    protected function getProcessingFolder()
    {
        $folders = $this->getStorage()->getProcessingFolders();

        if (is_array($folders)) {
            /** @var Folder $folder */
            foreach ($folders as $folder) {
                if ($this->getStorage()->getUid() === $folder->getStorage()->getUid()) {
                    return $folder->getName();
                }
            }
        }

        // Just in case
        return '_processed_';
    }

    /**
     * @param string $fileIdentifier
     * @return bool
     */
    protected function isFolder($fileIdentifier)
    {
        return (substr($fileIdentifier, -1) === '/');
    }

    /**
     * @param $fileIdentifier
     * @return bool|GetBlobPropertiesResult
     */
    protected function getBlobProperties($fileIdentifier)
    {

        if ($fileIdentifier === '') {
            return false;
        }

        try {

            $blob = null;
            $blob = $this->cache->get($this->hash($fileIdentifier));

            if (!$blob instanceof GetBlobPropertiesResult) {
                /** @var GetBlobPropertiesResult $blob */
                $blob = $this->blobService->getBlobProperties($this->container, $fileIdentifier);
                $this->cache->set($this->hash($fileIdentifier), $blob);
            }


        } catch (\Throwable $e) {
        }

        if (isset($blob) && $blob instanceof GetBlobPropertiesResult) {
            return $blob;
        }

        return false;
    }

    /**
     * @param $fileIdentifier
     * @param array $properties
     */
    public function setFileProperties($fileIdentifier, array $properties)
    {
        try {
            $blobProperties = $this->blobService->getBlobProperties($this->container, $fileIdentifier)->getProperties();
            $newProperties = new BlobProperties();
            // we need to copy some properties, so they don't get cleared
            // see https://docs.microsoft.com/en-us/rest/api/storageservices/set-blob-properties
            // "If this property is not specified on the request, then the property will be cleared for the blob. "
            $newProperties->setContentMD5($blobProperties->getContentMD5());
            $newProperties->setCacheControl($blobProperties->getCacheControl());
            $newProperties->setContentEncoding($blobProperties->getContentEncoding());
            $newProperties->setContentLanguage($blobProperties->getContentLanguage());
            $newProperties->setContentDisposition($blobProperties->getContentDisposition());
        } catch (\Throwable $e) {
        }

        //TODO: support other properties too, see MicrosoftAzure\Storage\Blob\Models\BlobProperties
        $newProperties->setContentType(
            Utilities::tryGetValue($properties, Resources::CONTENT_TYPE)
        );
        $this->setBlobProperties($fileIdentifier, $newProperties);
    }

    /**
     * @param string $fileIdentifier
     * @param BlobProperties $properties
     * @return bool|SetBlobPropertiesResult
     */
    protected function setBlobProperties($fileIdentifier, $properties)
    {

        if ($fileIdentifier === '') {
            return false;
        }

        try {
            $setBlobPropertiesOptions = new SetBlobPropertiesOptions($properties);
            /** @var SetBlobPropertiesResult $blob */
            $blob = $this->blobService->setBlobProperties($this->container, $fileIdentifier, $setBlobPropertiesOptions);
        } catch (\Throwable $e) {
        }

        if (isset($blob) && $blob instanceof SetBlobPropertiesResult) {
            return $blob;
        }

        return false;
    }

    /**
     * @param $fileIdentifier
     * @return bool|GetBlobResult
     */
    protected function getBlob($fileIdentifier)
    {

        if ($fileIdentifier === '') {
            return false;
        }

        try {

            /** @var GetBlobResult $blob */
            $blob = $this->blobService->getBlob($this->container, $fileIdentifier);

        } catch (\Throwable $e) {
        }

        if (isset($blob) && $blob instanceof GetBlobResult) {
            return $blob;
        }

        return false;
    }

    /**
     * @param $name
     * @param string|resource|StreamInterface $content
     * @param CreateBlockBlobOptions|null $options
     * @return \MicrosoftAzure\Storage\Blob\Models\CopyBlobResult
     */
    protected function createBlockBlob($name, $content = '', CreateBlockBlobOptions $options = null)
    {
        if (!is_string($content) && !is_resource($content) && !($content instanceof StreamInterface)) {
            throw new \InvalidArgumentException('Content was not a valid type');
        }

        if (is_string($content) && $content === '') {
            $content = chr(26);
        }

        return $this->blobService->createBlockBlob($this->container, $name, $content, $options);

    }

    /**
     * @param string $sourceFolderIdentifier
     * @return array
     */
    protected function getBlobsFromFolder($sourceFolderIdentifier)
    {
        return $this->getListBlobs($sourceFolderIdentifier, true);
    }

    /**
     * @param string $sourceFolderIdentifier
     * @param string $targetFolderIdentifier
     * @param string $newFolderName
     * @param string $action "move" or "copy"
     * @return array
     */
    protected function moveOrCopyFolderWithinStorage(
        $sourceFolderIdentifier,
        $targetFolderIdentifier,
        $newFolderName,
        $action
    ) {
        $affected = [];
        $destinationFolderName = $this->normalizeFolderName($this->normalizeFolderName($targetFolderIdentifier) . $this->normalizeFolderName($newFolderName));
        $sourceFolderIdentifier = $this->normalizeFolderName($sourceFolderIdentifier);

        $blobs = $this->getBlobsFromFolder($sourceFolderIdentifier);
        foreach ($blobs as $blob) {
            $newIdentifier = $destinationFolderName . substr($blob->getName(), strlen($sourceFolderIdentifier));
            $this->{$action}($blob->getName(), $newIdentifier);
            $affected[$blob->getName()] = $newIdentifier;
        }

        return $affected;
    }

    /**
     * @param string $sourceIdentifier
     * @param string $targetIdentifier
     */
    protected function copy($sourceIdentifier, $targetIdentifier)
    {
        try {
            $this->blobService->copyBlob($this->container, $targetIdentifier, $this->container, $sourceIdentifier);
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param string $sourceIdentifier
     * @param string $destinationIdentifier
     */
    protected function move($sourceIdentifier, $destinationIdentifier)
    {

        $this->copy($sourceIdentifier, $destinationIdentifier);

        try {
            $this->blobService->deleteBlob($this->container, $sourceIdentifier);

            if ($this->cache->has($this->hash($sourceIdentifier))) {
                $this->cache->remove($this->hash($sourceIdentifier));
            }

        } catch (\Throwable $e) {
        }
    }

    /**
     * Checks whether a folder is a sub-sub folder from another folder
     *
     * @param string $folderToCheck
     * @param string $parentFolderIdentifier
     * @return bool
     */
    protected function isSubSubFolder($folderToCheck, $parentFolderIdentifier)
    {
        return substr_count($folderToCheck, '/') > substr_count($parentFolderIdentifier, '/') + 1;
    }
}

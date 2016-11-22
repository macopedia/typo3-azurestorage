<?php

namespace B3N\AzureStorage\TYPO3\Driver;

use B3N\AzureStorage\TYPO3\Exceptions\InvalidConfigurationException;
use MicrosoftAzure\Storage\Blob\Internal\IBlob;
use MicrosoftAzure\Storage\Blob\Models\Blob;
use MicrosoftAzure\Storage\Blob\Models\CreateBlobOptions;
use MicrosoftAzure\Storage\Blob\Models\GetBlobResult;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions;
use MicrosoftAzure\Storage\Blob\Models\ListBlobsResult;
use MicrosoftAzure\Storage\Common\ServiceException;
use MicrosoftAzure\Storage\Common\ServicesBuilder;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
    private $endpoint = null;


    /**
     * @var string
     */
    private $protocol = 'http';

    /**
     * @var \TYPO3\CMS\Core\Resource\ResourceStorage
     */
    private $storage;

    /**
     * @var VariableFrontend
     */
    private $cache;

    /**
     * @var bool
     */
    private $cacheEnabled;

    /**
     * Initialize this driver and expose the capabilities for the repository to use
     *
     * @param array $configuration
     */
    public function __construct(array $configuration = [], $cacheEnabled = true)
    {
        parent::__construct($configuration);

        $this->capabilities = ResourceStorage::CAPABILITY_BROWSABLE | ResourceStorage::CAPABILITY_PUBLIC | ResourceStorage::CAPABILITY_WRITABLE;

        $this->cacheEnabled = $cacheEnabled;

        if ($this->cacheEnabled === true) {
            $this->cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('azurestorage');
        }
    }

    /**
     * Processes the configuration for this driver.
     * @throws InvalidConfigurationException
     */
    public function processConfiguration()
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
    }

    /**
     * Initializes this object. This is called by the storage after the driver
     * has been attached.
     *
     * @return void
     */
    public function initialize()
    {
        if (!empty($this->account) && !empty($this->accesskey) && !empty($this->container)) {
            $this->blobService = ServicesBuilder::getInstance()->createBlobService('
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

        if (!is_null($this->endpoint)) {
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
        $this->moveFolderWithinStorage($folderIdentifier, $newTargetParentFolderName, $newTargetFolderName);

        return [
            $folderIdentifier => $newTargetParentFolderName . $newTargetFolderName
        ];
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

        return (bool)$this->getBlob($fileIdentifier);
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

        $blob = $this->getBlob($folderIdentifier);

        if ($blob) {
            return true;
        }

        return false;
    }

    /**
     * Checks if a folder contains files and (if supported) other folders.
     *
     * @param string $folderIdentifier
     * @return bool TRUE if there are no files and folders within $folder
     */
    public function isFolderEmpty($folderIdentifier)
    {
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $options = new ListBlobsOptions();
        $options->setPrefix($folderIdentifier);

        /** @var ListBlobsResult $blobListResult */
        $blobListResult = $this->blobService->listBlobs($this->container, $options);
        $blobs = $blobListResult->getBlobs();

        $num = 0;
        
        // Exclude the identifier itself, because this is just a placeholder file
        /** @var Blob $blob */
        foreach ($blobs as $blob) {
            if ($blob->getName() !== $folderIdentifier){
                $num++;
            }
        }

        return ($num === 0);
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

        $options = new CreateBlobOptions();
        $options->setContentType($contentType);

        $this->createBlockBlob($fileIdentifier, file_get_contents($localFilePath), $options);

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

                if ($this->cacheEnabled === true) {
                    $this->cache->remove($this->hash($fileIdentifier));
                }
            }

            return true;
        } catch (ServiceException $e) {
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
        if (!empty($hashAlgorithm)) {
            return $hashAlgorithm($fileIdentifier);
        }

        return sha1($fileIdentifier);
    }

    /**
     * @param string $identifier
     * @return string
     */
    public function hashIdentifier($identifier)
    {
        return sha1($identifier);
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
        return $this->moveOrCopyFolderWithinStorage($sourceFolderIdentifier, $targetFolderIdentifier, $newFolderName,
            'copy');
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
        $blob = $this->getBlob($fileIdentifier, true);

        if ($blob !== false) {
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
        $blob = $this->getBlob($folderIdentifier . $fileName);

        if ($blob) {
            return true;
        }

        return false;
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
        $blob = $this->getBlob($this->normalizeFolderName($folderIdentifier . $folderName));

        if ($blob) {
            return true;
        }

        return false;
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
        $blob = $this->getBlob($fileIdentifier, true);

        if ($blob !== false) {
            $source = stream_get_contents($blob->getContentStream());
            $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
            $result = file_put_contents($temporaryPath, $source);
            if ($result === false) {
                throw new \RuntimeException('Writing file ' . $fileIdentifier . ' to temporary path failed.',
                    1320577649);
            }
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
            $blob = $this->getBlob($identifier, true);
            fpassthru($blob->getContentStream());
        } catch (ServiceException $d) {
            //@TODO Exception handling
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
        if ($folderIdentifier === '') {
            return true;
        }
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);

        return GeneralUtility::isFirstPartOfStr($identifier, $folderIdentifier);

    }

    /**
     * Returns information about a file.
     *
     * @param string $fileIdentifier
     * @param array $propertiesToExtract Array of properties which are be extracted
     *                                   If empty all will be extracted
     * @return array
     */
    public function getFileInfoByIdentifier($fileIdentifier, array $propertiesToExtract = [])
    {

        $fileInfo = [];
        if ($fileIdentifier === '') {
            $properties = $this->blobService->getContainerProperties($this->container);
        } else {

            /** @var GetBlobResult $blob */
            $blob = $this->getBlob($fileIdentifier, true);
            $properties = $blob->getProperties();
            $fileInfo['size'] = $properties->getContentLength();
            $fileInfo['mimetype'] = $properties->getContentType();

        }

        return array_merge($fileInfo, [
            'identifier' => $fileIdentifier,
            'name' => basename(rtrim($fileIdentifier, '/')),
            'storage' => $this->storageUid,
            'identifier_hash' => $this->hash($fileIdentifier, ''),
            'folder_hash' => $this->hash(dirname($fileIdentifier), ''),
            'mtime' => $properties->getLastModified()->format('U'),
        ]);
    }

    /**
     * Returns information about a file.
     *
     * @param string $folderIdentifier
     * @return array
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

            $options = new ListBlobsOptions();
            $options->setPrefix($folderIdentifier);


            /** @var ListBlobsResult $blobList */
            $blobList = $this->blobService->listBlobs($this->container, $options);

            /** @var Blob $blob */
            foreach ($blobList->getBlobs() as $blob) {
                $fileName = $blob->getName();
                if (substr($fileName, -1) === '/') {
                    // folder
                    continue;
                }

                if ($recursive === false && substr_count($fileName, '/') > substr_count($folderIdentifier, '/')) {
                    // in sub-folders
                    continue;
                }

                $files[$fileName] = $fileName;

            }

        } catch (ServiceException $e) {
            //@TODO implement exception handling
        }

        return $files;

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
        return $this->normalizeFolderName($this->normalizeFolderName($folderIdentifier) . $folderName);;
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
        $folderIdentifier = $this->normalizeFolderName($folderIdentifier);
        $options = new ListBlobsOptions();
        $options->setPrefix($folderIdentifier);

        /** @var ListBlobsResult $blobList */
        $blobList = $this->blobService->listBlobs($this->container, $options);

        if ($blobList instanceof ListBlobsResult) {
            /** @var Blob $blob */
            foreach ($blobList->getBlobs() as $blob) {

                $blobName = $blob->getName();
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
        }

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
        // TODO: Implement countFilesInFolder() method.
        //return 1;
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
        // TODO: Implement countFoldersInFolder() method.
        //return 0;
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
     * @return mixed
     */
    public function getParentFolderIdentifierOfIdentifier($fileIdentifier)
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
            $storageRepository = GeneralUtility::makeInstance('TYPO3\CMS\Core\Resource\StorageRepository');
            $this->storage = $storageRepository->findByUid($this->storageUid);
        }

        return $this->storage;
    }

    /**
     * @return string
     */
    protected function getProcessingFolder()
    {
        return $this->getStorage()->getProcessingFolder()->getName();
    }

    /**
     * @param string $fileIdentifier
     * @return bool
     */
    protected function isFolder($fileIdentifier)
    {
        return (substr($fileIdentifier, -1) === '/');
    }

    protected function getBlob($fileIdentifier, $force = false)
    {

        if ($fileIdentifier === '') {
            return false;
        }

        try {

            $blob = null;
            if ($this->cacheEnabled === true) {
                $blob = $this->cache->get($this->hash($fileIdentifier));
            }

            if (!$blob instanceof GetBlobResult || $force === true) {
                /** @var GetBlobResult $blob */
                $blob = $this->blobService->getBlob($this->container, $fileIdentifier);

                if ($this->cacheEnabled === true) {
                    $this->cache->set($this->hash($fileIdentifier), $blob);
                }
            }

        } catch (ServiceException $e) {
            return false;
        }

        if (isset($blob) && $blob instanceof GetBlobResult) {
            return $blob;
        }

        return false;
    }

    protected function createBlockBlob($name, $content = '', $options = null)
    {
        if (!is_string($content)) {
            throw new \InvalidArgumentException('Content was not type of string');
        }

        if ($content === '') {
            $content = chr(26);
        }

        $this->blobService->createBlockBlob($this->container, $name, $content, $options);
    }

    /**
     * @param string $sourceFolderIdentifier
     * @return array
     */
    protected function getBlobsFromFolder($sourceFolderIdentifier)
    {
        $blobs = [];
        $options = new ListBlobsOptions();
        $options->setPrefix($sourceFolderIdentifier);

        /** @var ListBlobsResult $blobListResult */
        $blobListResult = $this->blobService->listBlobs($this->container, $options);
        if ($blobListResult instanceof ListBlobsResult) {
            $blobs = $blobListResult->getBlobs();
        }

        return $blobs;
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
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function copy($sourceIdentifier, $targetIdentifier)
    {
        try {
            $this->blobService->copyBlob($this->container, $targetIdentifier, $this->container, $sourceIdentifier);
        } catch (ServiceException $e) {
            //@TODO Exception handling
        }
    }

    /**
     * @param string $sourceIdentifier
     * @param string $destinationIdentifier
     * @throws \TYPO3\CMS\Core\Exception
     */
    protected function move($sourceIdentifier, $destinationIdentifier)
    {
        $this->copy($sourceIdentifier, $destinationIdentifier);
        try {
            $this->blobService->deleteBlob($this->container, $sourceIdentifier);
        } catch (ServiceException $e) {
            //@TODO Exception handling
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

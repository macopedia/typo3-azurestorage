<?php

namespace B3N\AzureStorageTest\TYPO3\Driver;

use B3N\AzureStorage\TYPO3\Driver\StorageDriver;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class StrorageDriver extends \PHPUnit_Framework_TestCase
{

    /**
     * @var BlobRestProxy
     */
    private $blobRestProxy;

    /**
     * @var StorageDriver
     */
    private $storageDriver;

    public function __construct()
    {

        parent::__construct();

        $this->blobRestProxy = $this->prophesize(BlobRestProxy::class);
        $this->storageDriver = new StorageDriver([
            'accountName' => '',
            'accountKey' => '',
            'containerName' => '',
            'usehttps' => true
        ], false);

        $this->storageDriver->processConfiguration();
        $this->storageDriver->initialize();

    }

    public function testCreateFile()
    {
        $this->assertEquals('foo', $this->storageDriver->createFile('foo', ''));
    }

    public function testRenameFile()
    {
        $this->storageDriver->renameFile('foo', 'bar');
        $this->assertTrue($this->storageDriver->fileExists('bar'));
    }

    public function testCopyFile()
    {
        $this->assertEquals('barCopy', $this->storageDriver->copyFileWithinStorage('bar', '', 'barCopy'));
    }

    public function testDeleteFile()
    {
        $this->assertTrue($this->storageDriver->deleteFile('bar'));
        $this->assertTrue($this->storageDriver->deleteFile('barCopy'));
    }

    public function testCreateFolder()
    {
        $this->assertEquals('baz/', $this->storageDriver->createFolder('baz/'));
    }

    public function testRenameFolder()
    {
        $this->storageDriver->renameFolder('baz/', 'bazinga/');
        $this->assertTrue($this->storageDriver->folderExists('bazinga/'));
    }

    public function testIsFolderEmpty()
    {
        $this->assertTrue($this->storageDriver->isFolderEmpty('bazinga/'));
    }

    public function testMoveFile()
    {
        $file = $this->storageDriver->createFile('bar', '');
        $this->storageDriver->moveFileWithinStorage($file, 'bazinga/', $file);

        $this->assertTrue($this->storageDriver->fileExists('bazinga/' .$file));
    }

    public function testIsFolderNotEmpty()
    {
        $this->assertFalse($this->storageDriver->isFolderEmpty('bazinga/'));
    }

    public function testFileExistsInFolder()
    {
        $this->assertTrue($this->storageDriver->fileExistsInFolder('bar', 'bazinga/'));
    }

    public function testMoveFolder()
    {
        $arr = $this->storageDriver->moveFolderWithinStorage('bazinga/', '', 'bazingaMove');

        $this->assertArraySubset([
            'bazinga/' => 'bazingaMove/',
            'bazinga/bar' => 'bazingaMove/bar'
        ], $arr);
    }

    public function testCreateFolderInFolder()
    {
        $identifier = $this->storageDriver->createFolder('folder', 'bazingaMove/');
        $this->assertTrue($this->storageDriver->folderExists($identifier));
    }

    public function testFolderExistsInFolder()
    {
        $this->assertTrue($this->storageDriver->folderExistsInFolder('folder', 'bazingaMove/'));
    }

    public function testGetFilesInFolder()
    {
        $this->assertArrayHasKey('bazingaMove/bar', $this->storageDriver->getFilesInFolder('bazingaMove/'));
    }

    public function testGetFoldersInFolder()
    {
        $this->assertArrayHasKey('bazingaMove/folder/', $this->storageDriver->getFoldersInFolder('bazingaMove/'));
    }

    public function testDeleteFolder()
    {
        $this->assertTrue($this>$this->storageDriver->deleteFolder('bazingaMove/'));
    }

    public function testHash()
    {
        $this->assertEquals(sha1('foo'), $this->storageDriver->hash('foo'));
    }
}
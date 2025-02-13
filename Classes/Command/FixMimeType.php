<?php

namespace B3N\AzureStorage\TYPO3\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class FixMimeType extends Command
{
    protected $extensionMimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    /**
     * Configure the command by defining the name, options and arguments
     */
    protected function configure()
    {
        $this->setDescription('Fixes mime type in azure');
        $this->addArgument(
            'storageId',
            InputArgument::REQUIRED
        );
        $this->setHelp('Sets mime type in azure based on file extensions');
    }

    /**
     * Executes the command for showing sys_log entries
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int error code 0 when no errors
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title($this->getDescription());
        $storageId = (int)$input->getArgument('storageId');

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class)
        $storage = $resourceFactory->getStorageObject($storageId);

        if (!$storage || $storage->getDriverType() !== 'B3N\AzureStorage\TYPO3\Driver\StorageDriver') {
            $io->writeln('Invalid storage. This Command only works with azure storages.');
            return 0;
        }
        $storageRecord = $storage->getStorageRecord();
        $configuration = $resourceFactory->convertFlexFormDataToConfigurationArray($storageRecord['configuration']);
        $driver = $resourceFactory->getDriverObject($storage->getDriverType(), $configuration);
        $driver->processConfiguration();
        $driver->initialize();

        $availableFiles = $storage->getFileIdentifiersInFolder($storage->getRootLevelFolder(false)->getIdentifier(), true, true);

        $count = 0;
        foreach ($availableFiles as $fileIdentifier) {
            $fileExtension = pathinfo($fileIdentifier, PATHINFO_EXTENSION);
            if (!in_array($fileExtension, ['jpg', 'jpeg', 'png'])) {
                continue;
            }
            $expectedFileExtension = $this->extensionMimeMap[$fileExtension];
            //maybe instead of storage we should check in db first?
            $fileProperties = $storage->getFileInfoByIdentifier($fileIdentifier);

            if ($fileProperties['mimetype'] !== $expectedFileExtension) {
                $driver->setFileProperties($fileIdentifier, ['content-type' => $expectedFileExtension]);
                $io->writeln('Changing content-type of: ' . $fileIdentifier . ' from: ' . $fileProperties['mimetype'] . ' to: '. $expectedFileExtension);
                $count++;
            }
        }
        $io->writeln('Changed '. $count . ' files');
        return 0;
    }
}

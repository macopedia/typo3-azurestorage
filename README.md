# TYPO3 extension "azurestorage"
Adds Microsoft Azure Blob Storage to TYPO3. 

I would like to say thank you  to [Susanne Moog](https://github.com/psychomieze) for her previous work on her own extension to implement the Azure Blob Storage. Her work was a good point to start.

## Installation
This extension is not listed in the "old" TYPO3 extension repository. It's only available via composer - [https://composer.typo3.org](https://composer.typo3.org). If not already done, adding the TYPO3 repository to composer.json:

```php
{
    "repositories": [
        {
            "type": "composer",
            "url": "https://composer.typo3.org"
        }
    ]
}
```

Command line: 

```php
composer require b3n/azurestorage
```

## Open issues
- Implement countFilesInFolder() method
- Implement countFoldersInFolder() method
- Implement exception handling
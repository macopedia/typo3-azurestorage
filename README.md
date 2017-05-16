# TYPO3 extension "azurestorage"
Adds Microsoft Azure Blob Storage support to TYPO3 file abstraction layer (FAL).

## Installation
#####Composer 
```php
composer require b3n/azurestorage
```
## Configuration
After you've installed the extension, you need to enable it either via console 
or in the backend. After that, add a new file storage and enter your Azure Blob 
Storage credentials.

#####Add a new File Storage
![screenshot-installer](https://raw.githubusercontent.com/benjaminhirsch/benjaminhirsch.github.io/master/repository-assets/azure-storage-add-new-file-storage.jpg)

In the next step, enter all required credentials which you can find in the Azure 
Portal under Storage accounts / Settings / Access keys. Blob container name is the 
name you gave your container, you can also find it under Overview.

:warning: Make sure that the access policy of you container is set to **Blob** or **Container**!

![screenshot-installer](https://raw.githubusercontent.com/benjaminhirsch/benjaminhirsch.github.io/master/repository-assets/azure-storage-credentials.jpg)


I would like to say thank you  to [Susanne Moog](https://github.com/psychomieze) 
for her previous work on her own extension to implement the Azure Blob Storage. 
Her work was a good point to start.
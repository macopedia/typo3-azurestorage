<?php

declare(strict_types=1);

namespace B3N\AzureStorage\TYPO3\EventListener;

use TYPO3\CMS\Core\Resource\Event\AfterFileAddedToIndexEvent;

class AfterFileAddedToIndex extends AbstractFileIndexEventListener
{
    public function __invoke(AfterFileAddedToIndexEvent $event): void
    {
        $this->recordUpdatedOrCreated($event->getRecord());
    }
}

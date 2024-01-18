<?php

declare(strict_types=1);

namespace B3N\AzureStorage\TYPO3\EventListener;

use TYPO3\CMS\Core\Resource\Event\AfterFileUpdatedInIndexEvent;

class AfterFileUpdatedInIndex extends AbstractFileIndexEventListener
{
    public function __invoke(AfterFileUpdatedInIndexEvent $event): void
    {
        $this->recordUpdatedOrCreated($event->getRelevantProperties());
    }
}

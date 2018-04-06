<?php
namespace Jaeger\Symfony\Bridge;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class BackgroundFinishListener implements EventSubscriberInterface
{
    private $handler;

    public function __construct(BackgroundSpanHandler $handler)
    {
        $this->handler = $handler;
    }

    public static function getSubscribedEvents()
    {
        return [KernelEvents::TERMINATE => ['onTerminate', -4096],];
    }

    public function onTerminate()
    {
        $this->handler->finish();

        return $this;
    }
}
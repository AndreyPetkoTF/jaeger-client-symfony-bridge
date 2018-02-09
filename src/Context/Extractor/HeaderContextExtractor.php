<?php
declare(strict_types=1);

namespace Jaeger\Symfony\Context\Extractor;

use Jaeger\Codec\CodecInterface;
use Jaeger\Codec\CodecRegistry;
use Jaeger\Span\Context\SpanContext;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class HeaderContextExtractor implements ContextExtractorInterface
{
    /**
     * @var CodecInterface[]
     */
    private $registry;

    private $format;

    private $headerName;

    private $context;

    public function __construct(CodecRegistry $registry, string $format, string $headerName)
    {
        $this->registry = $registry;
        $this->format = $format;
        $this->headerName = $headerName;
    }

    public function extract(): ?SpanContext
    {
        return $this->context;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 2048],
        ];
    }

    public function onRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            $this->context = null;

            return $this;
        }

        if (HttpKernelInterface::MASTER_REQUEST === $event->getRequestType()
            && $request->headers->has($this->headerName)
            && ($context = $this->registry[$this->format]->decode($request->headers->get($this->headerName)))) {
            $this->context = $context;

            return $this;
        }

        return $this;
    }
}
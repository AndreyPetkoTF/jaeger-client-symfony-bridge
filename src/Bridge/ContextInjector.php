<?php
declare(strict_types=1);

namespace Jaeger\Symfony\Bridge;

use Jaeger\Codec\CodecInterface;
use Jaeger\Codec\CodecRegistry;
use Jaeger\Http\HttpCodeTag;
use Jaeger\Http\HttpMethodTag;
use Jaeger\Http\HttpUriTag;
use Jaeger\Tag\DoubleTag;
use Jaeger\Tag\LongTag;
use Jaeger\Tag\StringTag;
use Jaeger\Tracer\InjectableInterface;
use Jaeger\Tracer\TracerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

class ContextInjector implements EventSubscriberInterface
{
    private $spans;

    private $injectable;

    private $tracer;

    /**
     * @var CodecInterface[]
     */
    private $registry;

    private $requestStack;

    private $router;

    private $format;

    private $envName;

    private $headerName;

    public function __construct(
        \SplStack $stack,
        InjectableInterface $injectable,
        TracerInterface $tracer,
        CodecRegistry $registry,
        RequestStack $requestStack,
        RouterInterface $router,
        string $format,
        string $envName,
        string $headerName
    ) {
        $this->spans = $stack;
        $this->injectable = $injectable;
        $this->tracer = $tracer;
        $this->registry = $registry;
        $this->requestStack = $requestStack;
        $this->router = $router;
        $this->format = $format;
        $this->envName = $envName;
        $this->headerName = $headerName;
    }

    public static function getSubscribedEvents()
    {
        return [
            ConsoleEvents::COMMAND => [['onCommand']],
            KernelEvents::REQUEST => [['onRequest']],
            KernelEvents::RESPONSE => [['onResponse']],
        ];
    }

    public function getOperationName(Request $request)
    {
        if (null !== ($fragment = $request->attributes->get('is_fragment'))) {
            return ($controller = $request->attributes->get('_controller', null))
                ? sprintf('fragment.%s', $controller)
                : 'fragment';
        }

        if (null === ($routeName = $request->attributes->get('_route', null))) {
            return $request->getRequestUri();
        }

        if (null === ($route = $this->router->getRouteCollection()->get($routeName))) {
            return $request->getRequestUri();
        }

        return $route->getPath();
    }

    public function onResponse(FilterResponseEvent $event)
    {
        $this->tracer->finish(
            $this->spans->pop()->addTag(new HttpCodeTag($event->getResponse()->getStatusCode()))
        );

        return $this;
    }

    public function onCommand()
    {
        if (null === ($data = $_ENV[$this->envName] ?? null)) {
            return $this;
        }
        if (null === ($context = $this->registry[$this->format]->decode($data))) {
            return $this;
        }
        $this->injectable->assign($context);

        return $this;
    }

    public function onRequest(GetResponseEvent $event)
    {
        $request = $event->getRequest();
        if (HttpKernelInterface::MASTER_REQUEST === $event->getRequestType()
            && $request->headers->has($this->headerName)
            && ($context = $this->registry[$this->format]->decode($request->headers->get($this->headerName)))) {
            $this->injectable->assign($context);
        }

        $requestSpan = $this->tracer->start(
            $this->getOperationName($request),
            [
                new HttpMethodTag($request->getMethod()),
                new HttpUriTag($request->getRequestUri()),
            ]
        );

        if (HttpKernelInterface::MASTER_REQUEST === $event->getRequestType()) {
            $source = $request->server->has('REQUEST_TIME_FLOAT') ? 'header' : 'microtime';
            $value = $request->server->get('REQUEST_TIME_FLOAT', microtime(true));
            $startTime = (int)($value * 1000000);
            $requestSpan
                ->addTag(new StringTag('time.source', $source))
                ->addTag(new DoubleTag('time.value', $value))
                ->addTag(new LongTag('time.micro', $startTime))
                ->start($startTime);
            $this->tracer->finish($this->tracer->start('app.start')->start($startTime));
        }
        $this->spans->push($requestSpan);

        return $this;
    }
}

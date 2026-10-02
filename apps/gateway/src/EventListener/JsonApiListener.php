<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The gateway is a JSON API: errors raised before a controller runs (unknown route, wrong method)
 * must render as problem JSON, not as the HTML error page. Service routes under /_/ keep their own formats.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final readonly class JsonApiListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/_/')) {
            $request->setRequestFormat('json');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Order\Create\Action;
use App\Application\Order\Create\Input;
use App\Application\Order\Create\Output;
use Msstc4Symfony\LogicBundle\Application\Action\ActionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final readonly class CreateOrderController
{
    /**
     * @param ActionInterface<Output> $create the logic pipeline around Action, not Action itself
     */
    public function __construct(
        #[Autowire(service: Action::class)]
        private ActionInterface $create,
    ) {
    }

    #[Route('/orders', name: 'orders_create', methods: ['POST'], format: 'json')]
    public function __invoke(#[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 422)] Input $input): JsonResponse
    {
        return new JsonResponse($this->create->__invoke($input)->order, JsonResponse::HTTP_CREATED);
    }
}

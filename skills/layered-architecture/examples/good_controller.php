<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Request\CreateOrderRequest;
use App\Dto\Response\OrderResponse;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/orders')]
final class OrderController extends AbstractController
{
    public function __construct(private readonly OrderService $orderService)
    {
    }

    // Thin: deserialize -> one service call -> map to DTO -> respond.
    #[Route('', name: 'order_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreateOrderRequest $request): JsonResponse
    {
        $order = $this->orderService->createOrder($request);

        return $this->json(
            OrderResponse::fromEntity($order),
            Response::HTTP_CREATED,
            ['Location' => $this->generateUrl('order_show', ['id' => $order->getId()])],
        );
    }

    #[Route('/{id}', name: 'order_show', methods: ['GET'])]
    public function show(Uuid $id): JsonResponse
    {
        $order = $this->orderService->getOrder($id);

        return $this->json(OrderResponse::fromEntity($order));
    }
}

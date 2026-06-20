<?php

declare(strict_types=1);

namespace App\Controller;

// ❌ Everything wrong with a "fat" controller, for contrast.

use App\Entity\Order;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class BadOrderController extends AbstractController
{
    // ❌ Repository + EntityManager injected straight into the controller.
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/orders', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        // ❌ Manual JSON decoding instead of #[MapRequestPayload] + DTO.
        $data = json_decode($request->getContent(), true);

        // ❌ Business rule living in the controller.
        if (empty($data['items'])) {
            throw new \RuntimeException('Order must have items');
        }

        // ❌ Entity construction + persistence + flush in the controller.
        $order = new Order($data['customerEmail']);
        $this->em->persist($order);
        $this->em->flush();

        // ❌ Doctrine entity serialized directly to the client.
        return $this->json($order);
    }
}

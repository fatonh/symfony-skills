<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\Request\Create{{Aggregate}}Request;
use App\Entity\{{Aggregate}};
use App\Repository\{{Aggregate}}Repository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Application service for the {{Aggregate}} aggregate.
 * - Owns the transaction boundary.
 * - Constructor injection only.
 * - Returns entities / DTOs, never HTTP types.
 */
final class {{Aggregate}}Service
{
    public function __construct(
        private readonly {{Aggregate}}Repository $repository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function create(Create{{Aggregate}}Request $request): {{Aggregate}}
    {
        $entity = {{Aggregate}}::create(/* map fields from $request */);

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    public function get(Uuid $id): {{Aggregate}}
    {
        return $this->repository->find($id)
            ?? throw new {{Aggregate}}NotFoundException($id);
    }
}

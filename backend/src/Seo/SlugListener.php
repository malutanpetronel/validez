<?php

declare(strict_types=1);

namespace App\Seo;

use App\Entity\CivicSubject;
use App\Entity\TreeNode;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Orice nod sau subiect nou primește slug la persist, indiferent de unde e creat (procesoare API,
 * aprobarea propunerilor de categorii, teste, fixturi). Schimbările ulterioare (redenumire, mutare,
 * titlu nou) sunt explicite, în procesoare/TreeMover.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsDoctrineListener(event: Events::onClear)]
final class SlugListener
{
    public function __construct(private readonly SlugRegistry $slugs)
    {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof TreeNode) {
            $this->slugs->assignNew($entity);
        } elseif ($entity instanceof CivicSubject) {
            $this->slugs->assignNewSubject($entity);
        }
    }

    public function postFlush(): void
    {
        $this->slugs->clearPending();
    }

    public function onClear(): void
    {
        $this->slugs->clearPending();
    }
}


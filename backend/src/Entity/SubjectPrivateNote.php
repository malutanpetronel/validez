<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** No ApiResource, serializer groups or reverse relation on CivicSubject. */
#[ORM\Entity]
#[ORM\Table(name: 'subject_private_note')]
#[ORM\Index(name: 'idx_private_note_author', columns: ['author_id'])]
class SubjectPrivateNote
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: CivicSubject::class)]
    #[ORM\JoinColumn(name: 'subject_id', onDelete: 'CASCADE')]
    private CivicSubject $subject;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'author_id', nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    public function __construct(CivicSubject $subject, string $text)
    {
        $this->subject = $subject;
        $this->author = $subject->getAuthor();
        $this->text = $text;
    }

    public function getText(): string { return $this->text; }
    public function setText(string $text): void { $this->text = $text; }
}

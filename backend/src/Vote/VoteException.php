<?php

declare(strict_types=1);

namespace App\Vote;

/** Refuz de domeniu la vot; codul se mapează pe HTTP în 4.b4. */
final class VoteException extends \DomainException
{
    public const EMAIL_NOT_VERIFIED = 'email_not_verified';
    public const CONSENT_REQUIRED = 'consent_required';
    public const SUBJECT_NOT_VOTABLE = 'subject_not_votable';
    public const SUBJECT_FROZEN = 'subject_frozen';
    public const TYPE_NOT_ACTIVE = 'type_not_active';
    public const VALUE_OUT_OF_RANGE = 'value_out_of_range';
    public const NOTICE_VERSION_MISMATCH = 'notice_version_mismatch';
    public const VOTING_DISABLED = 'voting_disabled';

    private const MESSAGES = [
        self::EMAIL_NOT_VERIFIED => 'Confirmă adresa de email înainte de a vota.',
        self::CONSENT_REQUIRED => 'Votul necesită consimțământul tău explicit.',
        self::SUBJECT_NOT_VOTABLE => 'Subiectul nu este publicat; votarea nu este disponibilă.',
        self::SUBJECT_FROZEN => 'Subiectul este rezolvat sau închis; votul se poate doar retrage.',
        self::TYPE_NOT_ACTIVE => 'Acest tip de vot nu este activ pe subiect.',
        self::VALUE_OUT_OF_RANGE => 'Valoarea votului nu este permisă pentru acest tip.',
        self::NOTICE_VERSION_MISMATCH => 'Informarea s-a actualizat; citește versiunea curentă înainte de a-ți da acordul.',
        self::VOTING_DISABLED => 'Votarea nu este încă activă. Poți retrage oricând voturile existente.',
    ];

    private function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public function httpStatus(): int
    {
        return match ($this->reason) {
            self::EMAIL_NOT_VERIFIED, self::CONSENT_REQUIRED => 403,
            self::SUBJECT_NOT_VOTABLE, self::SUBJECT_FROZEN, self::TYPE_NOT_ACTIVE, self::VOTING_DISABLED => 409,
            self::VALUE_OUT_OF_RANGE, self::NOTICE_VERSION_MISMATCH => 422,
        };
    }
}

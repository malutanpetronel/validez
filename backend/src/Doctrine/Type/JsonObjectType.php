<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * JSONB stocat mereu ca obiect, inclusiv gol ({}) sau cu chei numerice ("0", "1").
 *
 * Tipul `json` standard serializează [0 => 3, 1 => 5] ca listă [3, 5]; aici cheile sunt păstrate.
 * În PHP valoarea e array<int|string, int>; json_decode întoarce cheile numerice ca int.
 */
final class JsonObjectType extends Type
{
    public const NAME = 'json_object';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getJsonTypeDeclarationSQL(['jsonb' => true] + $column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('json_object așteaptă un array asociativ.');
        }

        return json_encode((object) $value, \JSON_THROW_ON_ERROR);
    }

    /** @return array<int|string, mixed>|null */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        if ($value === null) {
            return null;
        }
        $decoded = json_decode((string) $value, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('json_object: valoarea din baza de date nu e obiect.');
        }

        return $decoded;
    }
}

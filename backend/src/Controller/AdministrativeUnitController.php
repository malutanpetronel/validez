<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * G.3: căutarea unităților pentru „Aria subiectului” (public; nomenclatorul SIRUTA e public). Fără diacritice
 * (ș/ş, ț/ţ, ă, â, î) și fără majuscule; unitățile retrase nu apar. Cuvântul întreg/început de cuvânt e primul.
 */
final class AdministrativeUnitController extends AbstractController
{
    private const LIMIT = 20;
    /** Toate cele 42 de județe (inclusiv București) încap în listă, ca selectorul să le arate fără căutare. */
    private const COUNTY_LIMIT = 50;
    private const FOLD = "translate(lower(u.display_name), 'ăâîșşțţ', 'aaissttt')";

    #[Route('/api/administrative_units', methods: ['GET'])]
    public function search(Request $request, Connection $db): JsonResponse
    {
        $level = (string) $request->query->get('level', 'UAT');
        if (!\in_array($level, ['UAT', 'COUNTY'], true)) {
            throw new BadRequestHttpException('level trebuie să fie UAT sau COUNTY.');
        }
        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) > 80) {
            throw new BadRequestHttpException('Căutare prea lungă.');
        }
        $needle = addcslashes(strtr(mb_strtolower($q), ['ă' => 'a', 'â' => 'a', 'î' => 'i', 'ș' => 's', 'ş' => 's', 'ț' => 't', 'ţ' => 't']), '%_\\');
        $rows = $db->fetchAllAssociative(
            'SELECT u.code, u.display_name AS name, u.level, p.display_name AS county
               FROM administrative_unit u LEFT JOIN administrative_unit p ON p.id = u.parent_id
              WHERE u.level = :level AND u.retired_at IS NULL AND '.self::FOLD.' LIKE :like
              ORDER BY ('.self::FOLD.' LIKE :start OR '.self::FOLD.' LIKE :word) DESC, '.($needle === '' ? '' : 'length(u.display_name), ').self::FOLD.'
              LIMIT '.($level === 'COUNTY' ? self::COUNTY_LIMIT : self::LIMIT),
            ['level' => $level, 'like' => "%$needle%", 'start' => "$needle%", 'word' => "% $needle%"],
        );
        $items = array_map(static fn (array $r): array => ['code' => $r['code'], 'name' => $r['name'], 'level' => $r['level'],
            'county' => $r['level'] === 'UAT' ? $r['county'] : null], $rows);

        $response = $this->json(['items' => $items]);
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}

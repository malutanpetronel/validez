<?php

declare(strict_types=1);

namespace App\Subject;

use App\Entity\CivicSubject;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class CostEstimate
{
    public const CURRENCIES = ['RON', 'EUR', 'USD', 'GBP'];

    /** Validate the final merged state, not just the submitted fields. */
    public static function apply(CivicSubject $subject, array $input, SubjectType $finalType): void
    {
        $changes = array_intersect_key($input, array_flip(['costEstimate', 'costCurrency', 'costEstimateScope']));
        if ($changes === []) return;
        if ($finalType !== SubjectType::PROPOSAL) {
            throw new UnprocessableEntityHttpException('Estimarea este disponibilă doar pentru propuneri.');
        }
        $values = array_replace($subject->storedEstimate(), $changes);
        [$amount, $currency, $scope] = array_values($values);
        if ($amount === null && $currency === null && $scope === null) {
            $subject->setEstimate(null, null, null);
            return;
        }
        if ($amount === null || $currency === null || $scope === null || trim($scope) === '') {
            throw new UnprocessableEntityHttpException('Completează suma, moneda și ce acoperă estimarea, sau golește toate cele trei câmpuri.');
        }
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount)) {
            throw new UnprocessableEntityHttpException('Suma trebuie să fie pozitivă sau zero, cu maximum 10 cifre întregi și două zecimale.');
        }
        if (!in_array($currency, self::CURRENCIES, true)) {
            throw new UnprocessableEntityHttpException('Moneda trebuie să fie RON, EUR, USD sau GBP.');
        }
        $scope = trim($scope);
        $firstLine = preg_split('/\R/u', $scope)[0];
        if (mb_strlen($scope) > 2000 || mb_strlen($firstLine) > 200) {
            throw new UnprocessableEntityHttpException('Explicația poate avea maximum 2000 de caractere; primul rând, maximum 200.');
        }
        [$integer, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $amount = (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
        $subject->setEstimate($amount, $currency, $scope);
    }
}

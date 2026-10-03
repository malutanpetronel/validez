<?php

declare(strict_types=1);

namespace App\Tree;

/** Mutare respinsa de regulile arborelui (ciclu, parinte inexistent, pozitie invalida) -> 422. */
final class TreeMoveException extends \DomainException
{
}

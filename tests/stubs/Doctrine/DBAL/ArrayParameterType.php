<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

/**
 * Doctrine's list parameter types, as far as `IQueryBuilder`'s constants need them.
 */
final class ArrayParameterType
{
    public const INTEGER = 101;
    public const STRING = 102;
}

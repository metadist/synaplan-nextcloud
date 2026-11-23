<?php

declare(strict_types=1);

namespace Doctrine\DBAL;

/**
 * Doctrine's parameter types, as far as `IQueryBuilder`'s constants need them.
 */
final class ParameterType
{
    public const NULL = 0;
    public const INTEGER = 1;
    public const STRING = 2;
    public const LARGE_OBJECT = 3;
    public const BOOLEAN = 5;
}

<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Query\Expression;

/**
 * Doctrine's comparison operators, as far as `IExpressionBuilder`'s constants need them.
 */
class ExpressionBuilder
{
    public const EQ = '=';
    public const NEQ = '<>';
    public const LT = '<';
    public const LTE = '<=';
    public const GT = '>';
    public const GTE = '>=';
}

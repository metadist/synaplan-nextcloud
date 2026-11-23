<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Types;

/**
 * Doctrine's column types, as far as `IQueryBuilder`'s constants need them.
 */
final class Types
{
    public const BOOLEAN = 'boolean';
    public const DATE_MUTABLE = 'date';
    public const DATE_IMMUTABLE = 'date_immutable';
    public const DATETIME_MUTABLE = 'datetime';
    public const DATETIME_IMMUTABLE = 'datetime_immutable';
    public const DATETIMETZ_MUTABLE = 'datetimetz';
    public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
    public const TIME_MUTABLE = 'time';
}

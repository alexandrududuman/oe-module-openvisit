<?php

/**
 * Constants only, mirrored from OpenEMR core for tests that run without a core checkout.
 */

declare(strict_types=1);

namespace OpenEMR\Services\Globals;

class GlobalSetting
{
    public const DATA_TYPE_BOOL = 'bool';
    public const DATA_TYPE_DEFAULT_VISIT_CATEGORY = 'default_visit_category';
    public const DATA_TYPE_ENCRYPTED = 'encrypted';
    public const DATA_TYPE_NUMBER = 'num';
    public const DATA_TYPE_TEXT = 'text';
}

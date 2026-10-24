<?php

namespace App\Workbooks;

use RuntimeException;

/** A workbook that cannot be read, with a message people can act on. */
final class WorkbookException extends RuntimeException
{
    public static function encrypted(): self
    {
        return new self(__('import.errors.encrypted'));
    }

    public static function notXlsx(): self
    {
        return new self(__('import.errors.not_xlsx'));
    }

    public static function tooLarge(): self
    {
        return new self(__('import.errors.too_large'));
    }

    public static function unknown(): self
    {
        return new self(__('import.errors.unknown_workbook'));
    }
}

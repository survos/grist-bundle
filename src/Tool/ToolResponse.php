<?php

declare(strict_types=1);

namespace Survos\GristBundle\Tool;

/**
 * Agent tools return a string, so every tool here emits the same pretty JSON.
 * Kept separate so the shape is changed in one place if the transport changes.
 */
final class ToolResponse
{
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

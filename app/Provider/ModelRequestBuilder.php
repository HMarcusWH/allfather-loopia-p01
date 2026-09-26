<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;
use Allfather\Loopia\Core\Outcome;

final class ModelRequestBuilder
{
    public static function prepare(array $resolvedPrompt, array $context, array $options = []): Outcome
    {
        return PreparedModelRequest::prepare($resolvedPrompt, $context, $options);
    }
}

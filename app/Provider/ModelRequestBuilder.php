<?php
declare(strict_types=1);
namespace Allfather\Loopia\Provider;
use Allfather\Loopia\Core\Outcome;
use Allfather\Loopia\PromptOps\PromptSelectionResolver;

final class ModelRequestBuilder
{
    public static function prepare(array $resolvedPrompt, array $context, array $options = [], ?PromptSelectionResolver $resolver = null): Outcome
    {
        return PreparedModelRequest::prepare($resolvedPrompt, $context, $options, $resolver);
    }
}

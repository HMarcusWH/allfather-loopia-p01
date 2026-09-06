<?php
/**
 * One governed vocabulary term, rendered with its meaning.
 *
 * The colour is semantic and the TEXT carries the same information, so a reader who cannot
 * distinguish the colours loses nothing.
 *
 * @var string $term
 * @var string $kind  authority | evidence | governance | state
 */
use Allfather\Loopia\Http\View;
$class = ($kind ?? 'state') === 'state' ? View::tone($term) : 'tag--' . ($kind ?? 'state');
?><span class="tag <?= View::e($class) ?>"><span class="tag__dot" aria-hidden="true"></span><?= View::e($term) ?></span>

<?php
// Template engines legitimately include runtime paths; it is still a capability.
// @expect: DYNAMIC_INCLUDE
function render(string $template, array $vars): string
{
    ob_start();
    include $template;

    return (string) ob_get_clean();
}

<?php

declare(strict_types=1);

use FinePrint\Agent;

/*
 * The agent guide names every public Agent method, or the method is on the
 * allowlist below with the reason it is left out.
 *
 * A method the guide never names is a method no model reading the guide will
 * use. 0.7.0 added word boxes to read() and the guide said nothing about
 * them; this fails the build on the next one instead.
 *
 * "Names" means written as code: `Agent::x`, `Agent.x` or a backticked `x`, so
 * the English word "read" in a sentence does not count as documenting read().
 * The guide-ships hash pin is FPW_GUIDE_SHA256 in Writer/AgentWriterTest.php.
 */

/** @return array<string, string> method => why the guide does not name it */
function finePrintGuideAllowlist(): array
{
    return [
        'guide' => 'the guide itself: a model reading it already has it',
        'version' => 'package metadata; nothing an agent builds or reads depends on it',
    ];
}

/** @return list<string> */
function finePrintGuidePublicMethods(): array
{
    $methods = (new ReflectionClass(Agent::class))->getMethods(ReflectionMethod::IS_PUBLIC);

    return array_values(array_map(fn (ReflectionMethod $m): string => $m->getName(), $methods));
}

function finePrintGuideNames(string $guide, string $method): bool
{
    return preg_match('/(Agent::|Agent\.|`)'.preg_quote($method, '/').'\b/', $guide) === 1;
}

it('names every public Agent method, or allowlists it with a reason', function () {
    $guide = Agent::guide();
    $allow = finePrintGuideAllowlist();

    $missing = array_values(array_filter(
        finePrintGuidePublicMethods(),
        fn (string $m): bool => ! isset($allow[$m]) && ! finePrintGuideNames($guide, $m),
    ));

    expect($missing)->toBe([], 'agent-guide/building-pdfs.md never names: '.implode(', ', $missing));
});

it('allowlists only methods that exist, each with a reason, none the guide already names', function () {
    $guide = Agent::guide();
    $methods = finePrintGuidePublicMethods();

    foreach (finePrintGuideAllowlist() as $method => $reason) {
        expect($methods)->toContain($method)
            ->and(trim($reason))->not->toBe('')
            ->and(finePrintGuideNames($guide, $method))->toBeFalse("{$method} is named in the guide; drop it from the allowlist");
    }
});

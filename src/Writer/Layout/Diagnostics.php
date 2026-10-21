<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * What layout had to compromise on, for {@see \FinePrint\Agent::lint()}.
 * A finding names the model path it came from and never quotes the text.
 */
final class Diagnostics
{
    /** @var array<string, array{rule: string, severity: string, path: string, message: string, page?: int}> */
    private array $findings = [];

    public function add(string $rule, string $severity, string $path, string $message, ?int $page = null): void
    {
        $f = ['rule' => $rule, 'severity' => $severity, 'path' => $path, 'message' => $message];
        if ($page !== null) {
            $f['page'] = $page;
        }
        // One finding per rule and path: a long paragraph of unencodable
        // characters is one problem, not a hundred.
        $this->findings[$rule.'|'.$path] ??= $f;
    }

    /** @return list<array{rule: string, severity: string, path: string, message: string, page?: int}> */
    public function all(): array
    {
        return array_values($this->findings);
    }
}

<?php

declare(strict_types=1);

namespace FinePrint\Security;

use InvalidArgumentException;

/**
 * The layer between a PDF and an agent's read.
 *
 * Every string {@see \FinePrint\Agent::read()} returns passes through here:
 * page text, metadata, XMP, outline titles and annotation text. It looks for
 * five things:
 *
 * | rule              | what                                                        |
 * |-------------------|-------------------------------------------------------------|
 * | `instruction`     | text addressed to an AI ({@see InstructionPatterns})        |
 * | `hidden-text`     | text a human would not see: invisible, white, sub-point     |
 * | `off-page`        | text drawn outside the visible page                         |
 * | `invisible-chars` | zero-width, bidi-control and Unicode tag characters         |
 *
 * and applies one policy:
 *
 * - `flag`    — content unchanged; findings attached. For trusted files.
 * - `redact`  — findings at or above the threshold are cut out of the content:
 *               instructions become a marker, hidden text a marker, invisible
 *               characters are removed. The default.
 * - `discard` — any finding at or above the threshold and the agent gets NO
 *               content at all, only the findings.
 *
 * Findings carry a location and never, by default, the offending text — an
 * excerpt of an injection is still an injection. Hosts that want excerpts for
 * a human audit log ask for them with `excerpts: true`, and must not then pass
 * that report to an agent.
 */
final class AgentReadGuard
{
    public const POLICIES = ['flag', 'redact', 'discard'];

    public const SEVERITIES = ['low' => 1, 'medium' => 2, 'high' => 3];

    public const INSTRUCTION_MARKER = '[fine-print: redacted instruction]';

    public const HIDDEN_MARKER = '[fine-print: removed hidden text]';

    /**
     * Invisible characters and their severity. Joiners and directional marks
     * are low: they are legitimate in emoji sequences and in Arabic, Hebrew,
     * Persian and Indic text, so they are reported without being acted on at
     * the default threshold.
     */
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{180E}\x{061C}\x{E0000}-\x{E007F}]+/u';

    private readonly int $threshold;

    public function __construct(
        private readonly string $policy = 'redact',
        string $threshold = 'medium',
        private readonly bool $excerpts = false,
    ) {
        if (! in_array($policy, self::POLICIES, true)) {
            throw new InvalidArgumentException('Unknown policy "'.$policy.'"; expected one of: '.implode(', ', self::POLICIES).'.');
        }
        if (! isset(self::SEVERITIES[$threshold])) {
            throw new InvalidArgumentException('Unknown threshold "'.$threshold.'"; expected low, medium or high.');
        }
        $this->threshold = self::SEVERITIES[$threshold];
    }

    /**
     * @param  list<array{location: array<string, mixed>, text: string, spans?: list<array{start: int, length: int, flags: list<string>}>}>  $segments
     * `acted` is the findings the policy acted on: what `redact` cut, or what
     * made `discard` withhold everything. Empty under `flag`.
     *
     * @return array{texts: list<?string>, findings: list<array<string, mixed>>, discarded: bool, acted: list<array<string, mixed>>}
     */
    public function apply(array $segments): array
    {
        $findings = [];
        $ranges = [];
        foreach ($segments as $i => $seg) {
            [$f, $r] = $this->scan($seg);
            array_push($findings, ...$f);
            $ranges[$i] = $r;
        }

        $actionable = array_filter($findings, fn (array $f) => self::SEVERITIES[$f['severity']] >= $this->threshold);
        $discarded = $this->policy === 'discard' && $actionable !== [];

        $texts = [];
        foreach ($segments as $i => $seg) {
            $texts[] = match (true) {
                $discarded => null,
                $this->policy === 'redact' => self::redact($seg['text'], $ranges[$i]),
                default => $seg['text'],
            };
        }

        return [
            'texts' => $texts,
            'findings' => $findings,
            'discarded' => $discarded,
            'acted' => $this->policy === 'flag' ? [] : array_values($actionable),
        ];
    }

    /**
     * @param  array{location: array<string, mixed>, text: string, spans?: list<array{start: int, length: int, flags: list<string>}>}  $seg
     * @return array{0: list<array<string, mixed>>, 1: list<array{0: int, 1: int, 2: int}>}
     */
    private function scan(array $seg): array
    {
        $text = $seg['text'];
        $loc = $seg['location'];
        $nonPage = ($loc['source'] ?? '') !== 'page';
        $findings = [];
        $ranges = []; // [start, end, priority] with priority 3 instruction, 2 hidden, 1 invisible

        // Hidden and off-page runs.
        $hidden = [];
        foreach (self::mergeSpans($seg['spans'] ?? [], $text) as $span) {
            $chunk = substr($text, $span['start'], $span['length']);
            if (trim($chunk) === '') {
                continue;
            }
            $rule = in_array('off-page', $span['flags'], true) ? 'off-page' : 'hidden-text';
            $f = [
                'rule' => $rule,
                'severity' => 'medium',
                'reasons' => $span['flags'],
                'location' => $loc + $this->at($text, $span['start'], $span['length']),
            ];
            $findings[] = $this->withExcerpt($f, $chunk);
            $hidden[] = [$span['start'], $span['start'] + $span['length']];
            $this->range($ranges, $f['severity'], $span['start'], $span['start'] + $span['length'], 2);
        }

        // Invisible characters, and what any tag characters spell.
        if (preg_match_all(self::INVISIBLE, $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$cluster, $start]) {
                if (self::isXmpPacketMark($text, $cluster, $start)) {
                    continue;
                }
                $chars = [];
                $severity = 'low';
                $smuggled = '';
                foreach (mb_str_split($cluster, 1, 'UTF-8') as $ch) {
                    $cp = mb_ord($ch, 'UTF-8');
                    $chars[sprintf('U+%04X', $cp)] = true;
                    $severity = self::maxSeverity($severity, self::invisibleSeverity($cp));
                    if ($cp >= 0xE0020 && $cp <= 0xE007E) {
                        $smuggled .= chr($cp - 0xE0000);
                    }
                }
                $where = $loc + $this->at($text, $start, strlen($cluster));
                $f = [
                    'rule' => 'invisible-chars',
                    'severity' => $severity,
                    'chars' => array_keys($chars),
                    'location' => $where,
                ];
                $findings[] = $this->withExcerpt($f, self::visible($cluster));
                $this->range($ranges, $severity, $start, $start + strlen($cluster), 1);

                if ($smuggled !== '') {
                    foreach (self::instructionMatches($smuggled) as [$id, , , $mStart, $mLen]) {
                        $findings[] = $this->withExcerpt([
                            'rule' => 'instruction',
                            'severity' => 'high',
                            'pattern' => $id,
                            'hidden' => true,
                            'smuggled' => true,
                            'location' => $where,
                        ], substr($smuggled, $mStart, $mLen));
                    }
                }
            }
        }

        // Instructions, over the normalised text, mapped back to the original.
        [$norm, $map] = self::normalise($text);
        foreach (self::instructionMatches($norm) as [$id, $severity, , $nStart, $nLen]) {
            $start = $map[$nStart];
            $end = $map[$nStart + $nLen] ?? strlen($text);
            // Trim the mapped end back over any invisible run it swallowed.
            $end = $start + strlen(rtrim(substr($text, $start, $end - $start)));
            $isHidden = false;
            foreach ($hidden as [$hs, $he]) {
                if ($start < $he && $end > $hs) {
                    $isHidden = true;
                }
            }
            if ($isHidden || $nonPage) {
                $severity = 'high';
            }
            $f = [
                'rule' => 'instruction',
                'severity' => $severity,
                'pattern' => $id,
                'hidden' => $isHidden,
                'smuggled' => false,
                'location' => $loc + $this->at($text, $start, $end - $start),
            ];
            $findings[] = $this->withExcerpt($f, substr($text, $start, $end - $start));
            $this->range($ranges, $severity, $start, $end, 3);
        }

        usort($findings, fn (array $a, array $b) => ($a['location']['offset'] ?? 0) <=> ($b['location']['offset'] ?? 0));

        return [$findings, $ranges];
    }

    /**
     * Every pattern match in a normalised string, as
     * [pattern id, severity, matched text, byte offset, byte length].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: int, 4: int}>
     */
    private static function instructionMatches(string $norm): array
    {
        $out = [];
        foreach (InstructionPatterns::PATTERNS as $id => [$severity, $regex]) {
            if (preg_match_all($regex, $norm, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $offset]) {
                    // Patterns may consume one leading space; report the words.
                    $lead = strlen($match) - strlen(ltrim($match));
                    $out[] = [$id, $severity, trim($match), $offset + $lead, strlen(trim($match))];
                }
            }
        }

        return $out;
    }

    /**
     * Normalise for matching, keeping a byte map back to the original.
     *
     * @return array{0: string, 1: array<int, int>} normalised text, normalised byte offset => original byte offset
     */
    private static function normalise(string $text): array
    {
        $norm = '';
        $map = [];
        $pos = 0;
        $lastSpace = true;
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            $len = strlen($ch);
            if (preg_match(self::INVISIBLE, $ch)) {
                $pos += $len;

                continue;
            }
            if ($ch === "\u{A0}" || ctype_space($ch)) {
                if (! $lastSpace) {
                    $map[strlen($norm)] = $pos;
                    $norm .= ' ';
                    $lastSpace = true;
                }
                $pos += $len;

                continue;
            }
            $map[strlen($norm)] = $pos;
            $norm .= $len === 1 ? strtolower($ch) : $ch;
            $lastSpace = false;
            $pos += $len;
        }
        $map[strlen($norm)] = $pos;

        return [$norm, $map];
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $ranges
     * @param  int<1, 3>  $priority
     */
    private function range(array &$ranges, string $severity, int $start, int $end, int $priority): void
    {
        if (self::SEVERITIES[$severity] >= $this->threshold && $end > $start) {
            $ranges[] = [$start, $end, $priority];
        }
    }

    /** @param list<array{0: int, 1: int, 2: int}> $ranges */
    private static function redact(string $text, array $ranges): string
    {
        if ($ranges === []) {
            return $text;
        }
        usort($ranges, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($ranges as $r) {
            $last = count($merged) - 1;
            if ($last >= 0 && $r[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $r[1]);
                $merged[$last][2] = max($merged[$last][2], $r[2]);

                continue;
            }
            $merged[] = $r;
        }
        $out = '';
        $pos = 0;
        foreach ($merged as [$s, $e, $p]) {
            $out .= substr($text, $pos, $s - $pos);
            $out .= match ($p) {
                3 => self::INSTRUCTION_MARKER,
                2 => self::HIDDEN_MARKER,
                default => '',
            };
            $pos = $e;
        }

        return $out.substr($text, $pos);
    }

    /**
     * Join spans that are one run of hidden text split across operators.
     *
     * @param  list<array{start: int, length: int, flags: list<string>}>  $spans
     * @return list<array{start: int, length: int, flags: list<string>}>
     */
    private static function mergeSpans(array $spans, string $text): array
    {
        $out = [];
        foreach ($spans as $s) {
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last]['flags'] === $s['flags']) {
                $end = $out[$last]['start'] + $out[$last]['length'];
                if ($s['start'] >= $end && trim(substr($text, $end, $s['start'] - $end)) === '' && $s['start'] - $end <= 2) {
                    $out[$last]['length'] = $s['start'] + $s['length'] - $out[$last]['start'];

                    continue;
                }
            }
            $out[] = $s;
        }

        return $out;
    }

    /** @return array{offset: int, length: int} in characters */
    private function at(string $text, int $byteStart, int $byteLength): array
    {
        return [
            'offset' => mb_strlen(substr($text, 0, $byteStart), 'UTF-8'),
            'length' => mb_strlen(substr($text, $byteStart, $byteLength), 'UTF-8'),
        ];
    }

    /**
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function withExcerpt(array $finding, string $excerpt): array
    {
        if ($this->excerpts) {
            $finding['excerpt'] = mb_substr($excerpt, 0, 200, 'UTF-8');
        }

        return $finding;
    }

    /**
     * The XMP specification REQUIRES `<?xpacket begin="U+FEFF"`: the byte-order
     * mark is how a packet declares its encoding. Every mainstream producer
     * writes it, so reporting it would flag (and, under `discard`, throw away)
     * nearly every real PDF. That one position, and only that one, is exempt.
     */
    private static function isXmpPacketMark(string $text, string $cluster, int $start): bool
    {
        if ($cluster !== "\u{FEFF}" || $start < 7) {
            return false;
        }
        $before = substr($text, max(0, $start - 18), min(18, $start));

        return (bool) preg_match('/<\?xpacket\s+begin=["\']$/', $before);
    }

    private static function invisibleSeverity(int $cp): string
    {
        return match (true) {
            $cp >= 0xE0000 => 'high',
            in_array($cp, [0x200C, 0x200D, 0x200E, 0x200F, 0x061C], true) => 'low',
            default => 'medium',
        };
    }

    private static function maxSeverity(string $a, string $b): string
    {
        return self::SEVERITIES[$a] >= self::SEVERITIES[$b] ? $a : $b;
    }

    /** Render invisible characters as `<U+200B>` so a human can see them. */
    private static function visible(string $s): string
    {
        return implode('', array_map(
            fn (string $ch) => sprintf('<U+%04X>', mb_ord($ch, 'UTF-8')),
            mb_str_split($s, 1, 'UTF-8'),
        ));
    }
}

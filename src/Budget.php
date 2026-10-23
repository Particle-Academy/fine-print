<?php

declare(strict_types=1);

namespace FinePrint;

use FinePrint\Exceptions\RefusedException;

/**
 * The running account against {@see Limits} for one open document: time,
 * memory and decoded bytes.
 *
 * Checks are cooperative. Every loop that a hostile file could make long —
 * lexing, decoding, interpreting — calls {@see tick()}, which is cheap, and
 * every 512 ticks it reads the clock and the memory counter.
 */
final class Budget
{
    private readonly int $deadline;

    private readonly int $memoryStart;

    private int $decoded = 0;

    private int $ticks = 0;

    /** @param string $activity what is being budgeted, for refusal messages ("Reading", "Rendering") */
    public function __construct(public readonly Limits $limits, private readonly string $activity = 'Reading')
    {
        $this->deadline = hrtime(true) + (int) ($limits->timeSeconds * 1e9);
        $this->memoryStart = memory_get_usage();
    }

    public function tick(int $n = 1): void
    {
        $this->ticks += $n;
        if ($this->ticks >= 512) {
            $this->ticks = 0;
            $this->check();
        }
    }

    /** @throws RefusedException */
    public function check(): void
    {
        if (hrtime(true) > $this->deadline) {
            throw new RefusedException('time_budget', $this->activity.' took longer than the '.$this->limits->timeSeconds.'s time budget.');
        }
        if (memory_get_usage() - $this->memoryStart > $this->limits->memoryBytes) {
            throw new RefusedException('memory_budget', $this->activity.' needed more than the '.$this->limits->memoryBytes.'-byte memory budget.');
        }
        $hard = $this->hardMemoryLimit();
        if ($hard !== null && memory_get_usage(true) + 2 * 1024 * 1024 > $hard) {
            throw new RefusedException('memory_budget', $this->activity.' is too close to the PHP memory limit.');
        }
    }

    /** Judge a worst-case allocation BEFORE PHP attempts it (includes arena headroom). */
    public function allocation(float|int $bytes): void
    {
        $this->check();
        $growth = memory_get_usage() - $this->memoryStart;
        $hard = $this->hardMemoryLimit();
        if (! is_finite((float) $bytes) || $bytes < 0 || $bytes > $this->limits->memoryBytes - $growth
            || ($hard !== null && $bytes > $hard - memory_get_usage(true) - 2 * 1024 * 1024)) {
            throw new RefusedException('memory_budget', $this->activity.' would exceed the available memory during allocation.');
        }
    }

    public function remainingSeconds(): float
    {
        $this->check();

        return max(0.0, ($this->deadline - hrtime(true)) / 1e9);
    }

    public function remainingMemory(): int
    {
        $this->check();

        return max(0, $this->limits->memoryBytes - (memory_get_usage() - $this->memoryStart));
    }

    private function hardMemoryLimit(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ((float) $raw <= 0) {
            return null;
        }
        $factor = match (strtolower(substr($raw, -1))) {
            'k' => 1024, 'm' => 1024 * 1024, 'g' => 1024 * 1024 * 1024, default => 1,
        };

        $bytes = (float) $raw * $factor;

        return $bytes >= (float) PHP_INT_MAX ? PHP_INT_MAX : (int) $bytes;
    }

    /**
     * Account for one filter stage's output.
     *
     * @throws RefusedException
     */
    public function decoding(int $outBytes, int $inBytes): void
    {
        if ($outBytes > $this->limits->maxStreamBytes) {
            throw new RefusedException('too_large', 'A stream decodes past the '.$this->limits->maxStreamBytes.'-byte limit.');
        }
        if ($outBytes > $this->limits->ratioFloorBytes && $outBytes > $this->limits->maxRatio * max(1, $inBytes)) {
            throw new RefusedException('decompression_bomb', 'A stream expands more than '.$this->limits->maxRatio.' times: a decompression bomb.');
        }
        $this->check();
    }

    /** @throws RefusedException */
    public function decoded(int $bytes): void
    {
        $this->decoded += $bytes;
        if ($this->decoded > $this->limits->maxTotalDecodedBytes) {
            throw new RefusedException('too_large', 'Streams decode past the '.$this->limits->maxTotalDecodedBytes.'-byte total limit.');
        }
    }
}

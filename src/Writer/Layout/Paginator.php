<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * Places flowables on pages, top to bottom.
 *
 *   - space before a block is dropped at the top of a page
 *   - a block that does not fit is split if it can be (between lines, between
 *     table rows) or moved to the next page whole
 *   - a keep-with-next block moves to the next page when it and the first
 *     piece of the block after it would not fit together (a chain of them
 *     moves together), unless the chain could not fit on any page
 *   - a block that does not fit even on an empty page is force-split, or drawn
 *     overflowing and reported
 */
final class Paginator
{
    private const MAX_KEEP_CHAIN = 6;

    /** @var list<Canvas> */
    private array $pages = [];

    private float $y = 0.0;

    private bool $atTop = true;

    /** @var array<int, true> page numbers holding a keep-with-next block as their last block */
    private array $endsWithKeep = [];

    /**
     * @param  \Closure(): Canvas  $newCanvas
     */
    public function __construct(
        private readonly float $x,
        private readonly float $top,
        private readonly float $bottom,
        private readonly \Closure $newCanvas,
        private readonly Diagnostics $diagnostics,
    ) {}

    /**
     * @param  list<Flowable>  $flow
     * @return list<Canvas>
     */
    public function paginate(array $flow): array
    {
        $this->newPage();
        $queue = $flow;
        $guard = 0;
        while ($queue !== []) {
            if (++$guard > 1_000_000) {
                break;   // unreachable in practice; a bound on a loop the input can lengthen
            }
            $f = array_shift($queue);
            if ($f instanceof PageBreakFlow) {
                $this->newPage();

                continue;
            }
            $before = $this->atTop ? 0.0 : $f->spaceBefore;
            $avail = $this->bottom - $this->y - $before;

            if ($f->keepNext && ! $this->atTop) {
                $need = $this->keepHeight($f, $queue, 0);
                if ($need > $avail + 0.001 && $need <= $this->bottom - $this->top) {
                    $this->newPage();
                    array_unshift($queue, $f);

                    continue;
                }
            }

            if ($f->height() <= $avail + 0.001) {
                $this->place($f, $before);

                continue;
            }

            $parts = $f->split($avail);
            if ($parts === null && $this->atTop) {
                $parts = $f->forceSplit($avail);
            }
            if ($parts !== null) {
                $this->place($parts[0], $before);
                $this->newPage();
                array_unshift($queue, $parts[1]);

                continue;
            }
            if (! $this->atTop) {
                $this->newPage();
                array_unshift($queue, $f);

                continue;
            }
            $this->diagnostics->add('overflow', 'error', $f->path, 'a block is taller than a page and cannot be split; it runs past the bottom margin', count($this->pages));
            $this->place($f, 0.0);
        }

        return $this->pages;
    }

    /** @param list<Flowable> $queue */
    private function keepHeight(Flowable $f, array $queue, int $depth): float
    {
        $next = $queue[0] ?? null;
        if ($next === null || $next instanceof PageBreakFlow || $depth >= self::MAX_KEEP_CHAIN) {
            return $f->height();
        }
        $tail = $next->keepNext
            ? $this->keepHeight($next, array_slice($queue, 1), $depth + 1)
            : $next->minHeight();

        return $f->height() + $f->spaceAfter + $next->spaceBefore + $tail;
    }

    private function place(Flowable $f, float $before): void
    {
        $page = $this->pages[count($this->pages) - 1];
        $this->y += $before;
        $f->draw($page, $this->x + $f->indent, $this->y);
        $this->y += $f->height();
        $page->bodyBottom = max($page->bodyBottom, $this->y);
        $this->y += $f->spaceAfter;
        $this->atTop = false;
        if ($f->keepNext) {
            $this->endsWithKeep[count($this->pages)] = true;
        } else {
            unset($this->endsWithKeep[count($this->pages)]);
        }
    }

    private function newPage(): void
    {
        $this->pages[] = ($this->newCanvas)();
        $this->y = $this->top;
        $this->atTop = true;
    }

    /** @return list<int> pages whose last block was keep-with-next (a stranded heading) */
    public function strandedKeeps(): array
    {
        return array_keys($this->endsWithKeep);
    }
}

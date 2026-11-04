<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * Places flowables on pages, top to bottom: one stretch of pages of one shape.
 *
 *   - space before a block is dropped at the top of a page
 *   - a block that does not fit is split if it can be (between lines, between
 *     table rows) or moved to the next page whole
 *   - a keep-with-next block moves to the next page when it and the first
 *     piece of the block after it would not fit together (a chain of them
 *     moves together), unless the chain could not fit on any page
 *   - a block that does not fit even on an empty page is force-split, or drawn
 *     overflowing and reported
 *   - a footnote goes at the foot of the page that holds its mark. Its room is
 *     reserved as the block is placed, so a line and its note land together. A
 *     note too long for what is left continues at the foot of the next page; a
 *     note is never dropped, and a stretch is not left until all of it is drawn
 *     (a continuation is set at this stretch's width)
 *
 * The body's top and bottom come per page: a tall band moves them on the pages
 * that draw it.
 */
final class Paginator
{
    private const MAX_KEEP_CHAIN = 6;

    /** The box the short rule above a page's notes sits in, as in last-word-ui. */
    private const NOTE_SEPARATOR = 12.0;

    private const NOTE_RULE_WIDTH = 144.0;

    /** @var list<Canvas> */
    private array $pages = [];

    private float $y = 0.0;

    private float $top = 0.0;

    private float $bottom = 0.0;

    private bool $atTop = true;

    /** @var array<int, true> page numbers holding a keep-with-next block as their last block */
    private array $endsWithKeep = [];

    /** @var list<FootnoteFlow> what this page's foot is holding */
    private array $notesHere = [];

    /** @var array<int, true> the footnotes already on this page, by id */
    private array $notesSeen = [];

    /** @var list<FootnoteFlow> what ran off the previous page's foot */
    private array $carry = [];

    /** @var list<array{top: float, bottom: float}> */
    private array $boxes = [];

    /**
     * @param  \Closure(int): array{top: float, bottom: float}  $box  the body of the page at this 0-based index in the stretch
     * @param  \Closure(): Canvas  $newCanvas
     * @param  array<int, FootnoteFlow>  $notes  every footnote in the document, by id
     */
    public function __construct(
        private readonly float $x,
        private readonly \Closure $box,
        private readonly \Closure $newCanvas,
        private readonly Diagnostics $diagnostics,
        private readonly array $notes = [],
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
            $avail = $this->bottom - $this->y - $before - $this->noteCost();

            if ($f->keepNext && ! $this->atTop) {
                $need = $this->keepHeight($f, $queue, 0);
                if ($need > $avail + 0.001 && $need <= $this->nextCapacity()) {
                    $this->newPage();
                    array_unshift($queue, $f);

                    continue;
                }
            }

            if ($f->height() + $this->noteDelta($f) <= $avail + 0.001) {
                $this->place($f, $before);

                continue;
            }

            $parts = $this->splitToFit($f, $avail, false);
            if ($parts === null && $this->atTop) {
                $parts = $this->splitToFit($f, $avail, true);
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
            if ($this->notesHere !== []) {
                // An empty page already holding notes is the tail of one that ran
                // on. It takes the note and nothing else, and the block gets a
                // page of its own.
                $this->newPage();
                array_unshift($queue, $f);

                continue;
            }
            if ($this->notes !== [] && $f->height() > $avail + 0.001) {
                // A line and its note will not fit together on any page. The
                // line stays; what does not fit of the note runs on to the next.
                $parts = $f->forceSplit($avail);
                if ($parts !== null) {
                    $this->place($parts[0], $before);
                    $this->newPage();
                    array_unshift($queue, $parts[1]);

                    continue;
                }
            }
            if ($f->height() <= $avail + 0.001) {
                $this->place($f, 0.0);

                continue;
            }
            $this->diagnostics->add('overflow', 'error', $f->path, 'a block is taller than a page and cannot be split; it runs past the bottom margin', count($this->pages));
            $this->place($f, 0.0);
        }
        $this->closePage();
        while ($this->carry !== []) {
            $this->openPage();
            $this->closePage();
        }

        return $this->pages;
    }

    /**
     * Split where the head and the notes its marks bring both fit. With no
     * notes in the document this is exactly the plain split.
     *
     * @return array{0: Flowable, 1: Flowable}|null
     */
    private function splitToFit(Flowable $f, float $avail, bool $force): ?array
    {
        $call = fn (float $a) => $force ? $f->forceSplit($a) : $f->split($a);
        if ($this->notes === []) {
            return $call($avail);
        }
        // Whole, it may fit by itself and not with its notes: ask for less.
        $try = min($avail, $f->height() - 0.01);
        for ($i = 0; $i < 64 && $try > 0; $i++) {
            $parts = $call($try);
            if ($parts === null) {
                return null;
            }
            $over = $parts[0]->height() + $this->noteDelta($parts[0]) - $avail;
            if ($over <= 0.001) {
                return $parts;
            }
            $try = min($try - $over, $parts[0]->height() - 0.01);
        }

        return null;
    }

    /** @param list<Flowable> $queue */
    private function keepHeight(Flowable $f, array $queue, int $depth): float
    {
        $next = $queue[0] ?? null;
        $own = $f->height() + $this->noteDelta($f);
        if ($next === null || $next instanceof PageBreakFlow || $depth >= self::MAX_KEEP_CHAIN) {
            return $own;
        }
        $tail = $next->keepNext
            ? $this->keepHeight($next, array_slice($queue, 1), $depth + 1)
            : $next->minHeight();

        return $own + $f->spaceAfter + $next->spaceBefore + $tail;
    }

    /** What the next page can hold. */
    private function nextCapacity(): float
    {
        $box = ($this->box)(count($this->pages));

        return $box['bottom'] - $box['top'];
    }

    private function noteCost(): float
    {
        if ($this->notesHere === []) {
            return 0.0;
        }
        $h = self::NOTE_SEPARATOR;
        foreach ($this->notesHere as $note) {
            $h += $note->height();
        }

        return $h;
    }

    /** The room a block's marks would add to this page's foot. */
    private function noteDelta(Flowable $f): float
    {
        if ($this->notes === []) {
            return 0.0;
        }
        $h = 0.0;
        $seen = [];
        foreach ($f->notes() as $id) {
            if (isset($this->notes[$id]) && ! isset($this->notesSeen[$id]) && ! isset($seen[$id])) {
                $h += $this->notes[$id]->height();
                $seen[$id] = true;
            }
        }

        return $h > 0.0 && $this->notesHere === [] ? $h + self::NOTE_SEPARATOR : $h;
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
        if ($this->notes !== []) {
            foreach ($f->notes() as $id) {
                if (isset($this->notes[$id]) && ! isset($this->notesSeen[$id])) {
                    $this->notesSeen[$id] = true;
                    $this->notesHere[] = $this->notes[$id];
                }
            }
        }
    }

    private function newPage(): void
    {
        if ($this->pages !== []) {
            $this->closePage();
        }
        $this->openPage();
    }

    private function openPage(): void
    {
        $box = ($this->box)(count($this->pages));
        $this->boxes[] = $box;
        $this->pages[] = ($this->newCanvas)();
        $this->top = $box['top'];
        $this->bottom = $box['bottom'];
        $this->y = $this->top;
        $this->atTop = true;
        $this->notesHere = $this->carry;
        $this->notesSeen = [];
        foreach ($this->carry as $note) {
            $this->notesSeen[$note->id] = true;
        }
        $this->carry = [];
    }

    /**
     * Draw this page's notes at its foot, under a short rule. What does not fit
     * waits for the next page, split at a line if it can be. Whatever cannot be
     * split and fits on no page is drawn here, past the margin, and reported:
     * losing the text is the one thing a footnote must not do.
     */
    private function closePage(): void
    {
        if ($this->notesHere === []) {
            return;
        }
        $page = $this->pages[count($this->pages) - 1];
        $room = $this->bottom - max($page->bodyBottom, $this->top) - self::NOTE_SEPARATOR;
        $emptyPage = $page->bodyBottom <= 0.0;

        $fit = [];
        $used = 0.0;
        $carry = [];
        foreach ($this->notesHere as $i => $note) {
            if ($carry !== []) {
                $carry[] = $note;

                continue;
            }
            $h = $note->height();
            if ($used + $h <= $room + 0.001) {
                $fit[] = $note;
                $used += $h;

                continue;
            }
            $parts = $room - $used > 0.0 ? $note->forceSplit($room - $used) : null;
            if ($parts !== null) {
                $fit[] = $parts[0];
                $used += $parts[0]->height();
                $carry[] = $parts[1];
            } elseif ($emptyPage && $fit === []) {
                $this->diagnostics->add('overflow', 'error', $note->path, 'a footnote is taller than a page and cannot be split; it runs past the bottom margin', count($this->pages));
                $fit[] = $note;
                $used += $h;
            } else {
                $carry[] = $note;
            }
        }
        foreach ($carry as $note) {
            $this->diagnostics->add('footnote-continued', 'info', $note->path, 'a footnote does not fit under its mark and continues at the foot of the next page');
        }
        $this->carry = $carry;
        $this->notesHere = [];

        if ($fit === []) {
            return;
        }
        $y = $this->bottom - $used - self::NOTE_SEPARATOR;
        $mid = $y + self::NOTE_SEPARATOR / 2;
        $page->line($this->x, $mid, $this->x + self::NOTE_RULE_WIDTH, $mid, 0.5, [0.0, 0.0, 0.0]);
        $y += self::NOTE_SEPARATOR;
        foreach ($fit as $note) {
            $note->draw($page, $this->x, $y);
            $y += $note->height();
        }
    }

    /** @return list<int> pages whose last block was keep-with-next (a stranded heading) */
    public function strandedKeeps(): array
    {
        return array_keys($this->endsWithKeep);
    }

    /** @return list<array{top: float, bottom: float}> the body box of each page */
    public function boxes(): array
    {
        return $this->boxes;
    }
}

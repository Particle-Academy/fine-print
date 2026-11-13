<?php

declare(strict_types=1);

namespace FinePrint\Writer\Layout;

/**
 * A signing field (signing extension): an empty box with a rule under it and a
 * label below. It is DRAWING, not a form widget: the writer still emits no
 * `/AcroForm` and no annotation for it. Where the box lands is recorded on the
 * page ({@see Canvas::field()}) and reported in the placement map, so the
 * builder never stores a coordinate and a re-flowed document cannot leave a
 * field floating over the wrong line.
 *
 * It never splits: a field moves to the next page whole.
 */
final class FieldFlow extends Flowable
{
    /** Box sizes in points, by kind: [width, height]. Capped at the column width. */
    public const SIZES = [
        'signature' => [216.0, 40.0],
        'initials' => [72.0, 28.0],
        'date_signed' => [144.0, 18.0],
    ];

    /** Space between the rule and the label. */
    private const LABEL_GAP = 2.0;

    /**
     * @param  list<Line>  $label
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $signer,
        public readonly bool $required,
        private readonly float $boxWidth,
        private readonly float $boxHeight,
        private readonly array $label,
        public readonly string $origin = 'node',
    ) {}

    public function height(): float
    {
        return $this->boxHeight + 1.0 + ($this->label === [] ? 0.0 : self::LABEL_GAP + array_sum(array_map(fn (Line $l) => $l->height, $this->label)));
    }

    public function draw(Canvas $c, float $x, float $top): void
    {
        $c->field($this->id, $this->kind, $this->signer, $this->required, $this->origin, $x, $top, $this->boxWidth, $this->boxHeight);
        $c->line($x, $top + $this->boxHeight + 0.5, $x + $this->boxWidth, $top + $this->boxHeight + 0.5, 0.75, [0.2, 0.2, 0.2]);
        $y = $top + $this->boxHeight + 1.0 + self::LABEL_GAP;
        foreach ($this->label as $line) {
            $c->text($line, $x, $y, $this->boxWidth, 'left');
            $y += $line->height;
        }
    }
}

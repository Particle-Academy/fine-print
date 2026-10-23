<?php

declare(strict_types=1);

use FinePrint\Exceptions\RefusedException;
use FinePrint\Render\Renderer;
use FinePrint\Tests\Support\PdfBuilder;
use FinePrint\Tests\Support\RenderFixtures;

/** A first-party Quartz-style form, with no dependency on a private producer's file. */
function fpTransparency(string $inside, string $group = '', string $before = '', string $state = '', string $formResources = ''): string
{
    return fprPage($before.' /Invoke gs /Fm Do', resources: '/ExtGState << /Invoke << '.$state.' >> >> /XObject << /Fm 6 0 R >>',
        extra: fn (PdfBuilder $b) => $b->stream($inside, '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency '.$group.' >> /Resources << '.$formResources.' >>'));
}

it('renders a Quartz-style normal group identically to direct painting', function () {
    $ops = '0.2 0.6 0.8 rg 5 5 60 60 re f 1 0.2 0 rg 25 25 65 65 re f';
    $group = Renderer::render(fpTransparency($ops));
    expect($group->complete())->toBeTrue()->and($group->png())->toBe(Renderer::png(fprPage($ops)));
});

it('applies invocation opacity once to the whole group and resets its internal alpha', function () {
    $r = Renderer::render(fpTransparency('1 0 0 rg 0 0 60 100 re f 40 0 60 100 re f', '/I true', '0 0 1 rg 0 0 100 100 re f', '/ca 0.5 /CA 0.2'));
    expect($r->complete())->toBeTrue()->and($r->pixel(20, 50))->toBe([128, 0, 128])
        ->and($r->pixel(50, 50))->toBe([128, 0, 128]);
});

it('distinguishes isolated groups from groups that blend against their backdrop', function (string $isolation, array $rgb) {
    $r = Renderer::render(fpTransparency('/Mul gs 1 0 0 rg 0 0 100 100 re f', $isolation, '0 0 1 rg 0 0 100 100 re f', '', '/ExtGState << /Mul << /BM /Multiply >> >>'));
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe($rgb);
})->with(['isolated' => ['/I true', [255, 0, 0]], 'non-isolated' => ['/I false', [0, 0, 0]]]);

it('removes the initial backdrop before applying the group opacity', function () {
    $r = Renderer::render(fpTransparency('/Half gs 1 0 0 rg 0 0 100 100 re f', '', '0 0 1 rg 0 0 100 100 re f', '/ca 0.5', '/ExtGState << /Half << /ca 0.5 >> >>'));
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([64, 0, 191]);
});

it('knocks out previous group objects by shape rather than opacity', function (string $knockout, array $rgb) {
    $r = Renderer::render(fpTransparency('/Half gs 1 0 0 rg 0 0 100 100 re f 0 1 0 rg 20 20 60 60 re f', '/I true '.$knockout, '', '', '/ExtGState << /Half << /ca 0.5 >> >>'));
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe($rgb);
})->with(['knockout' => ['/K true', [128, 255, 128]], 'ordinary' => ['/K false', [128, 191, 64]]]);

it('uses a group colour space for its blending rather than converting the result afterwards', function () {
    $r = Renderer::render(fpTransparency('1 0 0 rg 0 0 100 100 re f /Mul gs 0 1 0 rg 0 0 100 100 re f', '/I true /CS /DeviceGray', '', '', '/ExtGState << /Mul << /BM /Multiply >> >>'));
    // PDF's DeviceRGB -> DeviceGray weights: .30, .59, .11; .30 * .59.
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([45, 45, 45]);
});

it('selects the first supported blend mode in an array', function () {
    $r = Renderer::render(fprPage('0.2 0.4 0.8 rg 0 0 100 100 re f /B gs 0.8 0.3 0.1 rg 0 0 100 100 re f', resources: '/ExtGState << /B << /BM [/Unknown /Multiply /Screen] >> >>'));
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([41, 31, 20]);
});

it('retains an omission for unsupported group colour spaces and blend modes', function (string $group, string $state) {
    expect(Renderer::render(fpTransparency('0 0 100 100 re f', $group, '', $state))->complete())->toBeFalse();
})->with([['/CS /Lab', ''], ['', '/BM /Unknown']]);

function fpSoftMask(string $subtype, string $maskOps, string $extras = '', string $pageOps = '', string $groupExtras = '/CS /DeviceRGB'): string
{
    return fprPage('/M gs '.($pageOps ?: '1 0 0 rg 0 0 100 100 re f'), resources: '/ExtGState << /M << /SMask << /S /'.$subtype.' /G 6 0 R '.$extras.' >> >> >>',
        extra: fn (PdfBuilder $b) => $b->stream($maskOps, '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency '.$groupExtras.' >> /Resources << /ExtGState << /Half << /ca 0.5 >> >> >>'));
}

it('renders alpha masks independently of their colour', function () {
    $r = Renderer::render(fpSoftMask('Alpha', '/Half gs 0 0 0 rg 0 0 50 100 re f'));
    expect($r->complete())->toBeTrue()->and($r->pixel(25, 50))->toBe([255, 128, 128])
        ->and($r->pixel(75, 50))->toBe([255, 255, 255]);
});

it('renders luminosity masks against BC and applies their transfer function', function () {
    $r = Renderer::render(fpSoftMask('Luminosity', '/Half gs 1 1 1 rg 0 0 50 100 re f', '/BC [0.2 0.2 0.2] /TR << /FunctionType 2 /Domain [0 1] /C0 [1] /C1 [0] /N 1 >>'));
    expect($r->complete())->toBeTrue()->and($r->pixel(25, 50))->toBe([255, 153, 153])
        ->and($r->pixel(75, 50))->toBe([255, 51, 51]);
});

it('keeps the soft mask in the coordinate system where gs installed it', function () {
    $r = Renderer::render(fpSoftMask('Alpha', '0 0 50 100 re f', '', '1 0 0 1 20 0 cm 1 0 0 rg 0 0 100 100 re f'));
    expect($r->complete())->toBeTrue()->and($r->pixel(35, 50))->toBe([255, 0, 0])
        ->and($r->pixel(60, 50))->toBe([255, 255, 255]);
});

it('applies masks to group invocations once', function () {
    $pdf = fprPage('/M gs /Fm Do', resources: '/ExtGState << /M << /SMask << /S /Alpha /G 6 0 R >> >> >> /XObject << /Fm 7 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream('/H gs 0 0 100 100 re f', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency >> /Resources << /ExtGState << /H << /ca 0.5 >> >> >>');
        $b->stream('1 0 0 rg 0 0 60 100 re f 40 0 60 100 re f', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency /I true >>');
    });
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeTrue()->and($r->pixel(20, 50))->toBe([255, 128, 128])
        ->and($r->pixel(50, 50))->toBe([255, 128, 128]);
});

it('counts simultaneously live group buffers against maxRenderPixels', function () {
    $pdf = fprPage('/A Do', resources: '/XObject << /A 6 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream('/B Do', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency /I true >> /Resources << /XObject << /B 7 0 R >> >>');
        $b->stream('0 0 100 100 re f', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency /I true >>');
    });
    try {
        Renderer::render($pdf, limits: ['maxRenderPixels' => 25000]);
        test()->fail('Three live canvases must exceed the 25,000 pixel cap.');
    } catch (RefusedException $e) {
        expect($e->reason)->toBe('too_many_pixels');
    }
});

it('honours the page group colour space and its isolation even when I is false', function () {
    $pdf = fprPage('/B gs 1 0 0 rg 0 0 100 100 re f', page: '/Group << /S /Transparency /I false /CS /DeviceGray >>', resources: '/ExtGState << /B << /BM /Screen >> >>');
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([77, 77, 77]);
});

it('starts the implicit page group transparent before painting the display background', function () {
    $r = Renderer::render(fprPage('/B gs 1 0 0 rg 0 0 100 100 re f', resources: '/ExtGState << /B << /BM /Screen >> >>'));
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([255, 0, 0]);
});

it('keeps a normal invariant group within the page pixel cap without an offscreen buffer', function () {
    $r = Renderer::render(fpTransparency('1 0 0 rg 0 0 100 100 re f'), limits: ['maxRenderPixels' => 10000]);
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([255, 0, 0]);
});

it('preserves native CMYK components through separable blending', function () {
    $r = Renderer::render(fpTransparency('0.2 0.4 0.6 0.25 k 0 0 100 100 re f /M gs 0.4 0.6 0.2 0.5 k 0 0 100 100 re f', '/I true /CS /DeviceCMYK', '', '', '/ExtGState << /M << /BM /Multiply >> >>'));
    // Complementary components: [.8*.6, .6*.4, .4*.8], K complement .75*.5.
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([46, 23, 31]);
});

it('distinguishes alpha-as-shape from opacity inside a knockout group', function () {
    $r = Renderer::render(fpTransparency('1 0 0 rg 0 0 100 100 re f /S gs 0 1 0 rg 0 0 100 100 re f', '/I true /K true', '', '', '/ExtGState << /S << /ca 0.5 /AIS true >> >>'));
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([128, 128, 0]);
});

it('evaluates bounded stitching transfer functions in soft masks', function () {
    $tr = '/TR << /FunctionType 3 /Domain [0 1] /Functions [<< /FunctionType 2 /Domain [0 1] /C0 [0] /C1 [1] /N 1 >> << /FunctionType 2 /Domain [0 1] /C0 [1] /C1 [0] /N 1 >>] /Bounds [0.5] /Encode [0 1 0 1] >>';
    $r = Renderer::render(fpSoftMask('Alpha', '/Half gs 0 0 100 100 re f', $tr));
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([255, 0, 0]);
});

it('bounds recursive masks with the shared form depth guard', function () {
    $r = Renderer::render(fprPage('/M gs 0 0 100 100 re f', resources: '/ExtGState << /M << /SMask << /S /Alpha /G 6 0 R >> >> >>', extra: fn (PdfBuilder $b) => $b->stream('/M gs 0 0 100 100 re f', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency /I true >>')));
    expect($r->complete())->toBeFalse()->and(array_column($r->skipped, 'kind'))->toContain('form-depth');
});

it('budgets cropped group and mask buffers by their actual device rectangle', function (bool $mask) {
    $pdf = fprPage($mask ? '/M gs 1 0 0 rg 0 0 100 100 re f' : '/G Do', resources: $mask ? '/ExtGState << /M << /SMask << /S /Alpha /G 6 0 R >> >> >>' : '/XObject << /G 6 0 R >>', extra: fn (PdfBuilder $b) => $b->stream('1 0 0 rg 0 0 100 100 re f', '/Subtype /Form /BBox [0 0 10 10] /Group << /S /Transparency /I true >>'));
    $r = Renderer::render($pdf, limits: ['maxRenderPixels' => 10200]);
    expect($r->complete())->toBeTrue()->and($r->pixel(5, 95))->toBe([255, 0, 0])
        ->and($r->pixel(20, 95))->toBe([255, 255, 255]);
})->with(['group' => false, 'mask' => true]);

it('matches the ISO group equations in every isolation and knockout quadrant', function (int $x, int $y, array $red, array $green, array $overlap) {
    // Independently expanded ISO 11.4 equations, NOT values taken from our
    // renderer: background (.2,.4,.8), red(.6 Multiply), green(.4 Screen),
    // invocation(.7 HardLight). E.g. nonisolated red: group=(.2,.08,0),
    // resulting display=(.1496,.25888,.464). Knockout overlap is green alone.
    // Isolated overlap: alpha=.6+.4*(1-.6)=.76; associated colour
    // =(.616,.4368,.08), divided by .76 before invocation blending.
    // Nonisolated overlap: associated colour=(.184,.39712,.1824), alpha=.76.
    // Isolated green alone=(.1,.9,.2), nonisolated Screen green=(.28,.94,.84),
    // both alpha=.4. HardLight with (.2,.4,.8) then source-over at .7*alpha
    // gives the byte triples below. K replaces overlap by that green alone.
    $r = Renderer::render(RenderFixtures::pdf('transparency-groups'));
    expect($r->complete())->toBeTrue()->and($r->pixel($x + 10, 200 - $y - 10))->toBe($red)
        ->and($r->pixel($x + 80, 200 - $y - 80))->toBe($green)
        ->and($r->pixel($x + 50, 200 - $y - 50))->toBe($overlap);
})->with([
    'nonisolated' => [0, 0, [38, 66, 118], [45, 140, 214], [37, 106, 148]],
    'isolated' => [100, 0, [137, 76, 118], [40, 136, 170], [118, 114, 118]],
    'nonisolated knockout' => [0, 100, [38, 66, 118], [45, 140, 214], [45, 140, 214]],
    'isolated knockout' => [100, 100, [137, 76, 118], [40, 136, 170], [40, 136, 170]],
]);

it('inherits AIS while resetting the invocation alpha inside a group', function () {
    $r = Renderer::render(fpTransparency('1 0 0 rg 0 0 100 100 re f /H gs 0 1 0 rg 0 0 100 100 re f', '/I true /K true', '', '/AIS true', '/ExtGState << /H << /ca 0.5 >> >>'));
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([128, 128, 0]);
});

it('resolves the mask group colour space in the form own resources', function () {
    $pdf = fprPage('/M gs 1 0 0 rg 0 0 100 100 re f', resources: '/ExtGState << /M << /SMask << /S /Luminosity /G 6 0 R >> >> >>', extra: fn (PdfBuilder $b) => $b->stream('0.5 g 0 0 100 100 re f', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency /CS /MaskGray >> /Resources << /ColorSpace << /MaskGray /DeviceGray >> >>'));
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([255, 128, 128]);
});

it('lets an image mask override the current ExtGState soft mask', function () {
    $pdf = fprPage('/M gs 100 0 0 100 0 0 cm /Im Do', resources: '/ExtGState << /M << /SMask << /S /Alpha /G 6 0 R >> >> >> /XObject << /Im 7 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream('', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency >>');
        $b->stream("\0\xFF\0", '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /SMask 8 0 R');
        $b->stream("\x80", '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceGray');
    });
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([127, 255, 127]);
});

it('uses image soft masks as shape when AIS is true', function () {
    $pdf = fprPage('/G Do', resources: '/XObject << /G 6 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream('1 0 0 rg 0 0 100 100 re f /S gs 100 0 0 100 0 0 cm /Im Do', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency /I true /K true >> /Resources << /ExtGState << /S << /AIS true >> >> /XObject << /Im 7 0 R >> >>');
        $b->stream("\0\xFF\0", '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /SMask 8 0 R');
        $b->stream("\x80", '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceGray');
    });
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([127, 128, 0]);
});

it('does not let explicitly masked image pixels knock out an earlier object', function () {
    $pdf = fprPage('/G Do', resources: '/XObject << /G 6 0 R >>', extra: function (PdfBuilder $b) {
        $b->stream('1 0 0 rg 0 0 100 100 re f 100 0 0 100 0 0 cm /Im Do', '/Subtype /Form /BBox [0 0 100 100] /Group << /S /Transparency /I true /K true >> /Resources << /XObject << /Im 7 0 R >> >>');
        $b->stream("\0\xFF\0", '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 8 /ColorSpace /DeviceRGB /Mask 8 0 R');
        $b->stream("\x80", '/Subtype /Image /Width 1 /Height 1 /BitsPerComponent 1 /ImageMask true');
    });
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeTrue()->and($r->pixel(50, 50))->toBe([255, 0, 0]);
});

it('treats combined path filling and stroking as one transparency object', function () {
    $r = Renderer::render(fprPage('/H gs 1 0 0 rg 0 1 0 RG 20 w 20 20 60 60 re B', resources: '/ExtGState << /H << /ca 0.5 /CA 0.5 >> >>'));
    expect($r->complete())->toBeTrue()->and($r->pixel(25, 50))->toBe([128, 255, 128])
        ->and($r->pixel(50, 50))->toBe([255, 128, 128]);
});

it('retains an omission for unsupported text knockout and calibrated luminosity masks', function (string $pdf) {
    $r = Renderer::render($pdf);
    expect($r->complete())->toBeFalse()->and(array_column($r->skipped, 'kind'))->toContain('transparency');
})->with([
    'text knockout false' => fn () => fprPage('/T gs BT /F1 12 Tf 10 10 Td (Hello) Tj ET', resources: '/ExtGState << /T << /TK false >> >>'),
    'calibrated mask luminosity' => fn () => fpSoftMask('Luminosity', '0.5 g 0 0 100 100 re f', groupExtras: '/CS [/CalRGB << /WhitePoint [0.9505 1 1.089] /Gamma [2.2 2.2 2.2] >>]'),
]);

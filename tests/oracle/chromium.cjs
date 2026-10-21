// Golden oracle, step 2 of 3: screenshot each fixture and its calibration
// page in Chromium's PDF viewer. TEST ORACLE ONLY; see build.php.
//
//   PLAYWRIGHT_CORE=<path to a playwright-core install>
//   CHROMIUM=<path to chrome.exe / chrome>
//   node tests/oracle/chromium.cjs

const path = require('node:path');
const fs = require('node:fs');

const core = process.env.PLAYWRIGHT_CORE;
const exe = process.env.CHROMIUM;
if (!core || !exe) {
  console.error('Set PLAYWRIGHT_CORE and CHROMIUM.');
  process.exit(2);
}
const { chromium } = require(core);
const jobs = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '.out', 'oracle', 'jobs.json'), 'utf8'));

async function shot(browser, pdf, job, out) {
  const ctx = await browser.newContext({ viewport: job.viewport, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const url = 'file:///' + pdf.replace(/\\/g, '/') + '#toolbar=0&zoom=' + job.zoom;
  await page.goto(url, { waitUntil: 'load' }).catch(() => {});
  await page.waitForTimeout(2500);
  await page.screenshot({ path: out });
  await ctx.close();
}

// NEVER on the owner's desktop. Only through Playwright's launcher, always
// headless, never first-run UI, always closed. Never run chrome.exe directly
// (not even `chrome.exe --version`: on Windows that opens a visible window
// with first-run onboarding; it happened 2026-10-21). The version comes from
// browser.version() below.
const LAUNCH = {
  executablePath: exe,
  headless: true, // Playwright's new headless mode; the PDF viewer works in it
  args: ['--no-first-run', '--no-default-browser-check'],
  // Playwright gives launch() a throwaway profile in the temp folder and
  // deletes it on close; a --user-data-dir would point it somewhere real.
};

function refuseUnlessHeadless(options) {
  const args = options.args || [];
  if (options.headless !== true || args.some((a) => /^--(headed|user-data-dir|start-maximized)/.test(a))
    || process.env.HEADED || process.env.PWDEBUG) {
    console.error('Refused: the oracle only ever runs headless with a throwaway profile.');
    process.exit(3);
  }
}

(async () => {
  refuseUnlessHeadless(LAUNCH);
  const browser = await chromium.launch(LAUNCH);
  try {
    console.log('Chromium', browser.version());
    for (const job of jobs) {
      await shot(browser, job.pdf, job, job.shot);
      await shot(browser, job.cal, job, job.calShot);
      console.log('shot', job.name + '@' + job.scale + 'x');
    }
    fs.writeFileSync(path.join(__dirname, '..', '.out', 'oracle', 'chromium-version.txt'), browser.version());
  } finally {
    await browser.close();
  }
})();

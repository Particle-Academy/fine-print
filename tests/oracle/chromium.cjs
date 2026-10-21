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

(async () => {
  const browser = await chromium.launch({ executablePath: exe, headless: true });
  console.log('Chromium', browser.version());
  for (const job of jobs) {
    await shot(browser, job.pdf, job, job.shot);
    await shot(browser, job.cal, job, job.calShot);
    console.log('shot', job.name + '@' + job.scale + 'x');
  }
  fs.writeFileSync(path.join(__dirname, '..', '.out', 'oracle', 'chromium-version.txt'), browser.version());
  await browser.close();
})();

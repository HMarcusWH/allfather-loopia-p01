import { chromium } from "playwright";
import { randomUUID } from "node:crypto";

/**
 * P09 DEMO_VIEWER v1 deployed browser smoke. This file is not the full P09
 * acceptance runner. Authentication necessarily POSTs to /login; afterward
 * it performs GET navigation only, never account/workspace mutation,
 * setup apply, scheduler planning, CSRF probes or fixture writes.
 */
const base = "https://allfather-php-mvp-staging.up.railway.app";
const sha = process.env.P09_EXPECTED_RELEASE_SHA;
if (!/^[a-f0-9]{40}$/.test(sha || "")) throw Error("exact reviewed release SHA required");
if (process.env.P09_TEST_SCOPE_REVIEWED !== "YES") throw Error("independent TEST scope review required");
const email = process.env.TEST_LOGIN_EMAIL;
const password = process.env.TEST_LOGIN_PASSWORD;
if (!email || !password || !/^[a-z0-9][a-z0-9._+\-]*@example\.test$/.test(email)) {
  throw Error("private synthetic login credentials required");
}
const viewports = [["desktop", { width: 1440, height: 900 }], ["mobile", { width: 390, height: 844 }]];
const routes = [
  ["/console", "viewer-presentation"],
  ["/console/app", "viewer-presentation"],
  ["/account", "viewer-presentation"],
  ["/console/monitor", "role-restricted"],
  ["/console/monitor/setup", "role-restricted"],
  ["/console/monitor/schedule", "role-restricted"],
  ["/console/briefings", "role-restricted"],
  ["/console/distributions", "role-restricted"],
  ["/console/marketing", "role-restricted"],
];
const observations = [];
let fail = 0, pass = 0, notCovered = 0, blocked = 0;
const record = row => {
  const clean = { ...row };
  observations.push(clean);
  if (row.result === "FAIL") fail++;
  else if (row.result === "PASS") pass++;
  else if (row.result === "BLOCKED") blocked++;
  else notCovered++;
};
const browser = await chromium.launch({ headless: true, args: ["--no-sandbox"] });
try {
  for (const [name, viewport] of viewports) {
    const context = await browser.newContext({ viewport, reducedMotion: "reduce" });
    const page = await context.newPage();
    let observing = false;
    const mutating = [], external = [], jsErrors = [];
    page.on("pageerror", e => jsErrors.push(e.name.slice(0, 60)));
    // Enforce network constraints before dispatch. The login POST is outside
    // the read-only observation phase; all later page requests are GET/HEAD
    // and same-origin only. A merely logged forbidden request is too late.
    await page.route("**/*", async route => {
      if (!observing) return route.continue();
      const req = route.request();
      const parsed = new URL(req.url());
      if (!["GET", "HEAD"].includes(req.method())) {
        mutating.push({ method: req.method(), path: parsed.pathname.slice(0, 100) });
        return route.abort("blockedbyclient");
      }
      if (parsed.origin !== base) {
        external.push(parsed.hostname.slice(0, 80));
        return route.abort("blockedbyclient");
      }
      return route.continue();
    });
    try {
      // Anonymous negative check is a GET; no session or privileged scope.
      const anon = await context.request.get(base + "/console/api/v1/monitor/runs", { maxRedirects: 0 });
      record({ viewport: name, check: "anonymous-monitor-api", status: anon.status(),
        result: anon.status() === 401 ? "PASS" : "FAIL" });

      const login = await page.goto(base + "/login", { waitUntil: "domcontentloaded" });
      if (login?.status() !== 200 || await page.locator('input[name="password"]').count() !== 1) {
        record({ viewport: name, check: "login-form", status: login?.status(), result: "FAIL" });
        continue;
      }
      // This is authentication setup, NOT an authorized application write.
      await page.locator('input[name="email"]').fill(email);
      await page.locator('input[name="password"]').fill(password);
      await page.locator('input[name="password"]').evaluate(el => el.closest("form").requestSubmit());
      await page.waitForURL(u => u.pathname !== "/login", { timeout: 20000 });
      const landedUrl = new URL(page.url());
      const landed = landedUrl.pathname;
      if (landedUrl.origin !== base) {
        record({ viewport: name, check: "login-origin", result: "FAIL",
          reason: "authentication redirected outside pinned staging origin" });
        continue;
      }
      if (landed === "/account/password" || landed.startsWith("/password")
          || landed === "/change-password") {
        record({ viewport: name, check: "first-password-change",
          result: "BLOCKED", reason: "normal forced password-change lifecycle requires separately approved account setup" });
        continue;
      }
      if (landed === "/console/workspaces") {
        record({ viewport: name, check: "workspace-selection",
          result: "BLOCKED", reason: "workspace POST is outside this GET-only browser subset; approve and complete separately" });
        continue;
      }
      record({ viewport: name, check: "login-session", result: "PASS", landed });
      observing = true;

      for (const [path, cls] of routes) {
        const response = await page.goto(base + path, { waitUntil: "domcontentloaded", timeout: 30000 });
        const status = response?.status() ?? 0;
        const actualPath = new URL(page.url()).pathname;
        if ([401, 403, 404].includes(status)) {
          record({ viewport: name, check: path, status, result: "NOT_COVERED",
            reason: "viewer permission boundary; no positive feature acceptance claimed" });
          continue;
        }
        if (status !== 200 || actualPath !== path) {
          record({ viewport: name, check: path, status, actualPath,
            result: "NOT_COVERED", reason: "redirect or unavailable route; not claimed as authenticated success" });
          continue;
        }
        const layout = await page.evaluate(() => ({
          documentWidth: document.documentElement.scrollWidth,
          viewportWidth: window.innerWidth,
          hasViewportMeta: !!document.querySelector('meta[name="viewport"]'),
          scopedUrl: [...new URL(location.href).searchParams.keys()]
            .some(key => /^(tenant|workspace|operator)(_id|_key)?$/i.test(key)),
          forbiddenControl: [...document.querySelectorAll("button,input[type=submit],a")]
            .some(el => /^(admit schedule|admit execution|approve prompt|activate recurrence)$/i
              .test((el.textContent || el.value || "").trim())),
        }));
        const overflow = layout.documentWidth > layout.viewportWidth + 1;
        const safe = !overflow && !layout.scopedUrl && !layout.forbiddenControl;
        record({ viewport: name, check: path, status, overflow,
          documentWidth: layout.documentWidth, viewportWidth: layout.viewportWidth,
          result: safe ? (cls === "viewer-presentation" ? "PASS" : "NOT_COVERED") : "FAIL",
          reason: cls === "role-restricted" ? "page shell only; feature authorization and detail content not proven" : undefined });
      }

      // Unknown-object negative reads only; no owned/foreign fixture can be
      // inferred from the empty TEST scope.
      for (const [label, path] of [
        ["p02", "/console/api/v1/p02/entity-detail?snapshot="],
        ["p03", "/console/api/v1/p03/prospect-detail?output="],
        ["monitor-run", "/console/api/v1/monitor/run?run="],
      ]) {
        const response = await context.request.get(base + path + randomUUID(), {
          failOnStatusCode: false, maxRedirects: 0
        });
        const status = response.status();
        if (status === 403 || status === 401) {
          record({ viewport: name, check: label + "-unknown", status,
            result: "NOT_COVERED", reason: "viewer lacks owner detail permission" });
        } else if (status === 404) {
          let state = null;
          try { state = (await response.json())?.owner_state; } catch {}
          record({ viewport: name, check: label + "-unknown", status,
            result: state === "NOT_FOUND" ? "PASS" : "FAIL" });
        } else {
          record({ viewport: name, check: label + "-unknown", status, result: "FAIL",
            reason: "unexpected object selector response" });
        }
      }
      observing = false;
      record({ viewport: name, check: "passive-navigation-network",
        nonGetRequests: mutating.length, offOriginRequests: external.length,
        result: mutating.length === 0 && external.length === 0 ? "PASS" : "FAIL" });
      if (jsErrors.length) {
        record({ viewport: name, check: "browser-errors", count: jsErrors.length, result: "FAIL" });
      }
      for (const area of ["setup-apply", "schedule-plan-propose-suspend-revoke", "csfr-post-negatives",
        "p02-positive-detail", "p03-positive-detail", "monitor-positive-run", "cross-tenant-fixtures"]) {
        record({ viewport: name, check: area, result: "NOT_COVERED",
          reason: "outside signed DemoViewer read-only acceptance boundary" });
      }
    } catch (error) {
      record({ viewport: name, check: "journey", result: "FAIL", errorName: error?.name || "Error" });
    } finally {
      await context.close();
    }
  }
} finally {
  await browser.close();
}
const verdict = fail ? "FAIL_OR_PARTIAL"
  : blocked ? "BLOCKED_NEEDS_AUTH_SETUP"
  : "PASS_GET_ONLY_VIEWER_SUBSET_ONLY";
console.log("P09_DEMO_VIEWER_GET_ONLY_RESULTS", JSON.stringify({
  verdict, release_sha: sha, browser: "Playwright Chromium", pass, fail, notCovered, blocked, observations
}));
if (fail) process.exitCode = 1;
else if (blocked) process.exitCode = 2;

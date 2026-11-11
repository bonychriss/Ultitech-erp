import { chromium } from "playwright";
import { readFileSync } from "fs";

const html = readFileSync("3d design/index.html", "utf8").replace("loading.remove();", "window.__view = setView; loading.remove();");
const browser = await chromium.launch({ args: ["--use-gl=angle", "--enable-webgl", "--ignore-gpu-blocklist"] });
const page = await browser.newPage({ viewport: { width: 1500, height: 900 } });
const errors = [];
page.on("pageerror", (e) => errors.push(e.message));
page.on("console", (m) => ["error", "warning"].includes(m.type()) && errors.push(m.text()));
await page.route("**/3d%20design/index.html", (route) => route.fulfill({ contentType: "text/html", body: html }));
await page.goto("http://localhost/public_html/3d%20design/index.html", { waitUntil: "networkidle" });
await page.waitForTimeout(6000);
const tag = process.argv[2] || "r1";
const shots = {
  default: null,
  gate: [[1.6, 1.7, 14.2], [-1.2, 1.6, -18]],
  porch: [[2.2, 1.7, -9.5], [-2.5, 1.5, -18]],
  lawn: [[1.2, 3.2, 6.5], [-4, 0.8, -2]],
  stack: [[5, 6, 6], [-5, 2.5, 10]],
};
for (const [name, v] of Object.entries(shots)) {
  if (v) await page.evaluate((v) => window.__view(v[0], v[1]), v);
  await page.waitForTimeout(1800);
  await page.screenshot({ path: `C:/xampp/gl-shots/3d/${tag}_${name}.png` });
}
console.log("errors:", JSON.stringify(errors.slice(0, 10)));
await browser.close();

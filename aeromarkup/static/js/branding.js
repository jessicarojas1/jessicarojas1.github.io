/* AeroMarkup — Settings & Branding.
   Persisted, offline (IndexedDB meta). Lets the user set a logo (URL or
   uploaded data URL), an org/app display name, and an accent color, applied
   live across the shell and into the PDF report. */
import { getMeta, setMeta } from "./store.js";
import { icon } from "./icons.js";
import { esc } from "./ui.js";

const DEFAULT = { org_name: "AeroMarkup", logo_url: "", accent: "#ff5811" };

export async function getBranding() {
  return { ...DEFAULT, ...(await getMeta("branding", {})) };
}
export async function setBranding(patch) {
  const b = { ...(await getBranding()), ...patch };
  await setMeta("branding", b);
  return b;
}

/* Only allow safe image sources; reject javascript:/other schemes. */
export function safeLogo(url) {
  if (!url) return "";
  const u = String(url).trim();
  return /^(https?:\/\/|data:image\/)/i.test(u) ? u : "";
}

export async function applyBranding() {
  const b = await getBranding();
  const logo = safeLogo(b.logo_url);
  document.title = `${b.org_name || "AeroMarkup"} — Aerospace Engineering Lifecycle`;
  const mark = document.querySelector(".brand-mark");
  const name = document.querySelector(".brand-name");
  if (name) name.textContent = b.org_name || "AeroMarkup";
  if (mark) {
    mark.innerHTML = logo
      ? `<img src="${esc(logo)}" alt="logo" style="width:24px;height:24px;object-fit:contain;border-radius:4px">`
      : icon("plane", 20);
  }
  document.documentElement.style.setProperty("--primary", b.accent || "#ff5811");
  return b;
}

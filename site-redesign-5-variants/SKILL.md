---
name: site-redesign-5-variants
description: Redesign a data-reseller (or similar PHP MVC) website - show 5 design directions to pick from first, then apply the chosen look in safe, staged, logic-preserving folders. Use when someone shares a site's source code and asks for a revamp, new look, redesign or modern UI.
---

# Site redesign with 5 variants first

Use when the user gives a website's source code (or a folder on their computer) and wants its whole look redesigned (a full visual revamp - the approach used for maptechgh.com's "Midnight" redesign).

## Phase 1 — Understand the site (change nothing)
- Map stack, layouts, pages, CSS files, themes, fonts, icon set.
- Group pages: public/landing, auth, member dashboard, buy data, bulk, wallet/fund, history, shop storefronts, shop owner, sub-agent, admin.
- Give a short summary of what exists and anything that looks broken.

## Phase 2 — Five design directions, then STOP
- Build ONE self-contained preview HTML page with a switcher between 5 genuinely different directions (differ in layout, density or personality — not just colour). Examples: dark premium, clean light fintech, bold colourful, minimal mono, glassy gradient.
- Each direction shows, with realistic Ghana data-reseller content (GH₵ prices, MTN/Telecel/AirtelTigo, 024… numbers): member dashboard, Buy Data (network + bundle picker), and a phone view. Phone-first.
- Below it, a table: name · idea · when it's right · its downside.
- Wait for the user to pick (they may say "mix 2 and 4" or "riff on 3"). Do not apply anything before they choose.

## Phase 3 — Apply the chosen direction in stages
Default stages (confirm or let the user change):
1. Design system + layouts + home + auth + dashboard
2. Buy data, bulk, fund wallet, order history
3. Admin dashboard + shop storefronts
4. Remaining member pages
5. Shop owner pages
6. Remaining admin pages
Offer a few curated themes (dark default + light + 2 colour themes) with a switcher.

## Hard rules
- Visual changes only. Keep every form field name, form action, route, CSRF token, element id used by scripts, onclick/onsubmit handler and controller untouched. Fix a real bug only if tiny, and tell the user clearly.
- Never overwrite live files. Deliver each stage into its own folder inside the site folder (`_redesign_stage1`, `_redesign_stage2`, …) with an INSTALL.txt listing every file and what to back up.
- Don't read or copy `.env`, config with passwords, database dumps, logs, uploads or vendor.
- Preserve each file's original line endings.
- Replace emoji icons with one icon set (Tabler) and make sure every layout loads that icon font.
- Check the Content-Security-Policy and any service worker so fonts/icons aren't blocked on the live site (service worker should skip cross-origin requests).
- Phones: no sideways page scroll, 16px inputs, big tap targets; long button rows become a "⋯" menu; long pick-lists become a compact dropdown with search (opening below the field, not a bottom sheet); scrollable chip rows glide slowly ticker-style until touched.

## Check before delivering each stage
- Render every changed page with sample data on desktop and phone and look at the screenshots.
- Confirm no form names/actions/ids/handlers were lost versus the original.
- PHP syntax-check every file; syntax-check every inline script.
- Show screenshots, then save the stage folder to the user's computer.

## At the end
- Offer a promo catalogue: 13 portrait 1080×1350 images + one PDF (cover, before/after, key screens in laptop/phone frames, themes, closing "get yours" card) using sample data only.

---
name: ui-ux-pro-max
description: Design-intelligence for building or improving app and website UI — picks layout, colour palette, font pairing, spacing and component style for the product type, then builds it and checks it on phone and desktop. Use when asked to design, style, restyle or improve the look/usability of an app, dashboard, landing page or screen.
---

# UI/UX Pro Max

You are acting as a senior product designer who also writes production front-end code.
Your job is to make interfaces that look deliberate, are easy to use, and work on a 360px phone
as well as a 1440px desktop. Decide with reasons; never decorate at random.

## 1. Understand before designing
Work out (from the request, the code, or one short question if it is truly unclear):
- **Product type** — e.g. fintech/wallet, e-commerce, SaaS dashboard, admin panel, education,
  health, portfolio, landing page, booking, social, reseller/VTU (data bundles, airtime).
- **Primary user and main task** — what they must do in under 10 seconds on the main screen.
- **Platform** — web (HTML/CSS/JS, PHP views, React, Vue, Tailwind), mobile web / PWA, or native.
- **Constraints** — existing framework, CSS system, brand colours, what must not change
  (form field names, routes, IDs used by scripts).

If there is existing code, read the layouts, CSS and a few key views first. Change nothing yet.

## 2. Choose the design system (write it down as tokens)
Pick one **style**, one **palette**, one **font pairing**, and a **spacing/radius/shadow scale**
that fit the product type. State the choice and the one-line reason for each.

### Style guide by product type
| Product type | Recommended style | Avoid |
|---|---|---|
| Fintech / wallet / reseller | Clean, high-contrast, trust-first; clear numbers; restrained accent | Neon gradients on money figures, playful fonts |
| Admin / dashboard | Dense but calm; neutral surfaces, one accent, strong tables | Big hero images, heavy shadows |
| E-commerce | Product-first, big imagery, bold CTA, sticky cart | Low-contrast price text |
| SaaS landing | Bold headline, one idea per section, social proof, clear pricing | Walls of text, 5 CTAs competing |
| Education | Friendly, readable, generous line-height, progress cues | Tiny type, dark-only themes |
| Health / wellness | Soft, calm palette, lots of whitespace, rounded shapes | Alarm reds except for real errors |
| Creative / portfolio | Editorial type, asymmetric grids, big imagery | Generic template look |
| Gaming / youth | Vivid colour, motion, chunky components | Corporate greys |

### Style vocabulary (pick one, can blend two)
Minimal Swiss · Soft neumorphic-lite · Glassmorphism (use sparingly, keep contrast) ·
Bento grid · Brutalist · Editorial/magazine · Material-inspired · Claymorphism ·
Dark premium · Warm organic · Retro/pixel · Corporate clean.

### Palette rules
- Build tokens: `--bg`, `--surface`, `--surface-2`, `--border`, `--text`, `--text-muted`,
  `--primary`, `--primary-contrast`, `--success`, `--warning`, `--danger`, `--info`, `--focus`.
- One primary accent, one optional secondary. Semantic colours only for meaning.
- Text contrast ≥ 4.5:1 (≥ 3:1 for 18px+ bold). Check every text/background pair you define.
- Provide light and dark versions; dark mode is not just inverted — raise surfaces, lower saturation.
- Starter palettes (adapt, don't copy blindly):
  - Trust blue: primary `#2563EB`, bg `#F8FAFC`, text `#0F172A`
  - Emerald money: primary `#059669`, bg `#F6FBF8`, text `#0B1F17`
  - Warm sunset: primary `#EA580C`, bg `#FFFBF5`, text `#1C1917`
  - Royal violet: primary `#7C3AED`, bg `#FAF8FF`, text `#1E1B2E`
  - Graphite + lime: primary `#84CC16` on `#111315`, text `#F4F4F5`
  - Ocean teal: primary `#0D9488`, bg `#F0FDFA`, text `#042F2E`
  - Crimson bold: primary `#DC2626`, bg `#FFFFFF`, text `#18181B` (use red only if it is the brand)

### Font pairing (Google Fonts, max 2 families, max 4 weights total)
| Mood | Headings | Body |
|---|---|---|
| Modern product | Plus Jakarta Sans | Inter |
| Friendly | Nunito | Nunito Sans |
| Premium | Sora | Manrope |
| Editorial | Fraunces | Source Sans 3 |
| Tech / data | Space Grotesk | IBM Plex Sans (+ IBM Plex Mono for numbers) |
| Bold marketing | Outfit | DM Sans |
| Classic trust | Lora | Open Sans |
| Geometric clean | Poppins | Poppins |
Numbers in tables/wallets: use `font-variant-numeric: tabular-nums`.

### Scales
- Type: 12 / 14 / 16 (body) / 18 / 20 / 24 / 30 / 36 / 48. Body line-height 1.5–1.65; headings 1.1–1.25.
- Spacing: 4px base → 4, 8, 12, 16, 24, 32, 48, 64.
- Radius: pick one family (sharp 4–6px, soft 10–14px, or pill 999px for chips/buttons) and keep it.
- Shadow: at most 3 levels; subtle on light, replaced by borders/raised surfaces on dark.

## 3. Layout patterns
- **Mobile first.** Design the 360px layout first, then widen.
- App shell: top bar + collapsible sidebar on desktop; bottom tab bar (max 5 items) on phones.
- Dashboard: KPI cards row → primary action → recent activity table/list.
- Forms: one column on phone, labels above inputs, 44px min touch targets, inline validation,
  primary button full-width on phone.
- Tables: on phones turn rows into cards or allow horizontal scroll inside the table only
  (never the whole page). Keep the key column (e.g. status, amount) visible.
- Max content width 1200–1280px; readable text blocks ≤ 70ch.
- Use a 12-column grid on desktop, 4-column on phone; consistent gutters (16px phone, 24px desktop).

## 4. UX rules (checklist — apply all)
- Every screen has **one obvious primary action**.
- States for everything: loading (skeletons), empty (explain + action), error (what happened + fix),
  success (confirm + next step), disabled (explain why).
- Feedback within 100ms of any tap; spinners inside the button that was pressed; prevent double submit.
- Destructive actions: confirm, name the thing being deleted, offer undo where possible.
- Money and phone numbers: format clearly (`₵ 1,250.00`, `024 123 4567`), right-align amounts in tables.
- Status badges: colour **and** text/icon (never colour alone).
- Accessibility: visible focus ring, semantic HTML, `alt` text, labels tied to inputs, `aria-live`
  for toasts, respects `prefers-reduced-motion`, keyboard reachable menus/modals.
- Motion: 150–250ms ease-out for UI, no bouncing on data, no auto-playing carousels for key info.
- Icons: one set only (Lucide, Tabler, Phosphor or Heroicons), same stroke width, 20–24px.
- No emojis as UI icons in professional products.

## 5. Build
- Put tokens in one place (`:root` CSS variables or Tailwind config) and use them everywhere.
- Keep existing logic intact: don't rename form fields, routes, IDs or JS hooks unless asked.
- For existing projects, deliver changed files in a separate folder with a short INSTALL note
  unless the user says to edit in place.
- Reuse components: button, input, select, card, badge, table, modal, toast, tabs, empty state.

## 6. Verify (always do this before handing over)
- Render key screens at **360px, 768px and 1440px** (Playwright/Chromium screenshots if available)
  and look at them. Fix overflow, cramped spacing, clipped text, horizontal page scroll.
- Check light and dark mode.
- Run a contrast check on the palette pairs.
- Tab through a form and a modal with the keyboard.
- Report: the chosen style/palette/fonts (with reasons), what changed, screenshots, anything left.

## 7. When asked for options
Offer **3–5 clearly different directions** (style + palette + fonts + one mock screen each),
show them side by side, and wait for the user's pick before building everything.

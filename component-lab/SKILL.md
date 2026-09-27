---
name: component-lab
description: Builds polished, modern, copy-paste UI components and page sections (hero, pricing, navbar, cards, tables, modals, forms, footers, dashboards) in the user's stack — React/Tailwind, plain HTML/CSS/JS or PHP views — with variants and a live preview. Use when asked for a component, section, UI block or "nice looking" piece of interface.
---

# Component Lab

You build components the way a top component marketplace would: modern, accessible, responsive,
with sensible variants, and ready to paste into the user's project.

## 1. Match the stack
Detect or ask (only if unclear) which output is wanted:
- **React + Tailwind** (default for new React/Next.js projects; shadcn/ui-style API: `variant`, `size`,
  `className` merge, `forwardRef`).
- **Plain HTML + CSS + vanilla JS** (default for PHP sites and static pages; CSS variables, no build step).
- **Vue / Svelte** if the project uses them.
Follow the project's existing tokens, class naming and icon set if there is one.

## 2. Component catalogue (know these patterns)
- **Navigation:** sticky navbar with mobile drawer, sidebar with collapsible groups, bottom tab bar,
  breadcrumbs, command palette (Ctrl/⌘ K).
- **Marketing sections:** hero (split, centered, with product mock), logo cloud, feature grid / bento,
  how-it-works steps, testimonials, pricing (monthly/yearly toggle), FAQ accordion, CTA banner,
  newsletter, footer with columns.
- **App UI:** stat/KPI cards with trend, data table (sort, search, pagination, row actions, empty state,
  mobile card view), filters bar, tabs, segmented control, stepper/wizard, timeline/activity feed,
  file upload dropzone, avatar group, notification dropdown, profile menu.
- **Forms:** input with label/hint/error, password with show/hide + strength, phone input with network
  detection, OTP input, select/combobox, toggle, checkbox cards, radio cards (great for picking bundles
  or plans), date picker, amount input with currency.
- **Feedback & overlays:** modal/dialog, drawer/sheet, toast stack, alert/banner, tooltip, popover,
  skeleton loaders, progress bar, spinner-in-button, confirm dialog.
- **Commerce:** product card, bundle/plan card with "Popular" badge, cart drawer, checkout summary,
  order status tracker, receipt.
- **Fancy (use with restraint):** animated gradient border, spotlight/glow card on hover, marquee,
  number count-up, shimmer text, subtle parallax, dotted/grid backgrounds.

## 3. Quality bar for every component
- **Responsive** from 360px up; no horizontal page scroll.
- **Accessible:** semantic elements (`button`, `nav`, `dialog`), labels, `aria-*` where needed,
  focus-visible ring, keyboard support (Esc closes, Tab trapped in modals, arrow keys in menus/tabs),
  contrast ≥ 4.5:1, respects `prefers-reduced-motion`.
- **Themeable:** colours/radius/spacing from CSS variables or Tailwind theme — no hard-coded brand hex
  scattered around. Light and dark mode.
- **States:** default, hover, active, focus, disabled, loading, empty, error.
- **Variants:** at least size (`sm`/`md`/`lg`) and style (`primary`/`secondary`/`ghost`/`destructive`)
  where it makes sense.
- **No heavy dependencies** unless the project already uses them. Icons: Lucide (or the project's set).
- Realistic sample content (not lorem ipsum), e.g. real-looking bundle names and ₵ prices for a data site.

## 4. Deliver
1. A short note: what the component is, its props/variants, and where to put it.
2. The code in files (e.g. `components/PricingSection.tsx`, or `partials/pricing.php` + `assets/css/pricing.css`
   + `assets/js/pricing.js`). Keep one component per file.
3. A **live preview page** (single HTML file showing all variants and states, light + dark) — render it
   and screenshot at 360px and 1280px to check it before handing over. Publish/share the preview if the
   environment supports it.
4. Usage example showing how to drop it into the user's page.

## 5. When the user wants choices
Show **3 variants** of the component side by side in one preview (e.g. minimal / bold / glassy) and let
them pick before integrating it into their project.

## 6. Integrating into an existing site
- Don't change form field names, routes, IDs or script hooks.
- Put new CSS in its own file or scoped class prefix (e.g. `.cl-`), so it can't break existing styles.
- For live sites, deliver into a separate folder with an INSTALL note unless told to edit in place.

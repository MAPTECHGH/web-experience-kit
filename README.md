# Web Experience Kit — Site Redesign skill for Claude

A Claude skill that gives an old, plain website a full modern makeover — **safely**.
Built for Ghana data-bundle reseller sites (custom PHP MVC), but works for similar PHP sites.

It was made while redesigning **[maptechgh.com](https://www.maptechgh.com)** ("Midnight" theme).

<p>
  <img src="preview/01-cover.jpg" width="32%" alt="Midnight redesign cover">
  <img src="preview/02-before-after.jpg" width="32%" alt="Before and after">
  <img src="preview/04-buy-data.jpg" width="32%" alt="Buy data screen">
</p>

## What it does

1. **Looks around first** – maps your pages, layouts, CSS, fonts and icons. Changes nothing.
2. **Shows you 5 different design directions** in one preview page (dark premium, clean light,
   bold colourful, minimal, glassy…) with your dashboard, Buy Data screen and a phone view —
   then **waits for you to pick** (or mix: “take 2 and 4”).
3. **Applies your choice in stages** – layouts & dashboard → buy data, wallet, history →
   admin & shops → the rest.
4. Offers a set of **promo images** at the end so you can advertise the new look.

**Safety rules it follows**
- Visual changes only: form names, routes, CSRF tokens, scripts and controllers stay the same.
- Never overwrites your live files – every stage goes into its own `_redesign_stageN` folder
  with an `INSTALL.txt` telling you what to copy and what to back up.
- Never reads `.env`, passwords, database dumps, logs or uploads.
- Checks every page on desktop **and** phone before handing it over.

## Install

### Claude app (claude.ai – web, desktop or mobile)
1. Download **[`site-redesign-5-variants.zip`](site-redesign-5-variants.zip)** (click it, then the download button).
2. In Claude, open **Settings** and find **Skills** (under Capabilities / Customize).
3. Choose **Upload skill** and pick the zip. Make sure the skill is switched on.

### Claude Code
```bash
git clone https://github.com/MAPTECHGH/web-experience-kit.git
mkdir -p ~/.claude/skills
cp -r web-experience-kit/site-redesign-5-variants ~/.claude/skills/
```

## Use it

Give Claude your site's source code (attach a zip, or link the folder on your computer) and say:

> Redesign my site with the site-redesign-5-variants skill. Show me the 5 directions first.

Then pick a direction and say **“stage 1”**, **“next”**, and so on.

## Files
- `site-redesign-5-variants/SKILL.md` – the skill itself (plain text, easy to read and edit)
- `site-redesign-5-variants.zip` – same thing, zipped for uploading to Claude
- `preview/` – example images from the maptechgh.com redesign

---
Made by **MAPTECH GLOBAL** · Want your site redesigned for you instead? Visit [maptechgh.com](https://www.maptechgh.com).

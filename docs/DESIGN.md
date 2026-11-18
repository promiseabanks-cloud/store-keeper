# Back-office design

The Store Keeper screen is its own application shell, not a WordPress admin page
with recoloured form fields. This file records the rules so later screens keep
the same shape.

## Structure

```
body.storekeeper-app                 (set by Admin\Menu on this screen only)
└── .sk-root
    └── .sk-shell
        ├── aside.sk-rail            dark rail: brand, section nav, footer links
        └── main.sk-main
            ├── header.sk-topbar     page title, subtitle, live chips
            └── .sk-content          cards, panels, tables, forms
```

- One screen at a time; sections are rail items, not a second tab row.
- The rail carries the way back (`View store`, `WordPress admin`) and the signed-in
  user, so removing the WP admin bar from this screen loses nothing.
- On screens narrower than 900 px the rail becomes a horizontally scrollable
  strip above the content, which keeps the workflow one-handed on a phone.

## Tokens

Defined once on `body.storekeeper-app` in `assets/admin/admin.css`:

| Token | Value | Used for |
|---|---|---|
| `--sk-rail` | `#10131a` | Rail background |
| `--sk-bg` | `#f4f5f9` | Workspace background |
| `--sk-surface` | `#ffffff` | Cards, panels, tables |
| `--sk-line` | `#e6e8ef` | Hairline borders |
| `--sk-ink` / `--sk-muted` | `#151922` / `#6b7280` | Text |
| `--sk-accent` | `#4f46e5` | Primary action, focus ring, active nav |
| `--sk-good` / `--sk-warn` / `--sk-bad` + `-soft` | teal / amber / red | Status pills, alerts, money problems |
| `--sk-radius` | `14px` | Cards, panels, tables |

Typography is the system stack, one scale: page title 26 px (`-0.02em`), panel
title 15 px, body 14 px, table 13.5 px, labels 11.5 px uppercase with `0.07em`
tracking, stat numbers 30 px tabular.

## Rules

1. **Scoped, never global.** Every rule except the `body.storekeeper-app` chrome
   resets lives under `.sk-root`. No other wp-admin screen changes.
2. **No WordPress chrome on this screen.** `#wpadminbar`, `#adminmenumain`,
   `#wpfooter` and WP's own notices are hidden here only, and the 32 px toolbar
   offset is removed (`html.wp-toolbar:has(body.storekeeper-app)`).
3. **One primary action per panel.** Indigo filled button; everything else is a
   quiet outline button; destructive actions are red text on a light border.
4. **Status is a pill, money is tabular.** Never colour a whole row.
5. **Tables are the data view.** Uppercase 11 px headers, 13 px rows, hairline
   separators, hover tint, horizontal scroll on phones.
6. **Empty states explain.** A dashed card with one sentence, not a blank area.
7. **Specificity.** WordPress styles beat single-class selectors, so primary
   button rules are written as `body.storekeeper-app .sk-root .button.button-primary`.

## Files

- `assets/admin/admin.css` — the design system above.
- `assets/admin/admin.js` — React (`wp.element`) rendering the shell and the
  catalogue/order/customer screens.
- `assets/admin/screens.js` — the Shipping, Parcels and Messages screens, exposed
  as `window.storekeeperScreens`; loaded first, rendered by `admin.js`.
- `src/Admin/Menu.php` — body class, asset loading and the mount point.

## Printing

The Parcels screen prints a packing slip or a dispatch sheet. The job is rendered
through `wp.element.createPortal` into a `.sk-print` block on `document.body`,
the app shell is hidden by the print stylesheet, and `window.print()` opens the
OS dialog. There is no PDF renderer, no printer driver and no courier call; the
sheet is paper the staff hand to a rider.

# DESIGN_VillaTF.md
Design System Specification — **Villa TF** (Web App)
Version 1.0 · Light mode only · Prepared by nCode Digital

---

## 0. How to Use This Document (Instructions for AI / Developers)

1. This document is the **single source of truth**. Every value is mandatory. Do not invent, approximate, or substitute any color, font, size, radius, shadow, or spacing value.
2. If a needed value is not listed, derive it **only** from existing tokens (e.g., use the nearest spacing token). Never introduce a new hex color.
3. Use the **token names** (CSS custom properties) in code, never raw hex values inside components.
4. **Light mode only.** Do not generate dark-mode styles, toggles, or `prefers-color-scheme` rules.
5. Every interactive component MUST implement all states defined here: `default`, `hover`, `focus-visible`, `active`, `disabled`, and where applicable `error`, `loading`, `selected`.
6. Mobile-first. Build the smallest breakpoint first, then enhance.
7. Words "MUST", "MUST NOT", "ALWAYS", "NEVER" are absolute rules.

---

## 1. Product Overview

**Villa TF** is a beachfront property with **5 villas**, **camping areas** and **glamping tents**. The web app has two surfaces that share this design system:

| Surface | Audience | Purpose |
|---|---|---|
| **Public Site** (`/`) | Tourists and local guests (mobile-heavy) | Discover villas and spaces, check availability, book and pay online, read blog, find location (local SEO). |
| **Admin Panel** (`/admin`) | Owners and staff (desktop + tablet, occasionally mobile) | Manage reservations, clients, villas/spaces, site content (texts, images, blog) and payments. |

**Design direction:** *Modern coastal elegance.* Warm sand neutrals, deep sea-green as brand color, a sunset terracotta accent used sparingly. Generous whitespace, large photography, refined serif headlines, clean sans-serif UI. Calm, premium, effortless.

**UI language:** Spanish (default) with an English toggle (`ES | EN`) in the public site header. All layouts MUST tolerate English and Spanish text lengths (Spanish is ~20% longer; never use fixed-width text containers).

**Currency:** Prices shown in **USD** as primary (`$180 / noche`), with optional CRC secondary line in `--text-sm` muted color.

---

## 2. Color System

### 2.1 Brand & Neutral Tokens

| Token | Hex | Usage |
|---|---|---|
| `--color-primary-900` | `#0F2E2B` | Footer background, darkest brand text on light surfaces |
| `--color-primary-800` | `#16413D` | Primary button pressed (active) |
| `--color-primary-700` | `#1D5550` | **Primary brand color.** Primary buttons, active nav, links, focus ring base |
| `--color-primary-600` | `#26695F` | Primary button hover |
| `--color-primary-100` | `#DCEBE7` | Selected states, subtle brand backgrounds, active sidebar item bg |
| `--color-primary-50` | `#EEF5F3` | Hover background for ghost elements |
| `--color-accent-600` | `#B85A33` | Accent hover |
| `--color-accent-500` | `#C96B42` | **Accent (sunset terracotta).** Only for: price highlights, "Reservar" CTA in hero, promo badges, star icons. Max 1 accent element per viewport section. |
| `--color-accent-100` | `#F6E3D8` | Accent soft background (promo badge bg) |
| `--color-sand-50` | `#FBF9F5` | **App background** (public site page bg and admin content bg) |
| `--color-sand-100` | `#F5F0E8` | Alternating section background, table header bg |
| `--color-sand-200` | `#EAE2D5` | Skeleton loaders, dividers on sand backgrounds |
| `--color-white` | `#FFFFFF` | Cards, inputs, modals, admin sidebar, top bars |
| `--color-ink-900` | `#1C1A17` | Primary text, headings |
| `--color-ink-700` | `#46423C` | Body text secondary |
| `--color-ink-500` | `#7A746B` | Muted text, placeholders, helper text, icons default |
| `--color-ink-300` | `#C9C2B6` | Disabled text, disabled icons |
| `--color-border` | `#E4DDD1` | Default borders (inputs, cards, tables) |
| `--color-border-strong` | `#CFC6B7` | Input hover border |

### 2.2 Semantic Tokens

| Token | Text / Icon | Background | Border |
|---|---|---|---|
| Success | `--color-success-700: #2F6B3F` | `--color-success-50: #E8F3EA` | `--color-success-200: #BFDDC6` |
| Warning | `--color-warning-700: #8A5A0B` | `--color-warning-50: #FBF1DC` | `--color-warning-200: #EED29A` |
| Error | `--color-error-700: #A8322A` | `--color-error-50: #FBE9E7` | `--color-error-200: #F0BDB7` |
| Info | `--color-info-700: #2A5C8A` | `--color-info-50: #E7F0F8` | `--color-info-200: #BCD3E8` |

### 2.3 Reservation Status Colors (fixed mapping — MUST NOT change)

| Status (ES / EN) | Key | Text | Background | Dot |
|---|---|---|---|---|
| Pendiente / Pending | `pending` | `#8A5A0B` | `#FBF1DC` | `#D99A1E` |
| Confirmada / Confirmed | `confirmed` | `#1D5550` | `#DCEBE7` | `#1D5550` |
| Pagada / Paid | `paid` | `#2F6B3F` | `#E8F3EA` | `#3E8A52` |
| En estadía / Checked-in | `checked_in` | `#2A5C8A` | `#E7F0F8` | `#3B78B0` |
| Finalizada / Completed | `completed` | `#46423C` | `#F5F0E8` | `#7A746B` |
| Cancelada / Cancelled | `cancelled` | `#A8322A` | `#FBE9E7` | `#C9473D` |
| No se presentó / No-show | `no_show` | `#6B3A5C` | `#F4E8F0` | `#9B5686` |

The same status color MUST be used in badges, calendar blocks, list rows, and charts.

### 2.4 Space Type Colors (for calendar legends and filters)

| Type | Color |
|---|---|
| Villa | `#1D5550` (primary-700) |
| Glamping | `#C96B42` (accent-500) |
| Camping | `#6E7F3A` |

### 2.5 Color Rules

- Text on `--color-sand-50` and `--color-white` MUST use `--color-ink-900` or `--color-ink-700` (contrast ≥ 4.5:1).
- Text on `--color-primary-700` and darker MUST be `--color-white`.
- Text on `--color-accent-500` MUST be `--color-white` and at least 16px semibold (large-text contrast).
- Overlay on photos for text legibility: `linear-gradient(180deg, rgba(15,46,43,0) 40%, rgba(15,46,43,0.65) 100%)`.
- NEVER use pure black `#000000` or gradients as decorative backgrounds.

---

## 3. Typography

### 3.1 Font Families (Google Fonts)

| Token | Family | Weights | Usage |
|---|---|---|---|
| `--font-display` | **Fraunces** (variable, `opsz` auto) | 400, 500, 600 | Public site headlines (H1–H3), villa names, pull quotes, blog titles |
| `--font-sans` | **Manrope** | 400, 500, 600, 700 | All UI: body, buttons, inputs, labels, tables, navigation. **Admin panel uses ONLY Manrope**, including headings. |

Fallbacks: `Fraunces, Georgia, serif` · `Manrope, system-ui, -apple-system, "Segoe UI", sans-serif`.
Load with `font-display: swap`. Enable `font-feature-settings: "tnum"` on numbers in tables, prices, and dates.

### 3.2 Type Scale

| Token | Size / Line height | Mobile size | Family | Weight | Letter-spacing |
|---|---|---|---|---|---|
| `--text-display` | 64px / 1.05 | 40px / 1.1 | Fraunces | 500 | -0.02em |
| `--text-h1` | 48px / 1.1 | 34px / 1.15 | Fraunces | 500 | -0.015em |
| `--text-h2` | 36px / 1.15 | 28px / 1.2 | Fraunces | 500 | -0.01em |
| `--text-h3` | 24px / 1.3 | 22px / 1.3 | Fraunces | 500 | 0 |
| `--text-h4` | 20px / 1.4 | 18px / 1.4 | Manrope | 600 | 0 |
| `--text-lg` | 18px / 1.6 | 17px / 1.6 | Manrope | 400 | 0 |
| `--text-base` | 16px / 1.6 | 16px / 1.6 | Manrope | 400 | 0 |
| `--text-sm` | 14px / 1.5 | 14px / 1.5 | Manrope | 400/500 | 0 |
| `--text-xs` | 12px / 1.4 | 12px / 1.4 | Manrope | 500 | 0.01em |
| `--text-overline` | 12px / 1.4 | 12px / 1.4 | Manrope | 600 | 0.12em, UPPERCASE |

**Admin heading scale (Manrope only):** Page title 24px/600, Section title 18px/600, Card title 16px/600.

### 3.3 Typography Rules
- Body text max line length: **68ch**.
- Overline text (e.g., "VILLAS FRENTE AL MAR") always sits 12px above a Fraunces heading, color `--color-primary-700`.
- NEVER use Fraunces below 20px. NEVER use font weights 800/900. NEVER use italic except Fraunces italic in testimonials.

---

## 4. Spacing, Layout & Grid

### 4.1 Spacing Scale (4px base)
`--space-1: 4px` · `--space-2: 8px` · `--space-3: 12px` · `--space-4: 16px` · `--space-5: 20px` · `--space-6: 24px` · `--space-8: 32px` · `--space-10: 40px` · `--space-12: 48px` · `--space-16: 64px` · `--space-20: 80px` · `--space-24: 96px` · `--space-32: 128px`

### 4.2 Breakpoints

| Name | Min width | Columns | Gutter | Side margin |
|---|---|---|---|---|
| `xs` | 0 | 4 | 16px | 16px |
| `sm` | 640px | 8 | 20px | 24px |
| `md` | 768px | 8 | 24px | 32px |
| `lg` | 1024px | 12 | 24px | 40px |
| `xl` | 1280px | 12 | 32px | auto (container 1200px) |
| `2xl` | 1536px | 12 | 32px | auto (container 1320px) |

### 4.3 Section Rhythm (Public Site)
- Vertical padding per section: **96px** desktop (`lg+`), **64px** tablet, **48px** mobile.
- Alternate section backgrounds: `--color-sand-50` → `--color-white` → `--color-sand-50`. Never two identical consecutive backgrounds except hero → first section.

### 4.4 Admin Layout
- Sidebar width: **264px** expanded, **72px** collapsed (icons only, tooltip on hover). Below `lg` the sidebar becomes an off-canvas drawer (width 288px) opened from a hamburger in the top bar.
- Top bar height: **64px**, white, bottom border `1px solid --color-border`.
- Content area: background `--color-sand-50`, padding 32px (`lg+`), 24px (`md`), 16px (`xs`). Max content width 1440px.

---

## 5. Radius, Borders, Shadows, Motion

### 5.1 Radius
`--radius-xs: 6px` (badges, checkboxes) · `--radius-sm: 10px` (buttons, inputs, selects) · `--radius-md: 14px` (cards, dropdown menus, toasts) · `--radius-lg: 20px` (modals, booking widget, image cards) · `--radius-xl: 28px` (hero search bar container, large feature images) · `--radius-full: 9999px` (pills, avatars, chips, toggle).

### 5.2 Borders
Default: `1px solid var(--color-border)`. Focus never uses border thickness change (use ring, see 5.4).

### 5.3 Shadows (warm-tinted, never pure gray)
- `--shadow-xs`: `0 1px 2px rgba(28,26,23,0.06)` — inputs, small cards
- `--shadow-sm`: `0 2px 8px rgba(28,26,23,0.06), 0 1px 2px rgba(28,26,23,0.04)` — cards default
- `--shadow-md`: `0 8px 24px rgba(28,26,23,0.08), 0 2px 6px rgba(28,26,23,0.04)` — card hover, dropdowns, sticky booking widget
- `--shadow-lg`: `0 24px 48px rgba(28,26,23,0.12), 0 4px 12px rgba(28,26,23,0.06)` — modals, drawers

### 5.4 Focus Ring (ALL interactive elements)
`outline: none; box-shadow: 0 0 0 2px var(--color-white), 0 0 0 4px var(--color-primary-700);` Applied only on `:focus-visible`.

### 5.5 Motion
- `--ease-standard: cubic-bezier(0.2, 0, 0, 1)`
- `--duration-fast: 120ms` (hover/color), `--duration-base: 200ms` (dropdowns, toggles), `--duration-slow: 320ms` (modals, drawers, image reveal).
- Card hover: `translateY(-2px)` + `--shadow-md`. Image hover inside cards: `scale(1.03)` over 320ms, image container `overflow: hidden`.
- Scroll reveal (public site only): fade-in + `translateY(16px → 0)`, 320ms, once per element.
- MUST respect `prefers-reduced-motion: reduce` → disable transforms and reveals, keep color transitions only.

---

## 6. Iconography & Imagery

### 6.1 Icons
- Library: **Lucide** only. Stroke width **1.75px**. Sizes: 16px (inline/sm), 20px (default UI), 24px (nav, feature lists).
- Default color `--color-ink-500`; inside buttons inherit text color.
- Amenity icons (public): `BedDouble`, `Users`, `Bath`, `Wifi`, `Waves`, `Car`, `Snowflake`, `CookingPot`, `Tent`, `Flame`, `PawPrint`, `TreePalm`.

### 6.2 Photography (professional photos are a core asset)
- Required aspect ratios: Hero **16:9** desktop / **4:5** mobile · Space card **4:3** · Gallery: 1 large **3:2** + 4 small **1:1** grid · Blog cover **16:9** · Admin thumbnails **1:1**.
- All images: `object-fit: cover`, lazy-loaded (except hero), served as WebP/AVIF with `srcset`, explicit `width`/`height` to avoid layout shift.
- Image corners follow their container radius (cards `--radius-lg` top corners only when inside card; standalone feature images `--radius-xl`).
- Loading placeholder: `--color-sand-200` block with blurred low-quality preview.
- Every image MUST have descriptive `alt` text in the active language (e.g., "Villa Coral con piscina privada frente al mar").
- NEVER apply color filters, heavy overlays (except the defined text gradient), or borders to photos.

### 6.3 Logo (placeholder until brand is delivered)
Wordmark "Villa TF" in Fraunces 600, 24px, `--color-primary-900`, letter-spacing -0.01em, preceded by a 20px Lucide `TreePalm` icon in `--color-primary-700`. On dark/photo backgrounds use `--color-white`.

---

## 7. Components

> All sizes are exact. Height values include borders. Touch targets MUST be ≥ 44×44px on touch devices.

### 7.1 Buttons

**Sizes**

| Size | Height | Padding X | Font | Icon | Gap |
|---|---|---|---|---|---|
| `sm` | 36px | 14px | 14px / 600 | 16px | 6px |
| `md` (default) | 44px | 20px | 15px / 600 | 18px | 8px |
| `lg` | 52px | 28px | 16px / 600 | 20px | 10px |

Font: Manrope, letter-spacing 0.005em, no uppercase. Radius `--radius-sm`. Transition: background/color/border/shadow `--duration-fast`.

**Variants**

| Variant | Default | Hover | Active | Disabled |
|---|---|---|---|---|
| **Primary** | bg `primary-700`, text white | bg `primary-600` | bg `primary-800` | bg `sand-200`, text `ink-300`, no shadow |
| **Accent** (only "Reservar ahora" / main booking CTA on public site) | bg `accent-500`, text white | bg `accent-600` | bg `accent-600`, `translateY(1px)` | same as primary disabled |
| **Secondary** | bg white, border `1px border-strong`, text `ink-900` | bg `sand-50`, border `ink-500` | bg `sand-100` | border `border`, text `ink-300` |
| **Ghost** | transparent, text `primary-700` | bg `primary-50` | bg `primary-100` | text `ink-300` |
| **Destructive** | bg `error-700`, text white | bg `#922920` | bg `#7C221B` | same as primary disabled |
| **Link** | text `primary-700`, underline offset 3px on hover only, no padding, height auto | text `primary-600` + underline | text `primary-800` | text `ink-300` |
| **Icon button** | square 40px (`sm`: 32px), radius `--radius-sm`, transparent, icon `ink-700` | bg `sand-100` | bg `sand-200` | icon `ink-300` |

**Loading state:** text replaced by a 16px spinner (2px stroke, current color) centered + keep button width fixed (no layout shift); `aria-busy="true"`, non-clickable.
**Rules:** Max **one** Primary or Accent button per view section. Destructive actions MUST open a confirmation modal. Full-width buttons on mobile (`xs`) for forms and booking flow.

### 7.2 Text Inputs

- Height **48px** (public site and admin forms), **40px** for admin table filters/search.
- Padding: 0 16px. Font: 16px Manrope 400 (16px minimum to prevent iOS zoom). Text `ink-900`, placeholder `ink-500`.
- Background `white`, border `1px solid --color-border`, radius `--radius-sm`, shadow `--shadow-xs`.
- **Label:** above the field, 14px / 600, `ink-900`, margin-bottom 8px. Required fields: label followed by ` *` in `error-700`. Labels are ALWAYS visible (NEVER floating labels, NEVER placeholder-as-label).
- **Helper text:** below, 13px / 400, `ink-500`, margin-top 6px.
- **States:**
  - Hover: border `border-strong`.
  - Focus: border `primary-700` + ring `0 0 0 3px rgba(29,85,80,0.15)`.
  - Error: border `error-700`, ring on focus `0 0 0 3px rgba(168,50,42,0.15)`, helper replaced by error message in `error-700` with 16px `CircleAlert` icon. Validate on blur, not on keystroke.
  - Disabled: bg `sand-100`, text `ink-300`, border `border`, cursor `not-allowed`.
  - Read-only: bg `sand-50`, no border change on hover.
- Leading/trailing icon: 20px, `ink-500`, 12px from edge; text padding increases to 44px on that side.
- **Textarea:** min-height 120px, padding 12px 16px, `resize: vertical`, character counter bottom-right 12px `ink-500` when a max exists.
- **Phone input:** country selector (flag + code, default 🇨🇷 +506) attached on the left, 1px divider.

### 7.3 Select / Dropdown

- Trigger identical to text input (48px), trailing `ChevronDown` 20px `ink-500`, rotates 180° when open.
- Menu: bg white, radius `--radius-md`, shadow `--shadow-md`, border `1px border`, padding 6px, max-height 320px with scroll, offset 6px from trigger, min-width = trigger width.
- Option: height 40px, padding 0 12px, radius 8px, 15px text. Hover/keyboard-active: bg `sand-100`. Selected: text `primary-700` 600 + trailing `Check` 18px.
- Searchable select (combobox) when > 8 options: search input at top of menu, 40px.
- Multi-select: selected values shown as chips inside trigger (max 2 visible + "+N").
- On mobile (`xs`) selects with ≤ 8 options open as a **bottom sheet** (radius 20px top corners, drag handle 36×4px `sand-200`).

### 7.4 Checkbox, Radio, Toggle

- **Checkbox:** 20px, radius `--radius-xs`, border `1.5px border-strong`, bg white. Checked: bg `primary-700`, white `Check` 14px. Indeterminate: white 10×2px bar. Label 15px `ink-900`, 10px gap. Clickable area includes label.
- **Radio:** 20px circle, border `1.5px border-strong`. Checked: border `primary-700`, inner dot 10px `primary-700`.
- **Radio Card** (used for choosing space type, payment option): white card, border `1px border`, radius `--radius-md`, padding 16px. Selected: border `2px primary-700`, bg `primary-50`.
- **Toggle switch:** track 40×24px, radius full. Off: track `sand-200`, thumb white 20px with `--shadow-xs`. On: track `primary-700`. Transition 200ms.

### 7.5 Date Range Picker (critical for bookings)

- Trigger on public site: two-part field "Llegada | Salida" inside one container, 56px height, divider 1px, each part shows overline label (12px `ink-500`) + date (15px/600 `ink-900`), e.g., "LLEGADA / vie 14 nov".
- Popover: 2 months side-by-side on `md+`, 1 month vertical scroll on `xs` (opens as full-screen sheet with sticky footer "Limpiar" ghost + "Aplicar" primary).
- Day cell: 44×44px, 15px, radius full.
  - Available: `ink-900`, hover bg `sand-100`.
  - Start/End selected: bg `primary-700`, text white.
  - In range: bg `primary-100`, square edges (connect cells), text `primary-900`.
  - Today: 600 weight + 4px dot below in `accent-500`.
  - Unavailable/booked: text `ink-300` with diagonal strike-through line, not clickable, tooltip "No disponible".
  - Past: `ink-300`, not clickable.
- Below each available day (public, when space is selected) optional price in 11px `ink-500` (e.g., "$180").
- Minimum stay message shown under calendar: 13px `ink-700` with `Info` icon.

### 7.6 Guest Counter

Row per type ("Adultos", "Niños 2–12", "Bebés"): label 15px/600 + helper 13px `ink-500` left; right: circular icon buttons 36px (border `1px border-strong`, icon `Minus`/`Plus` 16px) with value 16px/600 centered, 32px wide. Disabled at min/max. Max capacity of selected space enforced with helper "Capacidad máxima: 6 personas".

### 7.7 Cards

**Base card:** bg white, radius `--radius-lg`, border `1px border`, shadow `--shadow-sm`, padding 24px (20px on `xs`).

**Space Card (public: villas, glamping, camping)**
- Image 4:3 on top (radius `--radius-lg` top corners), favorite-free (no heart icon).
- Badge over image top-left 12px offset: space type pill (white bg 90% opacity, `backdrop-filter: blur(8px)`, 12px/600 `ink-900`).
- Body padding 20px: name (Fraunces 22px/500), meta row (icons 16px + 14px `ink-700`: "6 huéspedes · 3 habitaciones · 2 baños"), divider 16px gap, price row: "Desde **$180** / noche" (price 20px/700 `ink-900`, rest 14px `ink-500`) + Ghost button "Ver detalles" with `ArrowRight`.
- Entire card is clickable (single link wrapping), hover: `translateY(-2px)`, `--shadow-md`, image scale 1.03.
- Grid: 1 col `xs`, 2 col `md`, 3 col `lg+`, gap 24px (32px on `xl`).

**Stat Card (admin dashboard)**
- Padding 20px, label 14px/500 `ink-500` with 20px icon in a 36px rounded square (`primary-50` bg, `primary-700` icon) top-right; value 32px/700 Manrope `ink-900` tabular; delta row 13px: up `success-700` with `TrendingUp`, down `error-700` with `TrendingDown`, comparison text `ink-500` ("vs. mes anterior").

### 7.8 Badges, Chips & Tags

- **Status badge:** height 24px, padding 0 10px, radius full, 12px/600, 6px dot left (colors from §2.3). Text never uppercase.
- **Type tag:** height 24px, radius `--radius-xs`, bg `sand-100`, text `ink-700` 12px/600.
- **Filter chip:** height 36px, padding 0 14px, radius full, border `1px border`, 14px/500 `ink-700`. Selected: bg `primary-700`, text white, border `primary-700`, optional leading `Check` 16px. Removable chip shows trailing `X` 14px.
- **Promo badge:** bg `accent-100`, text `accent-600`, 12px/700.

### 7.9 Tables (Admin)

- Container: white card, radius `--radius-md`, border `1px border`, overflow hidden.
- Toolbar above table (inside card, padding 16px): search input 40px (left, max-width 320px, `Search` icon) + filter chips/selects + right-aligned actions (secondary "Exportar", primary "Nueva reserva").
- Header row: height 44px, bg `sand-100`, 12px/600 `ink-500`, letter-spacing 0.02em, sticky on scroll. Sortable columns show `ArrowUpDown` 14px; active sort `ArrowUp`/`ArrowDown` in `primary-700`.
- Body rows: height 56px, 14px `ink-900`, border-bottom `1px border`, hover bg `sand-50`, selected bg `primary-50`. Numbers and dates right-aligned and tabular.
- First column: avatar/thumbnail 32px + primary text 14px/600 + secondary text 13px `ink-500`.
- Row actions: last column, icon button `MoreHorizontal` opening a dropdown (Ver, Editar, Cancelar reserva in `error-700`).
- Pagination footer: 52px, "Mostrando 1–20 de 134" left (13px `ink-500`), page buttons right (32px square, radius 8px, current page bg `primary-700` white text).
- **Responsive:** below `md`, tables transform into a **stacked card list** (each row = card with key fields, status badge top-right, chevron right). NEVER use horizontal-scroll tables on mobile.
- Empty state inside table (see 7.15).

### 7.10 Navigation

**Public Header**
- Height 72px (64px `xs`). Transparent over hero with white logo/links; after 24px scroll becomes white bg with `--shadow-xs`, `ink-900` text, transition 200ms. Sticky.
- Desktop: logo left · center links (Villas, Glamping, Camping, Experiencias, Blog, Contacto) 15px/500 with 32px gap, active link `primary-700` + 2px underline 6px below · right: `ES | EN` toggle (13px/600) + Accent button `md` "Reservar".
- Mobile: logo left, right "Reservar" accent `sm` + menu icon button. Menu opens full-screen overlay (white), links in Fraunces 28px stacked, 24px gap, language toggle and contact info at bottom.

**Admin Sidebar**
- White bg, right border `1px border`, padding 16px 12px.
- Top: logo (24px) + collapse icon button.
- Nav items: height 44px, padding 0 12px, radius `--radius-sm`, icon 20px + label 15px/500 `ink-700`, 12px gap. Hover bg `sand-100`. Active: bg `primary-100`, text & icon `primary-700`, weight 600, 3px left indicator bar `primary-700` (rounded).
- Sections with overline group labels (12px/600 `ink-500`, uppercase, letter-spacing 0.08em, 24px top margin):
  - **GENERAL:** Panel (`LayoutDashboard`), Calendario (`CalendarDays`), Reservas (`CalendarCheck`), Clientes (`Users`)
  - **PROPIEDAD:** Villas y espacios (`House`), Tarifas y temporadas (`Tag`)
  - **SITIO WEB:** Contenido (`FileText`), Galería (`Images`), Blog (`PenLine`)
  - **FINANZAS:** Pagos (`CreditCard`)
  - Bottom (pinned): Ayuda y guías (`LifeBuoy`), Configuración (`Settings`), user block (avatar 32px + name 14px/600 + role 12px `ink-500` + `ChevronsUpDown`).
- Badge counts on items (e.g., Reservas pending): pill 20px height, bg `accent-500`, white 11px/700.

**Admin Top Bar:** left: page title (24px/600) or breadcrumbs (14px, `ink-500`, separators `ChevronRight` 14px, current `ink-900` 600); right: global search (command palette trigger, 40px, "Buscar… ⌘K"), notifications icon button with 8px `accent-500` dot, primary `sm` "Nueva reserva".

**Tabs:** height 44px, 15px/500 `ink-500`, active `ink-900` 600 with 2px bottom indicator `primary-700`, bottom border `1px border` across container, 24px gap. On `xs` tabs scroll horizontally with fade edge.

**Segmented control** (e.g., calendar Month/Week/List): container `sand-100`, radius `--radius-sm`, padding 4px; segment height 32px, 14px/500; selected segment white bg, `--shadow-xs`, `ink-900` 600.

### 7.11 Modals, Drawers, Sheets

- Overlay: `rgba(28,26,23,0.45)` + `backdrop-filter: blur(2px)`.
- **Modal:** white, radius `--radius-lg`, shadow `--shadow-lg`, widths: sm 440px, md 560px, lg 760px. Header padding 24px 24px 0: title 20px/600 Manrope + close icon button top-right. Body padding 24px. Footer padding 16px 24px 24px, right-aligned buttons, 12px gap (secondary left of primary). Enter animation: fade + `scale(0.98 → 1)` 320ms.
- **Confirmation modal (destructive):** sm width, 48px circle icon `error-50` bg with `TriangleAlert` `error-700`, title, explanation text, buttons "Cancelar" (secondary) + destructive action label with explicit verb ("Cancelar reserva").
- **Drawer (admin detail panels, e.g., reservation detail quick view):** right side, width 520px (`lg+`), full width on `xs`, slides in 320ms.
- On `xs` all modals become **bottom sheets** (full width, radius 20px top, max-height 92vh, sticky footer).
- Focus trapped inside; `Esc` closes (except during payment processing).

### 7.12 Toasts & Alerts

- **Toast:** bottom-right desktop (24px offset), top-center mobile; width 380px (`xs`: calc(100% − 32px)); white bg, radius `--radius-md`, shadow `--shadow-md`, left 4px color bar (semantic), icon 20px, title 14px/600, message 14px `ink-700`, optional action link, close `X`. Auto-dismiss 5s (errors persist until closed). Max 3 stacked, 8px gap.
- **Inline alert/banner:** radius `--radius-md`, padding 14px 16px, semantic bg + border (§2.2), icon 20px semantic, text 14px `ink-900`.

### 7.13 Loading & Skeletons
- Skeleton blocks: `--color-sand-200`, radius matching the final element, shimmer gradient `linear-gradient(90deg, #EAE2D5 0%, #F5F0E8 50%, #EAE2D5 100%)` 1.4s infinite.
- Use skeletons for lists, cards, tables, calendar. Use button spinner for actions. NEVER use full-page spinners.

### 7.14 Avatars
Sizes 24/32/40/56px, radius full. Fallback: initials (first + last), 600 weight, bg `primary-100`, text `primary-700`.

### 7.15 Empty States
Centered in container, padding 48px: 56px circle `sand-100` with 24px Lucide icon `ink-500`, title 16px/600, description 14px `ink-500` max 40ch, one primary or secondary button. Example: "Aún no hay reservas para estas fechas" + "Crear reserva".

### 7.16 Help & Onboarding Components (supports staff training)
- **Contextual help tooltip:** `CircleHelp` 16px `ink-500` next to labels/titles; tooltip bg `ink-900`, text white 13px, radius 8px, padding 8px 10px, max-width 260px, arrow 6px, delay 300ms.
- **First-use guided tour:** spotlight overlay highlighting one element at a time (cutout radius matches element + 8px padding), popover card 320px with step counter "2 de 5", title, text, "Omitir" ghost + "Siguiente" primary `sm`.
- **Setup checklist card** on Admin dashboard until completed: progress bar (8px, radius full, track `sand-200`, fill `primary-700`), items with check circles (completed: `success-700`).
- **"Ayuda y guías" page:** searchable list of short guides (card per guide with icon, title, 2-line description, "3 min" read time tag) + embedded video cards 16:9.

---

## 8. Public Site — Page Specifications

### 8.1 Home (`/`)
1. **Hero:** full-bleed photo (height 92vh desktop, 88vh mobile, min 560px) with bottom gradient overlay (§2.5). Content bottom-left aligned within container, 96px from bottom (64px mobile): overline white "FRENTE AL MAR · [LOCATION], COSTA RICA", Display headline white Fraunces (max 14ch), subtitle 18px white 90% opacity (max 48ch).
2. **Search bar (booking widget)** overlapping hero bottom edge by 50% of its height: white, radius `--radius-xl`, shadow `--shadow-lg`, padding 8px, height 72px desktop. Segments: "Tipo de espacio" (select: Todos, Villas, Glamping, Camping) · "Llegada" · "Salida" · "Huéspedes" — each segment has overline label 12px `ink-500` + value 15px/600; 1px dividers; final Accent `lg` button "Buscar disponibilidad" with `Search` icon. On `xs`: stacked card (radius 20px) below hero text, each field full width 56px, button full width.
3. **Spaces intro:** overline + H2 "Elige tu forma de vivir la playa" + 3 large category cards (Villas / Glamping / Camping), image 4:5, title over image bottom with gradient, "Ver opciones →".
4. **Featured villas:** Space Card grid (§7.7).
5. **Experience / amenities band:** `sand-100` bg, 4-column icon features (icon 24px in 48px circle `primary-50`), title 16px/600, text 14px `ink-700`.
6. **Gallery strip:** asymmetric grid (1 large 3:2 + 4 small 1:1), click opens lightbox (dark overlay `rgba(15,46,43,0.95)`, arrows, counter, swipe on mobile).
7. **Testimonials:** Fraunces italic 24px quote, guest name 14px/600, country 13px `ink-500`, 5 stars `accent-500`. Carousel with dots on mobile, 3 columns `lg+`.
8. **Location (local SEO block):** split layout — left: H2 "Cómo llegar", full address (NAP: name, address, phone identical everywhere), driving times from nearby cities as list with `Car` icon, WhatsApp & phone buttons; right: embedded map, radius `--radius-xl`, height 420px.
9. **Blog preview:** 3 latest posts.
10. **Final CTA:** primary-900 bg, white Fraunces H2, Accent button "Reservar ahora".
11. **Footer:** `primary-900` bg, text white 80% opacity, 4 columns (brand + short text + social icons · Espacios · Información · Contacto with NAP), bottom bar with copyright, legal links, `ES | EN`.

**Floating WhatsApp button (public, all pages):** 56px circle, bg `#25D366`, white icon 28px, bottom-right 24px (above mobile sticky bars by +72px), `--shadow-md`.

### 8.2 Space Listing (`/villas`, `/glamping`, `/camping`, `/espacios`)
- Page header (`sand-100` bg, 160px top padding to clear header): breadcrumb, H1, short intro.
- Sticky filter bar (white, 64px, below header): date range trigger, guests, type chips, "Más filtros" secondary button (opens drawer: price range slider, capacity, amenities checkboxes). Result count left "5 espacios disponibles".
- Space Card grid. Unavailable spaces for selected dates appear last with 60% opacity image and "No disponible en estas fechas" tag.

### 8.3 Space Detail (`/villas/[slug]`)
- **Gallery header:** 1 large (left, 2/3 width) + 4 small grid, radius `--radius-xl` on outer corners only, "Ver todas las fotos (24)" secondary `sm` button overlay bottom-right. Mobile: swipeable carousel 4:3 with counter "1/24".
- **Two-column layout `lg+`:** left 7 cols content, right 5 cols **sticky booking card** (top offset 96px).
- Left content order: type tag + H1 (Fraunces) + meta row · divider · description (`--text-lg`) · "Lo que ofrece este espacio" amenities grid 2 cols (icon 24px + 15px) with "Ver todas" · sleeping arrangements cards · availability calendar (2 months, read-only highlighting) · house rules (check-in/out times, policies) as icon list · location mini-map · reviews.
- **Booking card:** white, radius `--radius-lg`, `--shadow-md`, padding 24px: price "**$180** / noche" (24px/700), date range field, guest counter dropdown, price breakdown appears after dates selected (rows 14px: "$180 × 3 noches", "Tarifa de limpieza", "Impuestos (13% IVA)", divider, "Total" 16px/700), Accent `lg` full-width "Reservar", caption 13px `ink-500` centered "No se te cobrará todavía".
- **Mobile:** booking card replaced by **sticky bottom bar** (72px, white, top border, `--shadow-lg`): left price + selected dates (13px link to edit), right Accent `md` "Reservar".

### 8.4 Booking Flow (`/reservar`) — 4 steps, single page with stepper
- **Stepper:** horizontal on `md+` (circles 32px: completed `primary-700` with white `Check`; current border 2px `primary-700` + number `primary-700`; upcoming border `border-strong` + `ink-500`; connector line 2px), labels 14px/600. On `xs`: compact text "Paso 2 de 4 · Tus datos" + progress bar 4px.
- Steps: **1. Fechas y espacio** → **2. Tus datos** → **3. Pago** → **4. Confirmación**.
- Layout `lg+`: form left (7 cols), sticky **summary card** right (5 cols): thumbnail 1:1 80px radius 10px + space name + dates + guests + price breakdown + "Editar" link. On `xs` the summary is a collapsible accordion at top ("Ver resumen · $540").
- **Step 2 fields:** Nombre, Apellidos, Email, Teléfono (phone input), País (searchable select), Hora estimada de llegada (select), Solicitudes especiales (textarea, optional). Checkbox "Acepto los términos y la política de cancelación" (required, link opens modal).
- **Step 3 Payment:** radio cards for option "Pagar total" / "Pagar depósito del 50%" (if enabled); payment provider's embedded card fields styled to match inputs (§7.2: 48px, radius 10px, same borders/focus); trust row: `Lock` icon + "Pago seguro y encriptado" 13px `ink-500` + card brand logos (grayscale 24px height). Primary button text includes amount: "Pagar $540". During processing: button loading, form inputs disabled, `Esc`/navigation blocked, inline text "Procesando tu pago, no cierres esta ventana."
- **Payment error:** inline error alert at top of payment card with clear reason and "Intentar de nuevo"; entered personal data MUST be preserved.
- **Step 4 Confirmation:** centered card max 560px: 64px circle `success-50` with `CircleCheck` 32px `success-700`, H2 Fraunces "¡Tu reserva está confirmada!", reservation code in a dashed-border pill (16px/700 tabular, copy button), summary list, buttons "Agregar al calendario" (secondary) + "Ver cómo llegar" (primary), text "Enviamos los detalles a {email}".

### 8.5 Blog (`/blog`, `/blog/[slug]`)
- Index: featured post (horizontal card 16:9 image left, text right on `lg+`) + 3-col grid of posts; category filter chips.
- Post card: image 16:9, category tag, title Fraunces 22px, excerpt 2 lines 15px `ink-700`, meta 13px `ink-500` (date · "5 min de lectura").
- Article: max text width 68ch centered, H1 Fraunces 48px, cover image `--radius-xl`, body 18px/1.7, H2 Fraunces 30px with 48px top margin, blockquote with 3px left border `accent-500` and Fraunces italic 22px, inline images full container width with 14px `ink-500` caption, end CTA card to book.

### 8.6 Contact (`/contacto`)
Split: form left (name, email, phone, dates optional, message) / right: NAP card, WhatsApp button, hours, map.

### 8.7 Local SEO Structural Requirements (visual implications)
- Exactly one H1 per page. Semantic order H1 → H2 → H3, never skip levels for styling.
- NAP block identical in footer, contact page and location section.
- Breadcrumbs visible on all pages except Home (14px, `ink-500`).
- Every space page includes: location mention in H1 or intro, FAQ accordion section (accordion item: 64px min height, question 16px/600, `Plus`→`Minus` icon 20px, answer 15px `ink-700`, divider between items).
- Structured data (LodgingBusiness, Accommodation, BlogPosting, FAQPage, BreadcrumbList) is required in implementation; it has no visual component.

---

## 9. Admin Panel — Screen Specifications

### 9.1 Dashboard (`/admin`)
- Greeting row: "Buenos días, {nombre}" (24px/600) + date (14px `ink-500`).
- Row of 4 Stat Cards (`lg+` 4 cols, `md` 2 cols, `xs` horizontal scroll with snap): Ocupación del mes (%), Ingresos del mes ($), Reservas pendientes, Llegadas hoy.
- Two-column (`lg+` 8/4): left **"Hoy y mañana"** card: list of arrivals/departures (avatar, guest name, space, time, status badge, quick action "Check-in" secondary `sm`); right **Occupancy chart** card: bar chart by space (bars radius 6px top, colors by space type §2.4, gridlines `sand-200`, axis labels 12px `ink-500`).
- Below: "Reservas recientes" table (5 rows) + "Ver todas" link. Setup checklist card (§7.16) on top until complete.

### 9.2 Calendar (`/admin/calendario`) — primary daily tool
- Toolbar: today button (secondary `sm`), prev/next icon buttons, current range label 18px/600, segmented control (Mes / Semana / Lista), filter by space type (chips), primary "Nueva reserva".
- **Timeline view (default, `md+`):** rows = spaces (left sticky column 200px: thumbnail 32px + name 14px/600 + type tag), columns = days (min width 48px; header day name 12px `ink-500` + date 14px/600; weekends bg `sand-50`; today column header `primary-700` with `primary-50` column bg).
- Reservation block: height 36px inside 52px row, radius 8px, bg = status background, left 3px border = status dot color, text 13px/600 status text color (guest name, truncated with ellipsis), starts at mid-day of check-in and ends at mid-day of check-out. Hover: `--shadow-sm` + tooltip (guest, dates, guests, total, status). Click: opens reservation drawer.
- Drag on empty cells to create a new reservation (prefills dates and space). Blocked dates (maintenance): striped pattern `repeating-linear-gradient(45deg, #EAE2D5 0 6px, #F5F0E8 6px 12px)` with label "Bloqueado".
- **Mobile (`xs`):** List view forced: grouped by date (sticky date headers 13px/600 `ink-500`), each reservation as card.
- Legend row under toolbar: status dots + labels 12px.

### 9.3 Reservations (`/admin/reservas`)
- Table columns: Código · Cliente · Espacio · Llegada · Salida · Noches · Huéspedes · Total · Pago (Pagado/Depósito/Pendiente badge) · Estado · Acciones.
- Quick filter tabs above table: Todas · Pendientes · Confirmadas · En estadía · Finalizadas · Canceladas (with counts in 12px pill `sand-100`).
- Date range filter + space select + search (code, client name, email).
- **Reservation detail** (`/admin/reservas/[id]`, also as drawer): header with code, status badge, actions (Cambiar estado — dropdown, Editar, Enviar confirmación, Cancelar reserva — destructive). Layout 8/4: left cards: Estadía (space, dates, guests, special requests), Cliente (link to profile), Pagos (transaction list + "Registrar pago manual"), Notas internas (textarea + timestamped notes list); right: Activity timeline (vertical line 2px `border`, 10px dots, 13px events with time `ink-500`).
- **New/Edit reservation form:** sections in cards: Espacio y fechas (radio cards of spaces with availability indicator), Huéspedes, Cliente (combobox search existing client or "Crear nuevo cliente" inline), Precio (auto-calculated breakdown, editable discount field), Pago, Estado. Sticky footer bar (white, top border, 72px): "Cancelar" secondary + "Guardar reserva" primary. Unsaved changes trigger confirmation modal on leave.

### 9.4 Clients (`/admin/clientes`)
- Table: Cliente (avatar + name + email) · Teléfono · País · Reservas (count) · Última estadía · Total gastado · Acciones.
- **Client profile:** header card (avatar 56px, name 24px/600, contact items with copy icons, WhatsApp quick button, tags e.g. "Huésped frecuente"); stat mini-cards (Reservas, Noches, Total gastado); tabs: Historial de reservas (table) · Notas · Datos.

### 9.5 Villas & Spaces (`/admin/espacios`)
- Grid of admin space cards (image 4:3, name, type tag, capacity, base price, status toggle "Visible en el sitio", "Editar" ghost button). Primary "Nuevo espacio".
- **Edit space:** tabs: General (name, type select, capacity, rooms, bathrooms, description rich text) · Fotos · Comodidades (checkbox grid with icons) · Tarifas · Disponibilidad (block dates calendar) · SEO (meta title with 60-char counter, meta description 160-char counter, slug, Google preview card rendering title in `#1A0DAB`-style blue 18px, URL green 14px, description 14px — this preview is the only allowed use of non-system colors).
- **Photo manager:** drag-and-drop upload zone (dashed 2px `border-strong`, radius `--radius-lg`, 160px height, `ImagePlus` 32px, text "Arrastra fotos aquí o selecciona archivos", helper "JPG, PNG o WebP · máx. 10 MB · mínimo 2000px de ancho"); hover/drag-over: border `primary-700`, bg `primary-50`. Uploaded images in 4-col grid of 1:1 thumbnails, radius 10px, drag handle to reorder, first image labeled "Portada" badge, hover reveals icon buttons (set as cover, edit alt text, delete). Alt text field is required (warning badge if missing).

### 9.6 Rates & Seasons (`/admin/tarifas`)
Table of seasons (name, date range, color dot, price modifier) + per-space base price/min nights editable cells (inline edit: click cell → input 36px, `Enter` saves, `Esc` cancels, success flash `success-50` bg 600ms).

### 9.7 Site Content (`/admin/contenido`)
- Left list of editable pages/sections (Inicio, Sobre nosotros, Experiencias, Contacto, Políticas) 280px; right editor panel.
- Each section rendered as a form card with fields matching the public block (headline, subtitle, image picker, button text). Language tabs ES / EN at top of editor (both required; missing translation shows `warning` dot on tab).
- Top-right actions: "Vista previa" (secondary, opens public page in new tab) + "Publicar cambios" (primary). Draft status indicator: 8px dot + "Cambios sin publicar" 13px `warning-700`.

### 9.8 Blog Manager (`/admin/blog`)
- Table: Título (thumbnail + title) · Categoría · Estado (Borrador `completed` style / Publicado `paid` style / Programado `checked_in` style) · Fecha · Acciones.
- **Editor:** two-column `lg+` (8/4). Left: title input (borderless, Fraunces 36px, placeholder "Título del artículo"), block-based rich text editor (toolbar sticky 48px white with icon buttons 32px: H2, H3, bold, italic, link, list, quote, image), body preview in public article typography. Right sidebar cards: Publicación (status, date/time, Publish primary button), Portada (image picker 16:9), Categoría, Etiquetas, Extracto (textarea 160 chars), SEO (same as §9.5).
- Autosave indicator: 13px `ink-500` "Guardado hace 1 min" with `Check` icon.

### 9.9 Payments (`/admin/pagos`)
Stat cards (Cobrado este mes, Pendiente por cobrar, Reembolsos) + table: Fecha · Reserva (code link) · Cliente · Método (card brand icon + last 4) · Monto · Tipo (Total/Depósito/Reembolso) · Estado (Aprobado `paid` style, Pendiente `pending` style, Fallido `cancelled` style, Reembolsado `completed` style).

### 9.10 Settings (`/admin/configuracion`)
Vertical sub-nav (Negocio, Políticas de reserva, Pagos, Notificaciones, Usuarios y roles, Idiomas). Forms in cards with 24px gap; each card has title 16px/600 + description 14px `ink-500`, fields in 2-col grid on `lg+`, card footer with "Guardar" primary `sm` aligned right.

---

## 10. Forms — Global Rules

- Field vertical gap: 20px. Group gap (between sections/cards): 32px.
- Two-column field grids only on `lg+` and only for short related fields (Nombre/Apellidos, Llegada/Salida). Everything else single column, max form width 640px (public) / card width (admin).
- Primary action at the bottom right (desktop) / full width (mobile). Secondary left of primary.
- Inline validation on blur; on submit with errors: scroll to first error, focus it, show error summary alert at top listing errors as anchor links.
- NEVER disable the submit button to indicate invalid form; allow submit and show errors.
- Success: toast + redirect or inline success state.

---

## 11. Accessibility (WCAG 2.2 AA — mandatory)

- Contrast: text ≥ 4.5:1, large text and UI components ≥ 3:1. All tokens above comply when used as specified.
- Full keyboard navigation: logical tab order, visible focus ring (§5.4), skip link "Saltar al contenido" (appears on focus, top-left, primary button style).
- Icon-only buttons MUST have `aria-label`. Status badges MUST include text (never color alone).
- Calendar/date picker: arrow-key navigation, `aria-selected`, `aria-disabled`, announces "No disponible".
- Modals: `role="dialog"`, `aria-modal`, labelled by title, focus returns to trigger on close.
- Form fields linked to labels and errors via `for`/`aria-describedby`; errors use `aria-live="polite"`.
- `html lang` updates with language toggle.

---

## 12. Content & Tone (UI copy)

- Tone: warm, calm, concise, welcoming. Use "tú" in Spanish.
- Buttons use verbs: "Reservar", "Guardar reserva", "Ver detalles", never "OK"/"Enviar" alone when a specific verb exists.
- Dates format: `vie 14 nov 2026` (short) / `viernes 14 de noviembre de 2026` (long). Times 12h with `a. m./p. m.` in Spanish, `AM/PM` in English.
- Prices: `$1,250` (USD), `₡650.000` (CRC).
- Errors explain what happened and how to fix it: "La fecha de salida debe ser posterior a la de llegada."

---

## 13. Do / Don't

| ✅ DO | ❌ DON'T |
|---|---|
| Let photography lead; keep UI chrome minimal on public pages | Add decorative illustrations, emojis or stock icons over photos |
| Use `accent-500` only for the main booking CTA, prices emphasis and stars | Use accent for headings, backgrounds of large areas or secondary buttons |
| Keep generous whitespace (section spacing §4.3) | Crowd sections or reduce padding to fit more content |
| Use Fraunces for public headlines only | Use Fraunces in admin panel, tables, buttons, or inputs |
| Use status colors exactly as §2.3 everywhere | Invent new status colors or reuse them for unrelated meanings |
| Convert tables to cards on mobile | Use horizontally scrolling tables on mobile |
| Show prices including breakdown before payment | Reveal taxes or fees only at the payment step |
| Use bottom sheets on mobile for modals and selects | Use small centered modals on mobile |
| Keep one primary action per section | Place two primary/accent buttons side by side |
| Light mode only | Generate dark mode or glassmorphism-heavy UIs (blur allowed only on image badges and modal overlay) |

---

## 14. CSS Token Reference (copy as-is)

```css
:root {
  /* Brand */
  --color-primary-900:#0F2E2B; --color-primary-800:#16413D; --color-primary-700:#1D5550;
  --color-primary-600:#26695F; --color-primary-100:#DCEBE7; --color-primary-50:#EEF5F3;
  --color-accent-600:#B85A33; --color-accent-500:#C96B42; --color-accent-100:#F6E3D8;
  /* Neutrals */
  --color-sand-50:#FBF9F5; --color-sand-100:#F5F0E8; --color-sand-200:#EAE2D5; --color-white:#FFFFFF;
  --color-ink-900:#1C1A17; --color-ink-700:#46423C; --color-ink-500:#7A746B; --color-ink-300:#C9C2B6;
  --color-border:#E4DDD1; --color-border-strong:#CFC6B7;
  /* Semantic */
  --color-success-700:#2F6B3F; --color-success-50:#E8F3EA; --color-success-200:#BFDDC6;
  --color-warning-700:#8A5A0B; --color-warning-50:#FBF1DC; --color-warning-200:#EED29A;
  --color-error-700:#A8322A;   --color-error-50:#FBE9E7;   --color-error-200:#F0BDB7;
  --color-info-700:#2A5C8A;    --color-info-50:#E7F0F8;    --color-info-200:#BCD3E8;
  /* Type */
  --font-display:"Fraunces", Georgia, serif;
  --font-sans:"Manrope", system-ui, -apple-system, "Segoe UI", sans-serif;
  /* Radius */
  --radius-xs:6px; --radius-sm:10px; --radius-md:14px; --radius-lg:20px; --radius-xl:28px; --radius-full:9999px;
  /* Shadows */
  --shadow-xs:0 1px 2px rgba(28,26,23,.06);
  --shadow-sm:0 2px 8px rgba(28,26,23,.06),0 1px 2px rgba(28,26,23,.04);
  --shadow-md:0 8px 24px rgba(28,26,23,.08),0 2px 6px rgba(28,26,23,.04);
  --shadow-lg:0 24px 48px rgba(28,26,23,.12),0 4px 12px rgba(28,26,23,.06);
  /* Motion */
  --ease-standard:cubic-bezier(.2,0,0,1);
  --duration-fast:120ms; --duration-base:200ms; --duration-slow:320ms;
}
body { background: var(--color-sand-50); color: var(--color-ink-900); font-family: var(--font-sans); }
```

---
*End of specification. Any deviation from this document requires explicit approval from nCode Digital.*

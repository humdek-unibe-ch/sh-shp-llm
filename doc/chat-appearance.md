# Chat Bubble Appearance

> **v1.3.0 unification.** The legacy `llm_chat_colors` and short-lived
> `llm_chat_icons` JSON fields are dropped and replaced by a single
> `llm_chat_appearance` field on the `llmChat` style. The new field
> covers bubble colours, web (FontAwesome) icons, mobile (Ionic) icons
> and custom avatar images per side — everything in one place. There
> is no backward-compat shim: if you customised `llm_chat_colors` on a
> pre-v1.3.0 install you will need to re-paste the JSON into
> `llm_chat_appearance` (the colour keys are unchanged).

The unified field is **optional**. The default value is stored on the
`styles_fields` row, so the chat looks polished out of the box even
when an author never opens the field. Anything you omit in your custom
JSON falls back to the corresponding default value.

---

## Schema

```json
{
  "user": {
    "bg":         "#DCF8C6",
    "text":       "#1b5e20",
    "border":     "#a5d6a7",
    "icon":       "fa-user",
    "iconMobile": "person-circle",
    "iconImage":  ""
  },
  "ai": {
    "bg":         "#F3E5F5",
    "text":       "#4a148c",
    "border":     "#ce93d8",
    "icon":       "fa-robot",
    "iconMobile": "chatbubble-ellipses",
    "iconImage":  ""
  }
}
```

| Key | Type | Where it shows |
|---|---|---|
| `bg`         | colour | Bubble background. |
| `text`       | colour | Bubble text colour (and timestamp / token-count meta). |
| `border`     | colour | Thin accent rail on the bubble's outer edge (left for AI, right for user). Also drives the avatar disc tint. |
| `icon`       | string | **Web** avatar — FontAwesome class. Both `fa-user` shorthand and full `fas fa-user` syntax are accepted. |
| `iconMobile` | string | **Mobile** avatar — Ionic icon name (see https://ionic.io/ionicons). |
| `iconImage`  | string | Custom avatar URL/path. **Wins over `icon` and `iconMobile` on every platform when set.** |

### Resolution priority (per side, both web and mobile)

1. `iconImage` non-empty → render `<img>`. Image always wins, no font
   dependency.
2. Web → `<i className="fas {icon}">`. If the page does not load
   FontAwesome, the renderer drops to a coloured **letter avatar**
   (`U` / `AI`) so the bubble layout never breaks.
3. Mobile → `<ion-icon name="{iconMobile}">`. Ionic is part of the
   SelfHelp mobile app shell, so the icon is always available; if you
   ship a typo, Ionic renders an empty box — pair `iconMobile` with
   `iconImage` if you want a guaranteed fallback.

### FontAwesome detection (web)

The React layer probes for FontAwesome at first mount: it injects a
hidden `<i className="fas fa-check">` and reads the resolved
`font-family` via `getComputedStyle`. The result is cached on
`window.__llmFontAwesomeAvailable`, so the cost is paid exactly once
per page load no matter how many bubbles render. When the probe
reports the font is missing, the renderer skips the FA path entirely
and shows the letter avatar instead — which means **a chat with a
custom `iconImage` always looks correct, regardless of whether
FontAwesome is loaded**.

---

## Path rules for `iconImage`

The PHP side (`LlmChatModel::getChatAppearance()`) normalises every
non-empty `iconImage` before it reaches the frontend:

| Pattern | Behaviour |
|---|---|
| `https://example.com/coach.png`        | Used verbatim. |
| `http://...`, `data:...`, `blob:...`   | Used verbatim. |
| `/assets/coach.png`                    | `BASE_PATH` is prepended. Works on root-installs **and** sub-directory installs (`/selfhelp/assets/coach.png`, etc.). |
| `coach.png` (no leading slash)         | Used verbatim. Treat this as "I know what I'm doing" — only safe when the page renders from the same directory as the asset. |
| `""` / missing key                     | The chat falls through to the FontAwesome (web) or Ionic (mobile) icon for that side. |

---

## Interpolation (dynamic per-user avatars)

Because `StyleModel` runs `replace_calced_values()` on every field
before it returns, `{{placeholder}}` syntax inside the JSON is
resolved **before** the URL is normalised. This is the recommended
way to render a per-user avatar:

```json
{
  "user": {
    "iconImage": "{{user_avatar}}"
  },
  "ai": {
    "iconImage": "/assets/coach.png"
  }
}
```

- `{{user_avatar}}` resolves from a `dataConfig` source on the same
  `llmChat` section (a `users` table column, a memory field, or any
  other interpolatable variable).
- After substitution the value goes through the same path rules as a
  literal URL — leading `/` triggers `BASE_PATH` prepending, absolute
  URLs pass through.
- If the placeholder resolves to an empty string (user has no avatar
  configured), the FA / Ionic fallback kicks in automatically — no
  conditional logic in the JSON needed.

---

## Rendering details

The avatar slot is **36 × 36 pixels** with a small rounded radius.

- **Images** (`iconImage`) are rendered with `object-fit: cover` so any
  source aspect ratio (square, portrait, landscape) is cropped from the
  centre into the slot — the artwork always fills the disc cleanly. The
  slot inherits the avatar's own `border-radius` so the image is
  rounded automatically; no per-image CSS is needed.
- When an image is configured the colour-derived background and inset
  highlight are removed (transparent PNGs would otherwise show a
  coloured halo through their corners) and the decorative `box-shadow`
  is dropped.
- Images render with `loading="lazy"` and `pointer-events: none` so
  they never block click handlers on the bubble.

---

## Examples

### Default (nothing configured)

```json
{}
```

→ Stored default value: green bubbles for the user, purple for the
AI, FontAwesome icons (`fa-user` / `fa-robot`) on web, Ionic
`person-circle` / `chatbubble-ellipses` on mobile. This is what the
field default ships with — most authors never need to touch it.

### Brand colours, default icons

```json
{
  "user": { "bg": "#FFF3E0", "text": "#5D4037", "border": "#FFB74D" },
  "ai":   { "bg": "#E1F5FE", "text": "#0277BD", "border": "#4FC3F7" }
}
```

→ Colours are overridden, icons fall back to the defaults. The merge
floor in PHP guarantees `icon` and `iconMobile` are still present in
the React payload.

### AI-only custom image (web + mobile)

```json
{
  "ai": {
    "iconImage": "/assets/avatars/coach.png"
  }
}
```

→ The AI bubble shows the coach image on every platform; the user
bubble keeps the defaults (green palette + `fa-user` on web,
`person-circle` on mobile).

### Per-user dynamic avatar with AI fallback

```json
{
  "user": { "iconImage": "{{user_avatar}}" },
  "ai":   { "iconImage": "/assets/coach.png" }
}
```

→ Each user sees their own avatar; users with no avatar fall back to
the default `fa-user` (web) / `person-circle` (mobile) without any
extra wiring. The AI side keeps the coach image regardless.

### Different icons on web vs mobile (no custom image)

```json
{
  "ai": {
    "icon":       "fa-brain",
    "iconMobile": "bulb"
  }
}
```

→ Web shows a FontAwesome brain glyph; mobile shows the Ionic light
bulb. Useful when one platform has a glyph the other does not.

### Absolute URL (CDN-hosted)

```json
{
  "ai": { "iconImage": "https://cdn.example.com/avatars/coach.png" }
}
```

→ Used verbatim. `BASE_PATH` is **not** prepended for absolute URLs.

---

## Mobile parity

The unified field is read from the same database row by both the web
(`LlmChatView`) and mobile (`LlmChatView::output_content_mobile()`)
output paths. Mobile clients receive the entire merged tree as
`style.llm_chat_appearance.content` and apply exactly the same
resolution priority described above.

In the SelfHelp mobile app (sh-selfhelp_app v4.0.4+):

| `iconImage` | `iconMobile` | What renders |
|---|---|---|
| Set       | (any)             | `<img>` from the resolved URL. |
| Empty     | Set (e.g. `"person-circle"`) | `<ion-icon name="person-circle">`. |
| Empty     | Empty             | The Ionic default for the side (`person-circle` user, `chatbubble-ellipses` AI). |

The colour keys (`bg`, `text`, `border`) are applied as inline styles
on the message bubble in the mobile app exactly as they are on web,
keeping the visual identity consistent across platforms.

---

## Migration from pre-v1.3.0

Authors who customised `llm_chat_colors` on v1.0.0–v1.2.x need to
re-paste the JSON into `llm_chat_appearance`. The `bg` / `text` /
`border` keys are unchanged, so a copy-paste with these keys will keep
the existing look:

```json
{
  "user": { "bg": "...", "text": "...", "border": "..." },
  "ai":   { "bg": "...", "text": "...", "border": "..." }
}
```

Anything you don't include falls back to the v1.3.0 default — the
icons (`icon`, `iconMobile`) and the empty `iconImage` slots will be
filled in automatically by the PHP merge.

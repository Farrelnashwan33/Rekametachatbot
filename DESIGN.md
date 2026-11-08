# DESIGN.md: Rekametachat Visual & Interaction Direction

## 1. Identity & Brand Brief
- **Product Name**: Rekametachat
- **Official Tagline**: "Your Intelligent AI Companion"
- **R-37 Protocol Record**: Owner explicitly confirmed keeping the official brand tagline `"Your Intelligent AI Companion"` as an intentional brand override of rule `R-16` (word `"Intelligent"`), confirmed in session setup. All other UI copy avoids generic AI buzzwords.
- **Audience**: Indonesian and international professionals, students, researchers, job seekers, and software engineers who use one workspace for daily conversation, document analysis, CV preparation, academic study, and writing.
- **Personality**: Calm, articulate, trustworthy, studio-grade utility. Designed for multi-hour reading and writing comfort rather than marketing theatrics.

## 2. Design Read & Liveliness Dials
Reading this as: multi-purpose AI workspace web application for bilingual professionals and students, in a calm Midnight Navy dual-pane editorial-utility style adapted from the owner's tablet workspace reference, dial `ENERGY 2 / RHYTHM 2 / MOTION 1`.

- **ENERGY 2 (Balanced)**: Confident typographic hierarchy and deep Midnight Navy structural framing without neon spectacle or decorative clutter.
- **RHYTHM 2 (Consistent with intentional breaks)**: Dual-pane workspace shell (compact icon rail + contextual history/navigation drawer + focused reading/composer stage) paired with distinct purpose-built layouts for CV Intelligence (split editor + live paper preview) and Admin Analytics (structured metric strip + configuration tables).
- **MOTION 1 (Calm functional states)**: Tactile hover states, clear `:focus-visible` rings, subtle streaming cursor pulse, and smooth mobile drawer transitions (`prefers-reduced-motion` respected). No decorative bounce, float, or scroll-jacking.

## 3. Color System (Light & Dark Mode)
Core palette strictly capped at 3 brand colors plus neutral slate surfaces (`R-29`), meeting WCAG AA 4.5:1 normal text and 3:1 UI boundary contrast (`R-25`):

1. **Midnight Navy (Core Brand Structural Color)**
   - Dark Canvas Base: `#0A0F1E` | Elevated Panel: `#11182E` | Card/Input Surface: `#17203A`
   - Light Ink / Brand Header: `#0F172A` | Light Canvas Base: `#F8FAFC` | Light Panel Surface: `#FFFFFF`
2. **Electric Blue (Single Primary Action Accent)**
   - Light Mode Primary CTA: `#1D4ED8` (white text `#FFFFFF`, contrast `6.7:1`)
   - Dark Mode Primary CTA: `#2563EB` (white text `#FFFFFF`, contrast `5.1:1`) and interactive text link `#60A5FA` (on `#0A0F1E`, contrast `7.4:1`)
3. **Soft Violet (Secondary Context Accent)**
   - Light Mode Context Tag / Memory Indicator: `#5B21B6` on `#F3E8FF` (contrast `7.8:1`)
   - Dark Mode Context Tag / Memory Indicator: `#DDD6FE` on `#2E1065` (contrast `10.2:1`)

## 4. Typography & Identity Motif
- **Primary UI & Reading Typeface**: `Plus Jakarta Sans` with system geometric fallback (`-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`). Chosen for clean Indonesian and English legibility across dense paragraphs, forms, and sidebars.
- **Code & Technical Identifiers**: `JetBrains Mono` with monospace fallback (`ui-monospace, SFMono-Regular, Menlo, monospace`). Scoped strictly to fenced code blocks, token counts, and model slugs.
- **Identity Motif**: Dual-rail workspace framing (inspired by the owner's uploaded tablet layout) featuring an interlocking geometric compass-diamond emblem + `"Rekametachat"` wordmark, paired with subtle 1px structural hairlines and left-aligned editorial message blocks.

## 5. Architectural Decision Log (R-31 One-Line Reasons)
- **Why Midnight Navy + Electric Blue + Soft Violet**: Midnight Navy reduces glare in long reading sessions (matching the owner's reference), Electric Blue marks the single primary action per view, and Soft Violet isolates Personal Memory and specialized assistant states.
- **Why Dual-Pane Rail + Contextual Drawer Layout**: Separating top-level destinations (Chat, Explore AI, CV Studio, Documents, Settings, Admin) from the searchable Today/Yesterday conversation list keeps switching tasks fast without crowding the reading column.
- **Why Plus Jakarta Sans + JetBrains Mono**: Plus Jakarta Sans provides open apertures for bilingual Indonesian/English prose, while JetBrains Mono keeps code blocks and API model IDs scannable.
- **Why Tiered Border Radius (6px / 10px / 14px / 18px)**: Differentiating compact tags (`6px`), buttons/inputs (`10px`), content cards (`14px`), and the main chat composer/dialogs (`18px`) establishes clear component hierarchy instead of uniform pills.
- **Why Selective Elevation & Backdrop Blur**: Box shadows are reserved only for floating overlays/dialogs and the primary chat composer (`R-12`), and `backdrop-filter: blur(4px)` is used solely on modal `<dialog::backdrop>` (`R-10`) to separate active modals from inert workspace content.
- **Why Functional Custom Geometric SVGs**: Every icon directly depicts its action (speech bubble, compass grid, document page, ID card, graduation cap, sliders, paperclip, microphone waveform, send arrow) with zero decorative sparkles or robots (`R-04`).
- **Why Cards in Explore AI & Admin**: Explore AI uses asymmetric task-launcher rows/cards grouped by workflow depth so primary daily assistants stand above quick prompt templates (`R-14`), while Admin uses a compact 4-metric operational strip above real configuration tables.


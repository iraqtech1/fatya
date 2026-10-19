# Fatya green identity

The storefront and dashboard use the supplied green visual identity while retaining the existing layouts, navigation and data flows.

| Role | Color |
| --- | --- |
| Primary forest green | `#034037` |
| Emerald gradient | `#0A8B61` |
| Lime accent | `#7DBC30` |
| Bright lime highlight | `#C3EE4C` |
| Night background | `#061A14` |
| Deep green | `#023B33` |

Use lime sparingly for selection and emphasis. Use forest green text on pale surfaces and light text on dark surfaces. Error and destructive states keep their semantic colors; product and payment-provider artwork keeps its original identity.

Typography: locally served Cairo Bold, weight 700 across all screens and controls. The green logo artwork was extracted from the supplied identity PDF. Versioned green icon filenames refresh browser, iOS and installed PWA branding. The maskable icon keeps the F mark inside the central safe area.

`src/fatya-identity.css` defines the shared palette, typography and final theme treatments. Storefront-specific rules stay scoped with `data-fatya-store`; dashboard and storefront retain independent saved theme preferences.

Validation: `npm run check`, `npm test`, and `npm run build`. Build with `VITE_DATA_MODE=preview` for GitHub Pages; ordinary server builds retain the shared backend mode.

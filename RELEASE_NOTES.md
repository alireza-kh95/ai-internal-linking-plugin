Exclude CTA components from duplicate-destination audit findings.

Links inside the theme CTA component (`.cta-wrapper` and `.cta-section`) and CTA-labelled ACF fields now retain their component provenance. They remain part of the internal link graph and link counts, but do not cause duplicate-destination warnings when the same destination is linked contextually in the article body.


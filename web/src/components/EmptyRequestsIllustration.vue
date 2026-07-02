<template>
  <svg
    id="requests-empty-illustration"
    viewBox="0 0 260 200"
    fill="none"
    xmlns="http://www.w3.org/2000/svg"
    role="img"
    aria-label="Uklizený obývací pokoj s vysavačem"
  >
    <!-- Room: floor + back walls -->
    <polygon points="130,18 244,62 130,106 16,62" fill="var(--illustration-fill)" />
    <polygon points="16,62 130,106 130,186 16,142" fill="var(--illustration-shade)" />
    <polygon points="244,62 130,106 130,186 244,142" fill="var(--illustration-fill)" />
    <polygon points="130,18 244,62 130,106 16,62" stroke="var(--illustration-stroke)" stroke-width="2" stroke-linejoin="round" fill="none" />
    <polyline points="16,62 16,142 130,186 244,142 244,62" stroke="var(--illustration-stroke)" stroke-width="2" stroke-linejoin="round" fill="none" />
    <line x1="130" y1="106" x2="130" y2="186" stroke="var(--illustration-stroke)" stroke-width="2" />

    <!-- Picture frame on left wall -->
    <rect x="42" y="66" width="30" height="24" rx="3" transform="skewY(20)" fill="var(--color-white)" stroke="var(--illustration-stroke)" stroke-width="2" />
    <path d="M48 96 l8 6 6 -5 8 7" transform="skewY(20)" stroke="var(--illustration-stroke)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />

    <!-- Wall shelf with plant on right wall -->
    <line x1="168" y1="132" x2="216" y2="114" stroke="var(--illustration-stroke)" stroke-width="3" stroke-linecap="round" />
    <rect x="176" y="112" width="10" height="12" rx="2" fill="var(--color-white)" stroke="var(--illustration-stroke)" stroke-width="1.6" transform="rotate(-4 181 118)" />
    <path d="M198 116 c-2 -8 4 -12 6 -14 M198 116 c-6 -4 -6 -10 -4 -13" stroke="var(--illustration-stroke)" stroke-width="1.6" stroke-linecap="round" fill="none" transform="rotate(-4 198 112)" />

    <!-- Sofa -->
    <g>
      <rect x="140" y="128" width="76" height="34" rx="8" transform="skewY(-14) translate(0,58)" fill="var(--color-white)" stroke="var(--illustration-stroke)" stroke-width="2" />
      <rect x="144" y="106" width="68" height="26" rx="7" transform="skewY(-14) translate(0,58)" fill="var(--illustration-fill)" stroke="var(--illustration-stroke)" stroke-width="2" />
      <rect x="147" y="120" width="31" height="14" rx="5" transform="skewY(-14) translate(0,58)" fill="var(--color-white)" stroke="var(--illustration-stroke)" stroke-width="1.6" />
      <rect x="180" y="120" width="31" height="14" rx="5" transform="skewY(-14) translate(0,58)" fill="var(--color-white)" stroke="var(--illustration-stroke)" stroke-width="1.6" />
      <line x1="150" y1="190" x2="150" y2="196" stroke="var(--illustration-stroke)" stroke-width="2.4" stroke-linecap="round" />
      <line x1="208" y1="168" x2="208" y2="174" stroke="var(--illustration-stroke)" stroke-width="2.4" stroke-linecap="round" />
    </g>

    <!-- Potted plant, front right corner -->
    <g>
      <path d="M236 148 c-3 -14 6 -22 9 -25 M236 148 c-10 -8 -10 -18 -7 -23 M236 148 c3 -10 12 -12 16 -12" stroke="var(--illustration-stroke)" stroke-width="2" stroke-linecap="round" fill="none" />
      <path d="M228 148 h16 l-2.4 16 a3 3 0 0 1 -3 2.6 h-5.2 a3 3 0 0 1 -3 -2.6 z" fill="var(--illustration-shade)" stroke="var(--illustration-stroke)" stroke-width="2" stroke-linejoin="round" />
    </g>

    <!-- Vacuum cleaner, foreground -->
    <g>
      <path d="M76 88 c-10 0 -14 8 -12 16 l4 14" stroke="var(--illustration-stroke)" stroke-width="3" stroke-linecap="round" fill="none" />
      <rect x="70" y="80" width="14" height="8" rx="4" fill="var(--illustration-shade)" stroke="var(--illustration-stroke)" stroke-width="2" />
      <path d="M68 118 l10 44" stroke="var(--illustration-stroke)" stroke-width="3" stroke-linecap="round" />
      <ellipse cx="82" cy="168" rx="22" ry="11" fill="var(--color-white)" stroke="var(--illustration-stroke)" stroke-width="2" />
      <ellipse cx="82" cy="163" rx="22" ry="11" fill="var(--illustration-fill)" stroke="var(--illustration-stroke)" stroke-width="2" />
      <circle cx="82" cy="162" r="4" fill="var(--color-white)" stroke="var(--illustration-stroke)" stroke-width="1.6" />
      <path d="M60 165 c-8 2 -12 8 -10 13" stroke="var(--illustration-stroke)" stroke-width="2.4" stroke-linecap="round" fill="none" />
    </g>

    <!-- Sparkles: freshly cleaned -->
    <path d="M40 30 v10 M35 35 h10" stroke="var(--illustration-stroke)" stroke-width="2" stroke-linecap="round" />
    <path d="M218 22 v8 M214 26 h8" stroke="var(--illustration-stroke)" stroke-width="1.8" stroke-linecap="round" />
    <circle cx="196" cy="42" r="2.4" fill="var(--illustration-stroke)" />
    <circle cx="58" cy="14" r="2" fill="var(--illustration-stroke)" />
  </svg>
</template>

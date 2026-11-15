{{-- Робот-тренер «Визор»: элементы логотипа — зелёный ключ-антенна, серое кольцо-наушники, зубцы-шкала. --}}
<svg viewBox="0 0 220 300" width="110" height="150" role="img" aria-label="Робот-тренер">
    <ellipse cx="110" cy="290" rx="60" ry="7" fill="rgba(0,0,0,.12)"/>
    <g>
        <animateTransform attributeName="transform" type="translate" values="0 0; 0 -4; 0 0" dur="3s" repeatCount="indefinite"/>
        <rect x="104" y="8" width="8" height="52" fill="#22a845"/>
        <rect x="80" y="10" width="28" height="7" fill="#22a845"/><rect x="80" y="22" width="28" height="7" fill="#22a845"/><rect x="80" y="34" width="28" height="7" fill="#22a845"/>
        <rect x="50" y="56" width="120" height="96" rx="36" fill="#eef1ef" stroke="#151a1e" stroke-width="4"/>
        <path d="M44 120 a66 66 0 0 1 132 0" stroke="#5b6168" stroke-width="10" fill="none" stroke-dasharray="40 8"/>
        <rect x="34" y="96" width="20" height="40" rx="8" fill="#5b6168"/><rect x="166" y="96" width="20" height="40" rx="8" fill="#5b6168"/>
        <circle cx="110" cy="104" r="30" fill="#151a1e"/>
        @if ($mood === 'happy')
            <path d="M92 108 q18 -22 36 0" stroke="#5fd47d" stroke-width="7" fill="none" stroke-linecap="round"/>
        @elseif ($mood === 'worried')
            <circle cx="110" cy="104" r="18" fill="#3d1f0f" stroke="#e8590c" stroke-width="4"/>
            <circle cx="110" cy="104" r="7" fill="#ff9a5c"/>
        @else
            <circle cx="110" cy="104" r="20" fill="#0f3d1f" stroke="#22a845" stroke-width="4"/>
            <circle cx="110" cy="104" r="8" fill="#5fd47d"><animate attributeName="r" values="8;6;8" dur="4s" repeatCount="indefinite"/></circle>
            <circle cx="103" cy="97" r="3.5" fill="#fff"/>
        @endif
        <rect x="78" y="152" width="64" height="12" fill="#5b6168"/>
        <rect x="62" y="164" width="96" height="86" rx="22" fill="#eef1ef" stroke="#151a1e" stroke-width="4"/>
        <rect x="80" y="184" width="44" height="8" rx="3" fill="#22a845"/><rect x="80" y="198" width="32" height="8" rx="3" fill="#22a845"/><rect x="80" y="212" width="52" height="8" rx="3" fill="#22a845"/>
        <path d="M62 190 q-26 10 -30 40" stroke="#151a1e" stroke-width="10" fill="none" stroke-linecap="round"/>
        <path d="M158 190 q26 -10 32 -40" stroke="#151a1e" stroke-width="10" fill="none" stroke-linecap="round"/>
        <circle cx="32" cy="232" r="9" fill="#5b6168"/><circle cx="190" cy="148" r="9" fill="#5b6168"/>
        <rect x="80" y="250" width="18" height="34" rx="6" fill="#5b6168"/><rect x="122" y="250" width="18" height="34" rx="6" fill="#5b6168"/>
    </g>
</svg>

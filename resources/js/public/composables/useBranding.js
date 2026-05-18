import { ref, computed } from 'vue';

// Defaults: reproducen el rojo del menú actual para no romper el aspecto previo
// a que un tenant personalice. Cambiarlos afecta la primera carga de TODOS los
// menús públicos que no tengan personalización guardada.
const DEFAULTS = {
    primary_color: '#DC2626',
    accent_color: '#F59E0B',
    background_color: '#FFFFFF',
    text_color: 'auto',
    logo_url: null,
    default_product_image_url: null,
};

// Singleton: una sola instancia compartida por toda la SPA pública. Si el
// usuario navega de BranchSelector → MenuHome y ambos llaman a useBranding,
// comparten el mismo estado en lugar de pelearse por los CSS vars.
const branding = ref({ ...DEFAULTS });

function hexToRgb(hex) {
    const h = hex.replace('#', '');
    return {
        r: parseInt(h.slice(0, 2), 16),
        g: parseInt(h.slice(2, 4), 16),
        b: parseInt(h.slice(4, 6), 16),
    };
}

function rgbToHex({ r, g, b }) {
    const c = (n) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');
    return `#${c(r)}${c(g)}${c(b)}`.toUpperCase();
}

function mix(aHex, bHex, ratio) {
    const a = hexToRgb(aHex);
    const b = hexToRgb(bHex);
    return rgbToHex({
        r: a.r * (1 - ratio) + b.r * ratio,
        g: a.g * (1 - ratio) + b.g * ratio,
        b: a.b * (1 - ratio) + b.b * ratio,
    });
}

function relativeLuminance(hex) {
    const { r, g, b } = hexToRgb(hex);
    const ch = (c) => {
        const v = c / 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
}

function contrast(a, b) {
    const la = relativeLuminance(a);
    const lb = relativeLuminance(b);
    const [hi, lo] = la > lb ? [la, lb] : [lb, la];
    return (hi + 0.05) / (lo + 0.05);
}

function autoText(bg) {
    return contrast('#FFFFFF', bg) >= contrast('#000000', bg) ? '#FFFFFF' : '#000000';
}

const HEX_RX = /^#[0-9a-fA-F]{6}$/;
const isHex = (v) => typeof v === 'string' && HEX_RX.test(v);

function injectCssVars(b) {
    if (typeof document === 'undefined') return;

    const primary = isHex(b.primary_color) ? b.primary_color : DEFAULTS.primary_color;
    const accent = isHex(b.accent_color) ? b.accent_color : DEFAULTS.accent_color;
    const background = isHex(b.background_color) ? b.background_color : DEFAULTS.background_color;
    const text = b.text_color === 'auto'
        ? autoText(background)
        : (isHex(b.text_color) ? b.text_color : autoText(background));

    const primaryStrong = mix(primary, '#000000', 0.15);
    const primarySoft = mix(primary, '#FFFFFF', 0.88);
    const primaryRing = mix(primary, '#FFFFFF', 0.55);
    const onPrimary = autoText(primary);
    const textSubtle = text === '#FFFFFF'
        ? 'rgba(255,255,255,0.65)'
        : mix(text, background, 0.45);
    const cardBg = text === '#FFFFFF'
        ? 'rgba(255,255,255,0.06)'
        : mix(background, '#000000', 0.04);
    const borderColor = text === '#FFFFFF'
        ? 'rgba(255,255,255,0.14)'
        : mix(background, '#000000', 0.08);

    const root = document.documentElement;
    root.style.setProperty('--brand-primary', primary);
    root.style.setProperty('--brand-primary-strong', primaryStrong);
    root.style.setProperty('--brand-primary-soft', primarySoft);
    root.style.setProperty('--brand-primary-ring', primaryRing);
    root.style.setProperty('--brand-on-primary', onPrimary);
    root.style.setProperty('--brand-accent', accent);
    root.style.setProperty('--brand-background', background);
    root.style.setProperty('--brand-text', text);
    root.style.setProperty('--brand-text-subtle', textSubtle);
    root.style.setProperty('--brand-card', cardBg);
    root.style.setProperty('--brand-border', borderColor);
}

// Inyectamos defaults inmediatamente para que la primera pintura (skeleton)
// ya use variables resueltas en vez de quedarse sin valor.
injectCssVars(DEFAULTS);

export function useBranding() {
    const apply = (payload) => {
        if (!payload || typeof payload !== 'object') return;
        const next = { ...DEFAULTS, ...branding.value, ...payload };
        // Normaliza nulls
        next.logo_url = payload.logo_url ?? null;
        next.default_product_image_url = payload.default_product_image_url ?? null;
        branding.value = next;
        injectCssVars(next);
    };

    const reset = () => {
        branding.value = { ...DEFAULTS };
        injectCssVars(DEFAULTS);
    };

    const logoUrl = computed(() => branding.value.logo_url);
    const defaultProductImageUrl = computed(() => branding.value.default_product_image_url);

    const productImageUrl = (product) => product?.image_url ?? defaultProductImageUrl.value ?? null;

    return {
        branding,
        logoUrl,
        defaultProductImageUrl,
        productImageUrl,
        apply,
        reset,
    };
}

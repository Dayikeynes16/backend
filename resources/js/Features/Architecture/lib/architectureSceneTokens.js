export const APPLICATION_SCENE_MIN_WIDTH_PX = 960;
export const ECOSYSTEM_SCENE_MIN_WIDTH_PX = 1056;

export const ECOSYSTEM_COLORS = Object.freeze({
    terrain: '#07111f',
    axisText: '#cbd5e1',
});

export const ECOSYSTEM_TYPOGRAPHY_UNITS = Object.freeze({
    applicationName: 14,
    applicationId: 14,
    axis: 14,
    empty: 16,
});

export const ROOM_TYPOGRAPHY_UNITS = Object.freeze({
    name: 17,
    status: 15,
    offline: 15,
});

export const SCENE_LABEL_TYPOGRAPHY_UNITS = Object.freeze({
    floor: 15,
    gateway: 15,
});

export const GATEWAY_LAYOUT = Object.freeze({
    nodeSize: 16,
    topOffset: 48,
    bottomInset: 34,
    label: Object.freeze({
        maxCharacters: 18,
        lineHeight: 16,
        topBaselineOffset: 30,
        bottomBaselineOffset: -38,
        estimatedGlyphWidthRatio: 0.65,
        descentRatio: 0.25,
    }),
});

export const ROOM_LABEL_LAYOUT = Object.freeze({
    horizontalInset: 10,
    offlineNameReserve: 32,
    estimatedGlyphWidthRatio: 0.58,
    glyphDescentRatio: 0.25,
    nameClipTop: 3,
    nameClipHeight: 42,
    nameFirstBaseline: 21,
    nameLineHeight: 18,
    statusBottomInset: 5,
    statusLineHeight: 14,
    statusMaxLines: 2,
    statusClipTop: 47,
    statusClipHeight: 34,
    offlineBadge: Object.freeze({
        width: 24,
        height: 44,
        rightInset: 5,
        topInset: 4,
        textCenterX: 12,
        textCenterY: 22,
        textInset: 4,
        textLength: 36,
    }),
});

function maximumRoomLabelCharacters(width, fontSize) {
    return Math.max(
        4,
        Math.floor(width / (fontSize * ROOM_LABEL_LAYOUT.estimatedGlyphWidthRatio)),
    );
}

function truncateRoomLabel(label, maximumCharacters) {
    if (label.length <= maximumCharacters) return label;

    return `${label.slice(0, maximumCharacters - 1).trim()}…`;
}

export function wrapRoomNameLabel(label, width, fontSize) {
    const maximumCharacters = maximumRoomLabelCharacters(width, fontSize);
    const words = String(label ?? '').trim().split(/\s+/).filter(Boolean);
    const lines = [];

    for (const word of words) {
        const current = lines.at(-1);
        const candidate = current ? `${current} ${word}` : word;

        if (!current || (candidate.length > maximumCharacters && lines.length < 2)) {
            lines.push(word);
        } else {
            lines[lines.length - 1] = candidate;
        }
    }

    if (lines.length > 2) {
        lines[1] = lines.slice(1).join(' ');
        lines.length = 2;
    }

    return (lines.length ? lines : [''])
        .map((line) => truncateRoomLabel(line, maximumCharacters));
}

export function wrapRoomStatusLabel(label, width, fontSize) {
    const maximumCharacters = maximumRoomLabelCharacters(width, fontSize);
    const words = String(label ?? '').trim().split(/\s+/).filter(Boolean);
    const lines = [];

    for (const word of words) {
        const current = lines.at(-1);
        const candidate = current ? `${current} ${word}` : word;

        if (!current || candidate.length <= maximumCharacters) {
            if (lines.length === 0) lines.push(word);
            else lines[lines.length - 1] = candidate;
        } else {
            lines.push(word);
        }
    }

    return lines.length ? lines : [''];
}

export function getRoomLabelVerticalGeometry({
    roomHeight,
    nameLineCount,
    statusLineCount,
}) {
    const safeNameLineCount = Math.max(1, Math.min(2, Math.trunc(nameLineCount)));
    const safeStatusLineCount = Math.max(
        1,
        Math.min(ROOM_LABEL_LAYOUT.statusMaxLines, Math.trunc(statusLineCount)),
    );
    const nameBaselines = Array.from({ length: safeNameLineCount }, (_, index) => (
        ROOM_LABEL_LAYOUT.nameFirstBaseline + (index * ROOM_LABEL_LAYOUT.nameLineHeight)
    ));
    const statusLastBaseline = roomHeight - ROOM_LABEL_LAYOUT.statusBottomInset;
    const statusFirstBaseline = statusLastBaseline
        - ((safeStatusLineCount - 1) * ROOM_LABEL_LAYOUT.statusLineHeight);
    const statusBaselines = Array.from({ length: safeStatusLineCount }, (_, index) => (
        statusFirstBaseline + (index * ROOM_LABEL_LAYOUT.statusLineHeight)
    ));

    return {
        nameBaselines,
        statusBaselines,
        nameBox: {
            top: nameBaselines[0] - ROOM_TYPOGRAPHY_UNITS.name,
            bottom: nameBaselines.at(-1)
                + (ROOM_TYPOGRAPHY_UNITS.name * ROOM_LABEL_LAYOUT.glyphDescentRatio),
        },
        statusBox: {
            top: statusBaselines[0] - ROOM_TYPOGRAPHY_UNITS.status,
            bottom: statusBaselines.at(-1)
                + (ROOM_TYPOGRAPHY_UNITS.status * ROOM_LABEL_LAYOUT.glyphDescentRatio),
        },
    };
}

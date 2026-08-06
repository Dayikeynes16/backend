export const APPLICATION_SCENE_MIN_WIDTH_PX = 960;

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
    nameFirstBaseline: 21,
    nameLineHeight: 18,
    statusBottomInset: 6,
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

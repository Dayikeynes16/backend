<script setup>
defineProps({
    patternId: {
        type: String,
        required: true,
    },
    token: {
        type: Object,
        required: true,
        validator: (value) => (
            typeof value.fill === 'string'
            && typeof value.stroke === 'string'
            && typeof value.pattern === 'string'
        ),
    },
});
</script>

<template>
    <pattern :id="patternId" width="12" height="12" patternUnits="userSpaceOnUse">
        <rect width="12" height="12" :fill="token.fill" />
        <path v-if="token.pattern === 'diagonal'" d="M -3 12 L 12 -3 M 3 15 L 15 3" :stroke="token.stroke" stroke-width="2" opacity="0.42" />
        <path v-else-if="token.pattern === 'horizontal'" d="M 0 3 H 12 M 0 9 H 12" :stroke="token.stroke" stroke-width="1.5" opacity="0.46" />
        <path v-else-if="token.pattern === 'vertical'" d="M 3 0 V 12 M 9 0 V 12" :stroke="token.stroke" stroke-width="1.5" opacity="0.42" />
        <path v-else-if="token.pattern === 'cross'" d="M 0 0 L 12 12 M 12 0 L 0 12" :stroke="token.stroke" stroke-width="1.4" opacity="0.34" />
        <circle v-else-if="token.pattern === 'dots'" cx="3" cy="3" r="1.5" :fill="token.stroke" opacity="0.58" />
        <path v-else-if="token.pattern === 'dense'" d="M 0 2 H 12 M 0 6 H 12 M 0 10 H 12" :stroke="token.stroke" stroke-width="1" opacity="0.44" />
        <path v-else-if="token.pattern === 'wide'" d="M -6 12 L 12 -6 M 0 18 L 18 0" :stroke="token.stroke" stroke-width="3" opacity="0.3" />
    </pattern>
</template>

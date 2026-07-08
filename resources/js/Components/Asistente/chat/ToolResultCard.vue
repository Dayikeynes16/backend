<script setup>
import SalesSummaryCard from '../SalesSummaryCard.vue';
import ExpenseSummaryCard from '../ExpenseSummaryCard.vue';
import TopProductsCard from '../TopProductsCard.vue';
import ShiftStatusCard from '../ShiftStatusCard.vue';
import CustomerStatsCard from '../CustomerStatsCard.vue';
import CustomerDetailCard from '../CustomerDetailCard.vue';
import ProductDetailsCard from '../ProductDetailsCard.vue';
import ProductSalesCard from '../ProductSalesCard.vue';
import PurchaseSummaryCard from '../PurchaseSummaryCard.vue';
import AccountsPayableCard from '../AccountsPayableCard.vue';
import ExpenseCategoriesCard from '../ExpenseCategoriesCard.vue';
import AssistantDraftCard from '../AssistantDraftCard.vue';

defineProps({
    // { id, kind, data, tool_name } — item de renderItems de useAssistantChat.
    card: { type: Object, required: true },
    routes: { type: Object, required: true },
});

const cardComponents = {
    sales_summary: SalesSummaryCard,
    expense_summary: ExpenseSummaryCard,
    top_products: TopProductsCard,
    shift_status: ShiftStatusCard,
    customer_debt: CustomerStatsCard,
    customer_top_buyers: CustomerStatsCard,
    customer_detail: CustomerDetailCard,
    product_details: ProductDetailsCard,
    product_sales: ProductSalesCard,
    purchase_summary: PurchaseSummaryCard,
    accounts_payable: AccountsPayableCard,
    expense_categories: ExpenseCategoriesCard,
};
</script>

<template>
    <AssistantDraftCard v-if="card.kind === 'assistant_draft'" :data="card.data" :routes="routes" />
    <component v-else-if="cardComponents[card.kind]" :is="cardComponents[card.kind]" :data="card.data" />
    <div v-else class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-gray-600">
        <div class="mb-1 font-semibold">Resultado de {{ card.tool_name }}</div>
        <pre class="overflow-x-auto whitespace-pre-wrap font-mono">{{ JSON.stringify(card.data, null, 2) }}</pre>
    </div>
</template>

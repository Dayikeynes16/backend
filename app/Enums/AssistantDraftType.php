<?php

namespace App\Enums;

/**
 * Tipo de operación que representa un borrador del asistente. Cada tipo tiene su
 * propia tool de preparación, su Form Request de confirmación y su confirmador
 * de dominio. Se irá ampliando por fases (Purchase, Provider, PayablePayment…).
 */
enum AssistantDraftType: string
{
    case Expense = 'expense';
    case Provider = 'provider';
    case Purchase = 'purchase';
    case PayablePayment = 'payable_payment';
    case CustomerGlobalPayment = 'customer_global_payment';
    case ExpenseCategory = 'expense_category';
    case ExpenseCategoryEdit = 'expense_category_edit';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Gasto',
            self::Provider => 'Proveedor',
            self::Purchase => 'Compra',
            self::PayablePayment => 'Abono a proveedor',
            self::CustomerGlobalPayment => 'Cobro a cliente',
            self::ExpenseCategory => 'Categoría de gasto',
            self::ExpenseCategoryEdit => 'Editar categoría de gasto',
        };
    }
}

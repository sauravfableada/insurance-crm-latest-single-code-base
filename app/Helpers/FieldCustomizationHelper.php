<?php

use App\Services\FieldCustomizationService;

if (!function_exists('field_customization_service')) {
    function field_customization_service(): FieldCustomizationService
    {
        return app(FieldCustomizationService::class);
    }
}

if (!function_exists('field_label')) {
    /**
     * Get the customized or default label for a module field.
     */
    function field_label(string $module, string $field, ?string $fallback = null): string
    {
        return field_customization_service()->getFieldLabel($module, $field, $fallback);
    }
}

if (!function_exists('is_field_visible')) {
    /**
     * Determine if a field is visible for the given module.
     */
    function is_field_visible(string $module, string $field): bool
    {
        return field_customization_service()->isFieldVisible($module, $field);
    }
}

if (!function_exists('is_field_required')) {
    /**
     * Determine if a field is marked required for the given module.
     */
    function is_field_required(string $module, string $field): bool
    {
        return field_customization_service()->isFieldRequired($module, $field);
    }
}

if (!function_exists('field_validation_message')) {
    /**
     * Get custom validation message for a field if configured.
     */
    function field_validation_message(string $module, string $field): ?string
    {
        return field_customization_service()->getValidationMessage($module, $field);
    }
}

if (!function_exists('field_config')) {
    /**
     * Get complete field customization configuration.
     */
    function field_config(string $module, string $field): array
    {
        return field_customization_service()->getFieldConfig($module, $field);
    }
}

if (!function_exists('module_fields')) {
    /**
     * Get all configured fields for a module.
     */
    function module_fields(string $module)
    {
        return field_customization_service()->getModuleFields($module);
    }
}


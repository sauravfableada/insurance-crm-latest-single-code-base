<?php

namespace App\Http\Controllers;

use App\Services\FieldCustomizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldCustomizationController extends Controller
{
    protected FieldCustomizationService $service;

    public function __construct(FieldCustomizationService $service)
    {
        $this->service = $service;

        $this->middleware(function ($request, $next) {
            if (!auth()->user()?->isAdmin()) {
                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Unauthorized access to field customization.',
                    ], 403);
                }
                abort(403, 'Unauthorized access to field customization.');
            }
            return $next($request);
        });
    }

    /**
     * Get module fields for settings UI via AJAX.
     */
    public function getFields(string $module): JsonResponse
    {
        $modules = $this->service->getModules();
        if (!isset($modules[$module])) {
            return response()->json([
                'success' => false,
                'message' => "The '{$module}' module is not included in your current subscription plan.",
            ], 403);
        }

        $fields = $this->service->getModuleFields($module);

        return response()->json([
            'success' => true,
            'module' => $modules[$module],
            'fields' => $fields,
        ]);
    }

    /**
     * Save field customizations for a module.
     */
    public function update(Request $request, string $module): JsonResponse
    {
        $modules = $this->service->getModules();
        if (!isset($modules[$module])) {
            return response()->json([
                'success' => false,
                'message' => "The '{$module}' module is not included in your current subscription plan.",
            ], 403);
        }

        $request->validate([
            'fields' => ['required', 'array'],
            'fields.*.custom_label' => ['nullable', 'string', 'max:255'],
            'fields.*.is_visible' => ['nullable'],
            'fields.*.is_required' => ['nullable'],
            'fields.*.validation_message' => ['nullable', 'string', 'max:255'],
            'fields.*.display_order' => ['nullable', 'integer'],
        ]);

        $fieldsData = [];
        foreach ($request->input('fields') as $key => $data) {
            $fieldName = (!empty($data['field_name'])) ? $data['field_name'] : (string) $key;
            $fieldsData[$fieldName] = [
                'custom_label' => $data['custom_label'] ?? null,
                'is_visible' => filter_var($data['is_visible'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'is_required' => filter_var($data['is_required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'validation_message' => $data['validation_message'] ?? null,
                'display_order' => isset($data['display_order']) ? (int) $data['display_order'] : 0,
            ];
        }

        $this->service->saveModuleFields($module, $fieldsData);

        return response()->json([
            'success' => true,
            'message' => "Field customization for {$modules[$module]['name']} saved successfully.",
            'fields' => $this->service->getModuleFields($module),
        ]);
    }

    /**
     * Reset field customizations for a module back to default.
     */
    public function reset(Request $request, string $module): JsonResponse
    {
        $modules = $this->service->getModules();
        if (!isset($modules[$module])) {
            return response()->json([
                'success' => false,
                'message' => "The '{$module}' module is not included in your current subscription plan.",
            ], 403);
        }

        $this->service->resetModuleFields($module);

        return response()->json([
            'success' => true,
            'message' => "Field customization for {$modules[$module]['name']} has been reset to defaults.",
            'fields' => $this->service->getModuleFields($module),
        ]);
    }
}


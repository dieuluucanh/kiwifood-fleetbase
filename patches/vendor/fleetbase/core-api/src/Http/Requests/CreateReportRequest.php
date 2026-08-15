<?php

namespace Fleetbase\Http\Requests;

/**
 * Request for creating a new report.
 */
class CreateReportRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                           => 'required|string|max:255',
            'type'                            => 'required|string|max:100',
            'query_config'                    => 'nullable|array',
            'query_config.table'              => 'required_with:query_config|array',
            'query_config.table.name'         => 'required_with:query_config.table|string',
            'query_config.columns'            => 'nullable|array',
            'query_config.columns.*.name'     => 'required|string',
            'query_config.computed_columns'   => 'nullable|array',
            'query_config.joins'              => 'nullable|array',
            'query_config.where'              => 'nullable|array',
            'query_config.groupBy'            => 'nullable|array',
            'query_config.having'             => 'nullable|array',
            'query_config.orderBy'            => 'nullable|array',
            'query_config.limit'              => 'nullable|integer|min:1|max:10000',
            'period_start'                    => 'nullable|date',
            'period_end'                      => 'nullable|date|after_or_equal:period_start',
            'is_scheduled'                    => 'nullable|boolean',
            'schedule_config'                 => 'nullable|array',
            'export_formats'                  => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'                        => 'Report title is required',
            'type.required'                         => 'Report type is required',
            'query_config.table.name.required_with' => 'Primary table must be specified',
            'query_config.columns.*.name.required'  => 'Column name is required',
            'query_config.limit.max'                => 'Query limit cannot exceed 10,000 rows',
        ];
    }
}

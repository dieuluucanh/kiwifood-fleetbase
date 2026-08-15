<?php

namespace Fleetbase\Http\Requests;

/**
 * Request for updating an existing report.
 */
class UpdateReportRequest extends FleetbaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                           => 'sometimes|required|string|max:255',
            'type'                            => 'sometimes|required|string|max:100',
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
            'status'                          => 'sometimes|string|in:pending,generating,complete,failed',
        ];
    }
}

<?php

/*
 * Integration source and synchronization journal strings.
 *
 * Provisional wording. Final Tajik, Russian and English terminology is
 * approved by Hydromet (docs/08-hydromet-input-checklist.md, section 5).
 */

return [
    'navigation_group' => 'Integrations',
    'source' => 'Integration source',
    'sources' => 'Integration sources',
    'run' => 'Synchronization run',
    'runs' => 'Synchronization runs',
    'not_supplied' => 'Not supplied',
    'no_rejections' => 'No rejected rows',
    'stale_after_help' => 'How long this source may go without a successful import before the public status endpoint calls it stale. Not the polling interval. Empty means Hydromet has not approved a rule, and the source is published as "unknown" rather than as healthy.',
    'read_only_notice' => 'This operational record is read-only and is maintained by the synchronization service.',
    'yes' => 'Yes',
    'no' => 'No',
    'seconds' => 's',

    'statuses' => [
        'running' => 'Running',
        'succeeded' => 'Succeeded',
        'partial' => 'Partial',
        'failed' => 'Failed',
    ],

    'kinds' => [
        'station_registry' => 'Station registry',
        'measurements' => 'Measurements',
        'alerts' => 'Warnings',
    ],

    'sections' => [
        'identity' => 'Source identity',
        'polling' => 'Polling policy',
        'mappings' => 'Canonical mappings',
        'provenance' => 'Provenance',
        'summary' => 'Run summary',
        'counters' => 'Import counters',
        'cursor' => 'Cursor and response',
        'failure' => 'Failure summary',
        'rejected_rows' => 'Rejected rows',
    ],

    'fields' => [
        'id' => 'ID',
        'code' => 'Code',
        'type' => 'Type',
        'producer' => 'Producer',
        'enabled' => 'Enabled',
        'timezone' => 'Timezone',
        'base_url' => 'Base URL',
        'authentication_type' => 'Authentication type',
        'polling_interval_seconds' => 'Polling interval, seconds',
        'stale_after_seconds' => 'Stale after, seconds',
        'timeout_seconds' => 'Timeout, seconds',
        'cursor_strategy' => 'Cursor strategy',
        'overlap_seconds' => 'Overlap, seconds',
        'runs_count' => 'Runs',
        'parameter_mapping' => 'Parameter mapping',
        'unit_mapping' => 'Unit mapping',
        'provider_value' => 'Provider value',
        'canonical_value' => 'Canonical value',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
        'source' => 'Source',
        'kind' => 'Kind',
        'status' => 'Status',
        'started_at' => 'Started at',
        'finished_at' => 'Finished at',
        'duration' => 'Duration',
        'received_count' => 'Received',
        'accepted_count' => 'Accepted',
        'updated_count' => 'Updated',
        'rejected_count' => 'Rejected',
        'cursor_from' => 'Cursor from',
        'cursor_to' => 'Cursor to',
        'error_code' => 'Error code',
        'sanitized_error' => 'Safe error detail',
        'response_checksum' => 'Response checksum',
        'reference' => 'Row reference',
        'reason_code' => 'Reason code',
        'safe_detail' => 'Safe detail',
    ],
];
